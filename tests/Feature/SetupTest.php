<?php

use App\Livewire\Setup\Index as SetupWizard;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    AppSetting::query()->create(['key' => 'setup_completed', 'value' => '0']);
});

function fakeSetupServerChecks(): void
{
    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        $binary = $command[0] ?? '';

        if (preg_match('/\A\/usr\/bin\/php8\.[234]\z/', $binary) && ($command[1] ?? null) === '--version') {
            return Process::result(output: 'PHP '.substr($binary, -3).'.10.0 (cli)');
        }

        if (preg_match('/\A\/usr\/bin\/php8\.[234]\z/', $binary) && ($command[1] ?? null) === '-m') {
            return Process::result(output: "PDO\npdo_mysql\npdo_pgsql\n");
        }

        if (in_array($binary, ['apache2', 'git', 'composer', 'node', 'certbot'], true)) {
            return Process::result(output: $binary.' version 1.0');
        }

        if ($binary === '/usr/bin/systemctl' || $binary === '/usr/bin/test') {
            return Process::result();
        }

        if ($binary === '/usr/bin/mysql' || $binary === '/usr/bin/psql') {
            return Process::result(output: $binary.' version 1.0');
        }

        if ($binary === '/usr/bin/pg_isready') {
            return Process::result(output: 'accepting connections');
        }

        throw new RuntimeException('Unexpected server check: '.implode(' ', $command));
    });
    Process::preventStrayProcesses();
}

function reachDnsConfirmationStep($wizard)
{
    $wizard->call('next')
        ->set('baseDomain', 'apps.example.test')
        ->set('publicIp', '203.0.113.10')
        ->set('applicationsDirectory', '/var/www/apps')
        ->set('defaultPhpVersion', '8.3')
        ->call('next')
        ->call('checkServer')
        ->call('next')
        ->call('next');

    return $wizard;
}

it('protects setup and redirects an authenticated administrator to it after login', function () {
    $this->get(route('setup'))
        ->assertOk()
        ->assertSee('Prepare this server for apps')
        ->assertSee($this->user->email);

    $this->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->post(route('login.store'), [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertRedirect(route('setup'));
});

it('redirects unauthenticated users to login and protects product pages until setup is complete', function () {
    auth()->logout();

    $this->get(route('setup'))->assertRedirect(route('login'));

    $this->actingAs($this->user);

    foreach ([
        route('apps.index'),
        route('apps.create'),
        route('server'),
        route('settings'),
        route('settings.github.repositories'),
    ] as $url) {
        $this->get($url)->assertRedirect(route('setup'));
    }
});

it('saves validated server defaults before continuing to the read-only checks', function () {
    Livewire::test(SetupWizard::class)
        ->call('next')
        ->assertSet('currentStep', 2)
        ->set('baseDomain', ' APPS.Example.Test ')
        ->set('publicIp', '203.0.113.10')
        ->set('applicationsDirectory', '/srv/laravel-apps/')
        ->set('defaultPhpVersion', '8.4')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('currentStep', 3);

    expect(AppSetting::valueFor('base_domain'))->toBe('apps.example.test')
        ->and(AppSetting::valueFor('public_ip'))->toBe('203.0.113.10')
        ->and(AppSetting::valueFor('applications_directory'))->toBe('/srv/laravel-apps')
        ->and(AppSetting::valueFor('default_php_version'))->toBe('8.4')
        ->and(AppSetting::initialSetupIsComplete())->toBeFalse();
});

it('rejects an invalid domain, IP, applications directory, and unsupported PHP version', function () {
    Livewire::test(SetupWizard::class)
        ->call('next')
        ->set('baseDomain', 'https://apps.example.test')
        ->set('publicIp', 'not-an-ip')
        ->set('applicationsDirectory', '/srv/apps/../outside')
        ->set('defaultPhpVersion', '9.9')
        ->call('next')
        ->assertHasErrors(['baseDomain', 'publicIp', 'applicationsDirectory', 'defaultPhpVersion'])
        ->assertSet('currentStep', 2);

    expect(AppSetting::query()->whereIn('key', [
        'base_domain',
        'public_ip',
        'applications_directory',
        'default_php_version',
    ])->count())->toBe(0);
});

it('requires an explicit read-only server check before proceeding', function () {
    fakeSetupServerChecks();

    Livewire::test(SetupWizard::class)
        ->call('next')
        ->set('baseDomain', 'apps.example.test')
        ->set('publicIp', '203.0.113.10')
        ->set('applicationsDirectory', '/var/www/apps')
        ->set('defaultPhpVersion', '8.3')
        ->call('next')
        ->assertSet('currentStep', 3)
        ->call('next')
        ->assertHasErrors('requirements')
        ->assertSet('currentStep', 3)
        ->call('checkServer')
        ->assertSet('serverChecked', true)
        ->assertSee('Available')
        ->call('next')
        ->assertSet('currentStep', 4);
});

it('shows clear package and service requirements when server checks fail', function () {
    Process::fake(fn () => Process::result(exitCode: 127));
    Process::preventStrayProcesses();

    Livewire::test(SetupWizard::class)
        ->call('next')
        ->set('baseDomain', 'apps.example.test')
        ->set('publicIp', '203.0.113.10')
        ->set('applicationsDirectory', '/var/www/apps')
        ->set('defaultPhpVersion', '8.3')
        ->call('next')
        ->call('checkServer')
        ->assertSee('Install Apache.')
        ->assertSee('Install Composer.')
        ->assertSee('Install PHP 8.2 CLI and FPM.')
        ->assertSee('Install and start the MySQL service.')
        ->assertSee('Install and start the PostgreSQL service.');
});

it('keeps GitHub optional and lets the administrator confirm DNS before finishing', function () {
    fakeSetupServerChecks();
    Config::set('services.github.client_id', 'client-id');
    Config::set('services.github.client_secret', 'client-secret');

    $wizard = reachDnsConfirmationStep(Livewire::test(SetupWizard::class));

    $wizard->assertSet('currentStep', 5)
        ->assertSee('*.apps.example.test')
        ->assertSee('203.0.113.10')
        ->call('next')
        ->assertHasErrors('wildcardDnsConfirmed')
        ->set('wildcardDnsConfirmed', true)
        ->call('next')
        ->assertSet('currentStep', 6)
        ->call('finish')
        ->assertRedirect(route('apps.index'));

    expect(AppSetting::initialSetupIsComplete())->toBeTrue();

    $this->get(route('apps.index'))
        ->assertOk()
        ->assertSee('Server setup is complete.')
        ->assertSee('No applications yet')
        ->assertSee('Create your first app');
});

it('shows GitHub connection status and preserves the setup step through the OAuth return', function () {
    GitHubConnection::query()->create([
        'github_user_id' => 12345,
        'login' => 'octocat',
        'access_token' => 'gho_test_secret',
        'connected_at' => now(),
    ]);

    $this->withSession([
        'laravel_manager_setup' => [
            'step' => 4,
            'server_checked' => true,
            'requirements' => [],
        ],
    ]);

    Livewire::test(SetupWizard::class)
        ->assertSet('currentStep', 4)
        ->assertSee('Connected as octocat')
        ->assertDontSee('gho_test_secret');

    $this->get(route('settings.github.callback'))
        ->assertRedirect(route('settings'));

    $this->get(route('settings'))->assertRedirect(route('setup'));
    $this->get(route('setup'))->assertOk()->assertSee('Connected as octocat');
});

it('marks newly seeded administrators as needing setup without resetting completed setup', function () {
    Config::set('manager.local_admin_email', 'admin@example.test');
    Config::set('manager.local_admin_password', 'first-admin-password');
    Config::set('manager.local_admin_name', 'Server Admin');

    AppSetting::query()->delete();
    app(AdminUserSeeder::class)->run();

    expect(AppSetting::valueFor('setup_completed'))->toBe('0')
        ->and(User::query()->where('email', 'admin@example.test')->exists())->toBeTrue();

    AppSetting::query()->updateOrCreate(['key' => 'setup_completed'], ['value' => '1']);
    app(AdminUserSeeder::class)->run();

    expect(AppSetting::valueFor('setup_completed'))->toBe('1');
});

it('treats a missing setup flag as complete for servers already in use', function () {
    AppSetting::query()->delete();

    expect(AppSetting::initialSetupIsComplete())->toBeTrue();

    $this->get(route('apps.index'))->assertOk();
});
