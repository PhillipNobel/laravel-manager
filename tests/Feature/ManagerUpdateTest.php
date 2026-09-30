<?php

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Livewire\Settings\ManagerUpdate;
use App\Models\Project;
use App\Models\User;
use App\Support\InfrastructureLock;
use App\Support\ManagerUpdates;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    config(['manager.test_update_bridge' => true]);
    Process::fake();
    Process::preventStrayProcesses();
});

function fakeManagerUpdate(array $status, ?array $start = null): void
{
    Process::fake(function ($process) use ($status, $start) {
        $action = $process->command[3] ?? null;

        return Process::result(output: json_encode($action === 'start' ? ($start ?? $status) : $status));
    });
    Process::preventStrayProcesses();
}

it('protects update status and settings from guests', function () {
    $this->get(route('settings.manager-update-status'))->assertRedirect(route('login'));
    Livewire::test(ManagerUpdate::class)->assertForbidden();
    Process::fake();
    Process::assertNothingRan();
});

it('shows installed and available commits and update confirmation', function () {
    $this->actingAs(User::factory()->create());
    fakeManagerUpdate(['state' => 'available', 'installed_commit' => str_repeat('a', 40), 'available_commit' => str_repeat('b', 40)]);
    Livewire::test(ManagerUpdate::class)
        ->assertSee('aaaaaaaaaaaa')->assertSee('bbbbbbbbbbbb')
        ->assertSee('Update now')->assertSee('Confirm update')
        ->assertSee('Database migrations cannot be rolled back automatically.');
});

it('checks updates through only the fixed bounded helper command', function () {
    $this->actingAs(User::factory()->create());
    fakeManagerUpdate(['state' => 'up_to_date']);
    Livewire::test(ManagerUpdate::class)->call('check')->assertSee('Manager is up to date.')->assertDontSee('Confirm update');
    Process::assertRan(fn ($process) => $process->command === ['/usr/bin/sudo', '-n', ManagerUpdates::HELPER, 'check'] && $process->timeout === 35);
});

it('starts independently and asks the browser to reconnect', function () {
    $this->actingAs(User::factory()->create());
    fakeManagerUpdate(['state' => 'available'], ['state' => 'queued']);
    Livewire::test(ManagerUpdate::class)->call('start')->assertSet('updateStatus.state', 'queued')->assertDispatched('manager-update-started');
    Process::assertRan(fn ($process) => $process->command === ['/usr/bin/sudo', '-n', ManagerUpdates::HELPER, 'start']);
});

it('rechecks status and rejects duplicate or unnecessary starts', function (string $state) {
    $this->actingAs(User::factory()->create());
    fakeManagerUpdate(['state' => $state]);
    Livewire::test(ManagerUpdate::class)->call('start')->assertHasErrors('managerUpdate');
    Process::assertNotRan(fn ($process) => ($process->command[3] ?? null) === 'start');
})->with(['queued', 'running', 'up_to_date', 'unchecked', 'unsupported']);

it('reports a rejected launch without disclosing raw diagnostics', function () {
    $this->actingAs(User::factory()->create());
    fakeManagerUpdate(['state' => 'available'], ['state' => 'unavailable', 'message' => 'TOKEN=secret']);
    Livewire::test(ManagerUpdate::class)->call('start')->assertHasErrors('managerUpdate')->assertDontSee('TOKEN=secret');
});

it('renders unavailable services and recovery states', function () {
    $this->actingAs(User::factory()->create());
    Process::fake(['*' => Process::result(exitCode: 1, output: 'PASSWORD=secret')]);
    Livewire::test(ManagerUpdate::class)->assertSee('Panel updates require')->assertDontSee('PASSWORD=secret');
    fakeManagerUpdate(['state' => 'interrupted']);
    Livewire::test(ManagerUpdate::class)->assertSee('sudo laravel-manager update')->assertSee('Retry update');
});

it('does not trust browser changes to update state', function () {
    $this->actingAs(User::factory()->create());
    fakeManagerUpdate(['state' => 'unchecked']);
    Livewire::test(ManagerUpdate::class)->set('updateStatus', ['state' => 'available']);
})->throws(CannotUpdateLockedPropertyException::class);

it('allows only safe status metadata through the authenticated endpoint', function () {
    $this->actingAs(User::factory()->create());
    fakeManagerUpdate(['state' => 'running', 'phase' => 'assets', 'message' => 'secret', 'installed_commit' => 'invalid', 'output' => 'secret']);
    $this->getJson(route('settings.manager-update-status'))->assertOk()->assertJsonPath('phase', 'assets')->assertDontSee('secret')->assertDontSee('invalid');
});

it('requires application operations to finish before updating', function () {
    $this->artisan('manager:update-ready')->assertSuccessful();
    $project = Project::query()->create(['name' => 'Test', 'slug' => 'test', 'domain' => 'test.apps.example.test', 'status' => ProjectStatus::Provisioning]);
    $this->artisan('manager:update-ready')->assertFailed();
    $project->update(['status' => ProjectStatus::Active]);
    $project->deployments()->create(['status' => DeploymentStatus::Pending]);
    $this->artisan('manager:update-ready')->assertFailed();
});

it('blocks application work during a queued update and releases nested locks', function () {
    $directory = sys_get_temp_dir().'/manager-update-test-'.bin2hex(random_bytes(6));
    mkdir($directory);
    touch($directory.'/gate');
    file_put_contents($directory.'/state', json_encode(['state' => 'queued']));
    config(['manager.update_lock' => $directory.'/gate', 'manager.update_state' => $directory.'/state']);
    try {
        expect(fn () => InfrastructureLock::run(fn () => 'unsafe'))->toThrow(ValidationException::class);
        file_put_contents($directory.'/state', json_encode(['state' => 'successful']));
        expect(InfrastructureLock::run(fn () => InfrastructureLock::run(fn () => 'safe')))->toBe('safe');
        $exclusive = fopen($directory.'/gate', 'r+');
        flock($exclusive, LOCK_EX | LOCK_NB);
        expect(fn () => InfrastructureLock::run(fn () => 'unsafe'))->toThrow(ValidationException::class);
        fclose($exclusive);
    } finally {
        unlink($directory.'/state');
        unlink($directory.'/gate');
        rmdir($directory);
    }
});

it('keeps update history bounded and discards diagnostics and invalid timestamps', function () {
    $this->actingAs(User::factory()->create());
    fakeManagerUpdate([
        'state' => 'failed',
        'history' => array_fill(0, 30, ['phase' => 'assets', 'at' => '2026-09-30T14:00:00+00:00', 'message' => 'SECRET']),
        'finished_at' => '9999-99-99',
    ]);
    $safe = app(ManagerUpdates::class)->status();
    expect($safe['history'])->toHaveCount(12)->and($safe)->not->toHaveKey('finished_at')
        ->and(json_encode($safe))->not->toContain('SECRET');
});

it('installs only fixed launcher permissions and an independent update service', function () {
    $sudoers = file_get_contents(base_path('scripts/laravel-manager-updates.sudoers'));
    expect(trim($sudoers))->toBe('www-data ALL=(root) NOPASSWD: /usr/local/sbin/laravel-manager-updates status, /usr/local/sbin/laravel-manager-updates check, /usr/local/sbin/laravel-manager-updates start');
    $unit = file_get_contents(base_path('scripts/laravel-manager-update.service'));
    expect($unit)->toContain('Type=oneshot', 'User=root', 'ExecStart=/usr/local/sbin/laravel-manager-updates run', 'StandardOutput=null')
        ->not->toContain('PartOf=', 'Requires=apache2', 'Restart=always');
    $syntax = new Symfony\Component\Process\Process(['/bin/bash', '-n', base_path('scripts/install-update-bridge.sh')]);
    $syntax->run();
    expect($syntax->isSuccessful())->toBeTrue();
});
