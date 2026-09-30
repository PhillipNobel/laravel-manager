<?php

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Livewire\Apps\Create;
use App\Livewire\Apps\Show;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Process::fake(fn (PendingProcess $process) => fakeAvailableServerCommand($process) ?? Process::result());
});

it('shows a helpful empty state on the apps page', function () {
    $response = $this->get(route('apps.index'))
        ->assertOk()
        ->assertSee('No applications yet')
        ->assertSee('Create your first app');

    expect(substr_count($response->getContent(), 'href="'.route('apps.create').'"'))->toBe(1);
});

it('lists managed applications', function () {
    Project::query()->create([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.com',
        'branch' => 'main',
        'php_version' => '8.4',
    ]);

    $this->get(route('apps.index'))
        ->assertOk()
        ->assertSee('Customer Portal')
        ->assertSee('customer.apps.example.com')
        ->assertSee('main');
});

it('requires a GitHub connection before creating an application', function () {
    $this->get(route('apps.create'))
        ->assertOk()
        ->assertSee('Connect GitHub before creating an application.')
        ->assertSee('Settings');

    expect(Project::query()->count())->toBe(0);
});

it('previews the generated domain using the configured base domain', function () {
    AppSetting::query()->create(['key' => 'base_domain', 'value' => 'apps.example.test']);
    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_preview_test_token',
        'connected_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'octocat/customer-portal',
                'private' => true,
                'default_branch' => 'main',
                'html_url' => 'https://github.com/octocat/customer-portal',
                'clone_url' => 'https://github.com/octocat/customer-portal.git',
            ],
        ]),
    ]);

    Livewire::test(Create::class)
        ->set('subdomain', 'customer')
        ->assertSee('customer.apps.example.test');
});

it('rejects an invalid or duplicate subdomain', function () {
    Project::query()->create([
        'name' => 'Existing Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.com',
        'branch' => 'main',
    ]);

    Livewire::test(Create::class)
        ->set('name', 'Another Portal')
        ->set('subdomain', 'customer')
        ->set('branch', 'main')
        ->set('phpVersion', '8.4')
        ->call('save')
        ->assertHasErrors('subdomain');

    Livewire::test(Create::class)
        ->set('name', 'Another Portal')
        ->set('subdomain', 'not a domain')
        ->set('branch', 'main')
        ->set('phpVersion', '8.4')
        ->call('save')
        ->assertHasErrors('subdomain');
});

it('requires a name and a supported PHP version', function () {
    Livewire::test(Create::class)
        ->set('name', '')
        ->set('subdomain', 'portal')
        ->set('branch', 'main')
        ->set('phpVersion', '9.9')
        ->call('save')
        ->assertHasErrors(['name', 'phpVersion']);
});

it('rejects database engines outside the supported allowlist', function () {
    Livewire::test(Create::class)
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('branch', 'main')
        ->set('phpVersion', '8.3')
        ->set('databaseEngine', 'sqlite')
        ->call('save')
        ->assertHasErrors('databaseEngine');

    expect(Project::query()->count())->toBe(0);
});

it('keeps unavailable PHP options visible and blocks a stale choice on submit', function () {
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        if (($process->command[0] ?? null) === '/usr/bin/php8.2' && ($process->command[1] ?? null) === '--version') {
            return Process::result(exitCode: 127);
        }

        return fakeAvailableServerCommand($process) ?? Process::result();
    });

    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_unavailable_test_token',
        'connected_at' => now(),
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/user/repos*' => Http::response([
        [
            'full_name' => 'octocat/customer-portal',
            'private' => true,
            'default_branch' => 'main',
            'html_url' => 'https://github.com/octocat/customer-portal',
            'clone_url' => 'https://github.com/octocat/customer-portal.git',
        ],
    ])]);

    Livewire::test(Create::class)
        ->assertSee('PHP 8.2 (unavailable)')
        ->assertSeeHtml('value="8.2" disabled>')
        ->assertSee('PHP 8.2: Install PHP 8.2 CLI and FPM.')
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'octocat/customer-portal')
        ->set('branch', 'main')
        ->set('phpVersion', '8.2')
        ->set('databaseEngine', 'mysql')
        ->call('save')
        ->assertHasErrors('phpVersion')
        ->assertSee('PHP 8.2 is unavailable. Install PHP 8.2 CLI and FPM.');

    expect(Project::query()->count())->toBe(0);
});

it('shows clear placeholders when the server has no supported PHP runtime', function () {
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        if (preg_match('/\A\/usr\/bin\/php8\.[234]\z/', $process->command[0] ?? '')
            && ($process->command[1] ?? null) === '--version') {
            return Process::result(exitCode: 127);
        }

        return fakeAvailableServerCommand($process) ?? Process::result();
    });

    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_no_runtime_test_token',
        'connected_at' => now(),
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/user/repos*' => Http::response([
        [
            'full_name' => 'octocat/customer-portal',
            'private' => true,
            'default_branch' => 'main',
            'html_url' => 'https://github.com/octocat/customer-portal',
            'clone_url' => 'https://github.com/octocat/customer-portal.git',
        ],
    ])]);

    Livewire::test(Create::class)
        ->assertSee('No PHP-FPM runtime available')
        ->assertSee('No database engine available')
        ->assertSee('No supported PHP-FPM runtime is ready.');
});

it('blocks a database choice when its server service is unavailable', function () {
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        if (($process->command[0] ?? null) === '/usr/bin/systemctl'
            && in_array('postgresql.service', $process->command, true)) {
            return Process::result(exitCode: 3);
        }

        return fakeAvailableServerCommand($process) ?? Process::result();
    });

    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_unavailable_test_token',
        'connected_at' => now(),
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/user/repos*' => Http::response([
        [
            'full_name' => 'octocat/customer-portal',
            'private' => true,
            'default_branch' => 'main',
            'html_url' => 'https://github.com/octocat/customer-portal',
            'clone_url' => 'https://github.com/octocat/customer-portal.git',
        ],
    ])]);

    Livewire::test(Create::class)
        ->assertSee('PostgreSQL (unavailable)')
        ->assertSeeHtml('value="pgsql" disabled>')
        ->assertSee('PostgreSQL: Install and start the PostgreSQL service.')
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'octocat/customer-portal')
        ->set('branch', 'main')
        ->set('phpVersion', '8.3')
        ->set('databaseEngine', 'pgsql')
        ->call('save')
        ->assertHasErrors('databaseEngine')
        ->assertSee('PostgreSQL is unavailable. Install and start the PostgreSQL service.');

    expect(Project::query()->count())->toBe(0);
});

it('disables a database engine when the selected PHP runtime lacks its PDO driver', function () {
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        if (($process->command[0] ?? null) === '/usr/bin/php8.4' && ($process->command[1] ?? null) === '-m') {
            return Process::result(output: "PDO\npdo_mysql\n");
        }

        return fakeAvailableServerCommand($process) ?? Process::result();
    });

    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_missing_driver_test_token',
        'connected_at' => now(),
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/user/repos*' => Http::response([
        [
            'full_name' => 'octocat/customer-portal',
            'private' => true,
            'default_branch' => 'main',
            'html_url' => 'https://github.com/octocat/customer-portal',
            'clone_url' => 'https://github.com/octocat/customer-portal.git',
        ],
    ])]);

    Livewire::test(Create::class)
        ->set('phpVersion', '8.4')
        ->assertSee('PostgreSQL: Install the PHP 8.4 PostgreSQL driver.')
        ->assertSeeHtml('value="pgsql" disabled>')
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'octocat/customer-portal')
        ->set('branch', 'main')
        ->set('databaseEngine', 'pgsql')
        ->call('save')
        ->assertHasErrors('databaseEngine');

    expect(Project::query()->count())->toBe(0);
});

it('shows project details and the empty deployment state', function () {
    $project = Project::query()->create([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.com',
        'branch' => 'main',
        'php_version' => '8.4',
        'database_engine' => 'pgsql',
    ]);

    $this->get(route('apps.show', $project))
        ->assertOk()
        ->assertSee('Customer Portal')
        ->assertSee('customer.apps.example.com')
        ->assertSee('No deployments yet')
        ->assertSee('Not provisioned')
        ->assertSee('8.4')
        ->assertSee('PostgreSQL');
});

it('does not allow a Livewire client to modify the bound project state', function () {
    $project = Project::query()->create([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.com',
        'branch' => 'main',
        'status' => ProjectStatus::Pending,
    ]);

    expect(fn () => Livewire::test(Show::class, ['project' => $project])
        ->set('project.status', ProjectStatus::Active->value))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($project->fresh()->status)->toBe(ProjectStatus::Pending);
});

it('relates projects to their deployments', function () {
    $project = Project::query()->create([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.com',
        'branch' => 'main',
    ]);

    $deployment = $project->deployments()->create([
        'status' => DeploymentStatus::Pending,
    ]);

    expect($project->deployments()->sole()->is($deployment))->toBeTrue()
        ->and($deployment->project->is($project))->toBeTrue();
});
