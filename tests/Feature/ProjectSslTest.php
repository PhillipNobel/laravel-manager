<?php

use App\Actions\Projects\ConfigureProjectSsl;
use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Enums\SslStatus;
use App\Jobs\EnableProjectSsl;
use App\Livewire\Apps\Show;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->applicationsRoot = storage_path('framework/testing/ssl-'.Str::uuid());
    File::makeDirectory($this->applicationsRoot, 0755, true);
    AppSetting::query()->create(['key' => 'applications_directory', 'value' => $this->applicationsRoot]);
    AppSetting::query()->create(['key' => 'base_domain', 'value' => 'apps.example.test']);
    Queue::fake();
});

afterEach(function () {
    if (isset($this->applicationsRoot)) {
        File::deleteDirectory($this->applicationsRoot);
    }
});

it('queues HTTPS setup for an authenticated project with an active Apache domain', function () {
    $project = makeSslProject($this->applicationsRoot);
    $admin = User::factory()->create(['email' => 'admin@example.com']);
    Process::preventStrayProcesses();

    Livewire::actingAs($admin)
        ->test(Show::class, ['project' => $project])
        ->call('enableHttps')
        ->assertHasNoErrors()
        ->assertSee('Certificate setup is queued.')
        ->assertSee('Setting up')
        ->assertSee('This page checks progress automatically.');

    expect($project->fresh()->ssl_status)->toBe(SslStatus::Provisioning);

    Queue::assertPushed(EnableProjectSsl::class, fn (EnableProjectSsl $job): bool => $job->projectId === $project->id
        && $job->email === 'admin@example.com');
    Process::assertNothingRan();
});

it('does not queue a certificate request until the Apache domain is active', function () {
    $project = makeSslProject($this->applicationsRoot, ['domain_status' => DomainStatus::Pending]);
    Process::preventStrayProcesses();

    expect(app(ConfigureProjectSsl::class)->queue($project, 'admin@example.com'))
        ->toBe(SslStatus::Pending)
        ->and($project->fresh()->ssl_message)->toContain('Configure and verify the Apache domain');

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('rejects an invalid Certbot account email before queueing work', function () {
    $project = makeSslProject($this->applicationsRoot);
    Process::fake();
    Process::preventStrayProcesses();

    expect(app(ConfigureProjectSsl::class)->queue($project, 'invalid-email'))
        ->toBe(SslStatus::Failed)
        ->and($project->fresh()->ssl_message)->toContain('Use a valid administrator email');

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('does not queue HTTPS when the project domain no longer matches Settings', function () {
    $project = makeSslProject($this->applicationsRoot, ['domain' => 'customer.old-example.test']);
    Process::fake();
    Process::preventStrayProcesses();

    expect(app(ConfigureProjectSsl::class)->queue($project, 'admin@example.com'))
        ->toBe(SslStatus::Failed)
        ->and($project->fresh()->ssl_message)->toContain('no longer matches the configured applications domain');

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('prevents duplicate HTTPS setup jobs while the first request is pending', function () {
    $project = makeSslProject($this->applicationsRoot);
    $configureSsl = app(ConfigureProjectSsl::class);

    expect($configureSsl->queue($project, 'admin@example.com'))
        ->toBe(SslStatus::Provisioning)
        ->and($configureSsl->queue($project, 'admin@example.com'))
        ->toBe(SslStatus::Provisioning);

    Queue::assertPushed(EnableProjectSsl::class, 1);
});

it('records certificate activation and expiry from the restricted Apache helper', function () {
    $project = makeSslProject($this->applicationsRoot, ['ssl_status' => SslStatus::Provisioning]);
    Process::fake(['*' => Process::result(output: 'ACTIVE|2027-08-01T10:30:00Z')]);
    Process::preventStrayProcesses();

    app(ConfigureProjectSsl::class)->enable($project, 'admin@example.com');

    expect($project->fresh()->ssl_status)->toBe(SslStatus::Active)
        ->and($project->fresh()->ssl_expires_at->toIso8601String())->toBe('2027-08-01T10:30:00+00:00')
        ->and($project->fresh()->ssl_message)->toContain('Certbot renews this certificate automatically.');

    Process::assertRan(function (PendingProcess $process): bool {
        return $process->command === [
            '/usr/bin/sudo',
            '-n',
            '/usr/local/sbin/laravel-manager-apache',
            'ssl-enable',
            'customer',
            'admin@example.com',
        ] && $process->timeout === 720;
    });
});

it('records helper failures with safe retry guidance', function () {
    $project = makeSslProject($this->applicationsRoot, ['ssl_status' => SslStatus::Provisioning]);
    Process::fake(['*' => Process::result(errorOutput: 'ACME challenge failed: private diagnostic', exitCode: 1)]);
    Process::preventStrayProcesses();

    app(ConfigureProjectSsl::class)->enable($project, 'admin@example.com');

    expect($project->fresh()->ssl_status)->toBe(SslStatus::Failed)
        ->and($project->fresh()->ssl_message)->toContain('Check DNS and public HTTP access, then retry.')
        ->and($project->fresh()->ssl_message)->not->toContain('private diagnostic');
});

it('refreshes HTTPS status and records an expired certificate', function () {
    $project = makeSslProject($this->applicationsRoot);
    Process::fake(['*' => Process::result(output: 'EXPIRED|2025-08-01T10:30:00Z')]);
    Process::preventStrayProcesses();

    Livewire::actingAs(User::factory()->create())
        ->test(Show::class, ['project' => $project])
        ->call('refreshHttpsStatus')
        ->assertSee('The HTTPS certificate has expired. Retry HTTPS setup to renew it.')
        ->assertSee('Failed')
        ->assertSee('Retry HTTPS setup');

    expect($project->fresh()->ssl_status)->toBe(SslStatus::Failed)
        ->and($project->fresh()->ssl_expires_at->toIso8601String())->toBe('2025-08-01T10:30:00+00:00');

    Process::assertRan([
        '/usr/bin/sudo',
        '-n',
        '/usr/local/sbin/laravel-manager-apache',
        'ssl-status',
        'customer',
    ]);
});

it('does not run a status check while HTTPS setup is provisioning', function () {
    $project = makeSslProject($this->applicationsRoot, ['ssl_status' => SslStatus::Provisioning]);
    Process::fake();
    Process::preventStrayProcesses();

    expect(app(ConfigureProjectSsl::class)->refreshStatus($project))->toBe(SslStatus::Provisioning);

    Process::assertNothingRan();
});

function makeSslProject(string $applicationsRoot, array $overrides = []): Project
{
    $path = $applicationsRoot.'/customer';
    File::makeDirectory($path.'/public', 0755, true);

    return Project::query()->create(array_merge([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.test',
        'path' => $path,
        'branch' => 'main',
        'status' => ProjectStatus::Active,
        'domain_status' => DomainStatus::Active,
        'ssl_status' => SslStatus::Pending,
    ], $overrides));
}
