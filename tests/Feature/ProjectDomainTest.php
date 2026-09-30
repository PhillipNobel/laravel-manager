<?php

use App\Actions\Projects\ConfigureProjectDomain;
use App\Enums\DomainStatus;
use App\Livewire\Apps\Show;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function () {
    $this->applicationsRoot = storage_path('framework/testing/domains-'.Str::uuid());
    File::makeDirectory($this->applicationsRoot, 0755, true);
    AppSetting::query()->create(['key' => 'applications_directory', 'value' => $this->applicationsRoot]);
    AppSetting::query()->create(['key' => 'base_domain', 'value' => 'apps.example.test']);
});

afterEach(function () {
    if (isset($this->applicationsRoot)) {
        File::deleteDirectory($this->applicationsRoot);
    }
});

function makeProvisionedProject(string $root, string $slug = 'customer'): Project
{
    $path = $root.'/'.$slug;
    File::makeDirectory($path.'/public', 0755, true);

    return Project::query()->create([
        'name' => 'Customer Portal',
        'slug' => $slug,
        'domain' => $slug.'.apps.example.test',
        'path' => $path,
        'branch' => 'main',
        'status' => 'active',
    ]);
}

it('configures an authenticated project domain with one fixed helper command', function () {
    $project = makeProvisionedProject($this->applicationsRoot);
    Process::fake(['*' => Process::result(output: 'ACTIVE')]);
    Process::preventStrayProcesses();

    Livewire::actingAs(User::factory()->create())
        ->test(Show::class, ['project' => $project])
        ->call('configureDomain')
        ->assertHasNoErrors()
        ->assertSee('Apache accepted the virtual host configuration and the service is active.')
        ->assertSee('Active');

    $project->refresh();
    expect($project->domain_status)->toBe(DomainStatus::Active)
        ->and($project->domain_message)->toContain('Apache accepted');

    Process::assertRan(function (PendingProcess $process): bool {
        return $process->command === [
            '/usr/bin/sudo',
            '-n',
            '/usr/local/sbin/laravel-manager-apache',
            'enable',
            'customer',
            '8.3',
        ] && $process->timeout === 35;
    });
});

it('refreshes domain status without requesting a reload', function () {
    $project = makeProvisionedProject($this->applicationsRoot);
    Process::fake(['*' => Process::result(output: 'PENDING')]);
    Process::preventStrayProcesses();

    $status = app(ConfigureProjectDomain::class)->refreshStatus($project);

    expect($status)->toBe(DomainStatus::Pending)
        ->and($project->fresh()->domain_message)->toBe('The Apache virtual host is not enabled.');

    Process::assertRan(function (PendingProcess $process): bool {
        return $process->command === [
            '/usr/bin/sudo',
            '-n',
            '/usr/local/sbin/laravel-manager-apache',
            'status',
            'customer',
            '8.3',
        ];
    });
});

it('marks helper failures without showing diagnostic output on the project page', function () {
    $project = makeProvisionedProject($this->applicationsRoot);
    Process::fake(['*' => Process::result(errorOutput: 'Apache configuration failed: private detail', exitCode: 1)]);
    Process::preventStrayProcesses();

    $status = app(ConfigureProjectDomain::class)->handle($project);

    expect($status)->toBe(DomainStatus::Failed)
        ->and($project->fresh()->domain_message)->toContain('Check the Laravel Manager log')
        ->and($project->fresh()->domain_message)->not->toContain('private detail');
});

it('does not call the helper for unprovisioned or unsafe project paths', function () {
    Process::fake();
    Process::preventStrayProcesses();

    $unprovisioned = Project::query()->create([
        'name' => 'Unprovisioned',
        'slug' => 'pending',
        'domain' => 'pending.apps.example.test',
        'branch' => 'main',
    ]);

    expect(app(ConfigureProjectDomain::class)->handle($unprovisioned))->toBe(DomainStatus::Pending)
        ->and($unprovisioned->fresh()->domain_message)->toContain('Finish application provisioning');

    $failed = makeProvisionedProject($this->applicationsRoot, 'failed');
    $failed->update(['status' => 'failed']);

    expect(app(ConfigureProjectDomain::class)->handle($failed))->toBe(DomainStatus::Failed)
        ->and($failed->fresh()->domain_message)->toContain('Finish application provisioning');

    $unsafe = makeProvisionedProject($this->applicationsRoot, 'outside');
    $unsafe->update(['path' => storage_path('outside-project')]);

    expect(app(ConfigureProjectDomain::class)->handle($unsafe))->toBe(DomainStatus::Failed)
        ->and($unsafe->fresh()->domain_message)->toContain('configured root');

    Process::assertNothingRan();
});

it('renders a Laravel public directory virtual host without invoking Apache', function () {
    $process = new SymfonyProcess([
        'python3',
        base_path('scripts/laravel-manager-apache'),
        'render',
        'customer',
        'customer.apps.example.test',
        '/var/www/apps/customer/public',
        '8.2',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain(
            '<VirtualHost *:80>',
            'ServerName customer.apps.example.test',
            'DocumentRoot "/var/www/apps/customer/public"',
            '<Directory "/var/www/apps/customer/public">',
            'AllowOverride All',
            'Require all granted',
            'customer-access.log',
            'SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost/"',
        )
        ->and($process->getOutput())->not->toContain('DocumentRoot "/var/www/apps/customer"');
});

it('quotes application paths with spaces in Apache directives', function () {
    $process = new SymfonyProcess([
        'python3',
        base_path('scripts/laravel-manager-apache'),
        'render',
        'customer',
        'customer.apps.example.test',
        '/var/www/customer apps/customer/public',
        '8.3',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain(
            'DocumentRoot "/var/www/customer apps/customer/public"',
            '<Directory "/var/www/customer apps/customer/public">',
        );
});

it('renders HTTPS and an HTTP redirect while keeping ACME challenges reachable', function () {
    $httpProcess = new SymfonyProcess([
        'python3',
        base_path('scripts/laravel-manager-apache'),
        'render-https-redirect',
        'customer',
        'customer.apps.example.test',
        '/var/www/apps/customer/public',
        '8.3',
    ]);
    $httpProcess->run();

    $sslProcess = new SymfonyProcess([
        'python3',
        base_path('scripts/laravel-manager-apache'),
        'render-ssl',
        'customer',
        'customer.apps.example.test',
        '/var/www/apps/customer/public',
        '8.3',
    ]);
    $sslProcess->run();

    expect($httpProcess->isSuccessful())->toBeTrue()
        ->and($httpProcess->getOutput())->toContain(
            'RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/',
            'RewriteRule ^ https://customer.apps.example.test%{REQUEST_URI} [R=301,L,NE]',
        )
        ->and($sslProcess->isSuccessful())->toBeTrue()
        ->and($sslProcess->getOutput())->toContain(
            '<VirtualHost *:443>',
            'SSLEngine on',
            'SSLCertificateFile "/etc/letsencrypt/live/customer.apps.example.test/fullchain.pem"',
            'SSLCertificateKeyFile "/etc/letsencrypt/live/customer.apps.example.test/privkey.pem"',
        );
});

it('renders the fixed Apache reload hook used by Certbot renewals', function () {
    $process = new SymfonyProcess([
        'python3',
        base_path('scripts/laravel-manager-apache'),
        'render-renewal-hook',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toBe("#!/bin/sh\nexec /usr/bin/systemctl reload apache2\n");
});

it('limits the sudo rule to fixed Apache and HTTPS helper operations', function () {
    $sudoers = file_get_contents(base_path('scripts/laravel-manager-apache.sudoers'));

    expect($sudoers)->toContain(
        '^(enable|status|ssl-status) [a-z0-9]',
        '^ssl-enable [a-z0-9]',
        '8\\.[234]',
        '[A-Za-z0-9._%+~-]+@',
    )
        ->and($sudoers)->not->toContain('ALL,', ' /bin/sh', 'apache2ctl', 'certbot');
});

it('rejects unsafe Apache template values', function (array $arguments) {
    $process = new SymfonyProcess([
        'python3',
        base_path('scripts/laravel-manager-apache'),
        'render',
        ...$arguments,
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('ERROR:');
})->with([
    'shell metacharacter in slug' => [['customer;touch-pwned', 'customer.apps.example.test', '/var/www/apps/customer;touch-pwned/public', '8.3']],
    'directive injection in domain' => [['customer', "customer.apps.example.test\nInclude /tmp/unsafe.conf", '/var/www/apps/customer/public', '8.3']],
    'document root outside applications path' => [['customer', 'customer.apps.example.test', '/etc/apache2/public', '8.3']],
    'unsupported php version' => [['customer', 'customer.apps.example.test', '/var/www/apps/customer/public', '8.5']],
]);

it('protects project domain pages and provides disabled actions before provisioning', function () {
    $project = Project::query()->create([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.test',
        'branch' => 'main',
    ]);

    $this->get(route('apps.show', $project))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('apps.show', $project))
        ->assertOk()
        ->assertSee('Apache domain')
        ->assertSee('Not configured')
        ->assertSee('Configure Apache')
        ->assertSee('Check status')
        ->assertSeeHtml('disabled');
});
