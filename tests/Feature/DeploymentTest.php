<?php

use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Jobs\DeployProject;
use App\Livewire\Apps\Show;
use App\Models\AppSetting;
use App\Models\Deployment;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->applicationsRoot = storage_path('framework/testing/deployments-'.Str::uuid());
    $this->projectPath = $this->applicationsRoot.'/customer';
    File::makeDirectory($this->projectPath.'/.git', 0755, true);
    File::put($this->projectPath.'/.git/config', "[remote \"origin\"]\n\turl = https://github.com/octocat/customer-portal.git\n");
    File::put($this->projectPath.'/.gitignore', ".env\n/vendor\n/node_modules\n");
    File::put($this->projectPath.'/.env', "APP_KEY=base64:deploy-key-value\nDB_PASSWORD=deploy-password\n");
    File::put($this->projectPath.'/artisan', "<?php\n");
    File::put($this->projectPath.'/composer.json', '{}');
    File::put($this->projectPath.'/composer.lock', '{}');
    File::put($this->projectPath.'/package.json', json_encode(['scripts' => ['build' => 'vite build']]));
    File::put($this->projectPath.'/package-lock.json', '{}');
    AppSetting::query()->create(['key' => 'applications_directory', 'value' => $this->applicationsRoot]);
    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_deploy_test_token',
        'connected_at' => now(),
    ]);
    $this->admin = User::factory()->create();
    $this->project = Project::query()->create([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.test',
        'path' => $this->projectPath,
        'repository_url' => 'https://github.com/octocat/customer-portal',
        'repository_name' => 'octocat/customer-portal',
        'branch' => 'main',
        'status' => ProjectStatus::Active,
        'database_status' => DatabaseStatus::Active,
    ]);
});

afterEach(function () {
    if (isset($this->applicationsRoot)) {
        File::deleteDirectory($this->applicationsRoot);
    }
});

it('queues a manual deployment from the authenticated project page', function () {
    Queue::fake();

    Livewire::actingAs($this->admin)
        ->test(Show::class, ['project' => $this->project])
        ->call('deploy')
        ->assertHasNoErrors()
        ->assertSee('Deployment queued.');

    $deployment = Deployment::query()->sole();

    expect($deployment->status)->toBe(DeploymentStatus::Pending)
        ->and($deployment->project->is($this->project))->toBeTrue();

    Queue::assertPushed(DeployProject::class, fn (DeployProject $job): bool => $job->deploymentId === $deployment->id);
});

it('prevents another deployment while one is pending or running', function () {
    Queue::fake();
    $this->project->deployments()->create(['status' => DeploymentStatus::Running]);

    Livewire::actingAs($this->admin)
        ->test(Show::class, ['project' => $this->project])
        ->call('deploy')
        ->assertHasErrors('deployment')
        ->assertSee('A deployment is already queued or running.');

    expect(Deployment::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('requires an active application database before deployment', function () {
    Queue::fake();
    $this->project->update(['database_status' => DatabaseStatus::Pending]);

    Livewire::actingAs($this->admin)
        ->test(Show::class, ['project' => $this->project])
        ->call('deploy')
        ->assertHasErrors('deployment')
        ->assertSee('Create and verify the application database before deploying.');

    expect(Deployment::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('deploys the configured branch and stores its current commit', function () {
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Pending]);
    $commit = str_repeat('a', 40);

    Http::fake();
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($commit) {
        $command = $process->command;

        if ($command === ['git', 'remote', 'get-url', 'origin']) {
            return Process::result(output: 'https://github.com/octocat/customer-portal.git');
        }

        if ($command === ['git', 'rev-parse', '--verify', 'refs/remotes/origin/main']) {
            return Process::result(output: $commit);
        }

        if ($command === ['git', 'show', '-s', '--format=%s', $commit]) {
            return Process::result(output: 'Release customer dashboard');
        }

        return Process::result(output: 'Step completed.');
    });

    DeployProject::dispatchSync($deployment->id);

    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Successful)
        ->and($deployment->commit_hash)->toBe($commit)
        ->and($deployment->commit_message)->toBe('Release customer dashboard')
        ->and($deployment->started_at)->not->toBeNull()
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($deployment->output)->toContain('Installing Composer dependencies', 'Installing npm dependencies', 'Building frontend assets', 'Running database migrations', 'Optimizing Laravel application', 'Restarting Laravel queue workers', 'Deployment completed successfully.');

    Process::assertRan(['git', 'fetch', '--no-tags', 'origin', 'refs/heads/main:refs/remotes/origin/main']);
    Process::assertRan(['git', 'checkout', '--force', '--detach', $commit]);
    Process::assertRan(['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader']);
    Process::assertRan(['npm', 'ci', '--no-audit', '--no-fund']);
    Process::assertRan(['npm', 'run', 'build']);
    Process::assertRan(['php', 'artisan', 'migrate', '--force']);
    Process::assertRan(['php', 'artisan', 'optimize']);
    Process::assertRan(['php', 'artisan', 'queue:restart']);
    Process::assertRan(function (PendingProcess $process): bool {
        return ($process->command[1] ?? null) === 'fetch'
            && ! str_contains(implode(' ', $process->command), 'gho_deploy_test_token')
            && $process->environment['GIT_CONFIG_COUNT'] === '1'
            && $process->environment['GIT_CONFIG_KEY_0'] === 'http.https://github.com/.extraheader'
            && $process->environment['GIT_CONFIG_VALUE_0'] === 'AUTHORIZATION: basic '.base64_encode('octocat:gho_deploy_test_token')
            && $process->environment['GIT_TERMINAL_PROMPT'] === '0'
            && $process->path === $this->projectPath;
    });

    Http::assertNothingSent();
});

it('checks an active Apache domain through loopback after deployment', function () {
    AppSetting::query()->create(['key' => 'base_domain', 'value' => 'apps.example.test']);
    $this->project->update(['domain_status' => DomainStatus::Active]);
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Pending]);
    $commit = str_repeat('e', 40);

    Http::fake(fn () => Http::response('', 302, ['Location' => 'https://customer.apps.example.test/login']));
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($commit) {
        if (($process->command[1] ?? null) === 'remote') {
            return Process::result(output: 'https://github.com/octocat/customer-portal.git');
        }

        if (($process->command[1] ?? null) === 'rev-parse') {
            return Process::result(output: $commit);
        }

        if (($process->command[1] ?? null) === 'show') {
            return Process::result(output: 'Check application after deploy');
        }

        return Process::result();
    });

    DeployProject::dispatchSync($deployment->id);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Successful)
        ->and($deployment->fresh()->output)->toContain('Checking application response', 'Application responded with HTTP 302.', 'Deployment completed successfully.');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://127.0.0.1/'
        && $request->hasHeader('Host', 'customer.apps.example.test'));
});

it('marks the deployment failed when an active Apache domain returns a server error', function () {
    AppSetting::query()->create(['key' => 'base_domain', 'value' => 'apps.example.test']);
    $this->project->update(['domain_status' => DomainStatus::Active]);
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Pending]);
    $commit = str_repeat('f', 40);

    Http::fake(fn () => Http::response('', 503));
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($commit) {
        if (($process->command[1] ?? null) === 'remote') {
            return Process::result(output: 'https://github.com/octocat/customer-portal.git');
        }

        if (($process->command[1] ?? null) === 'rev-parse') {
            return Process::result(output: $commit);
        }

        if (($process->command[1] ?? null) === 'show') {
            return Process::result(output: 'Check failed application');
        }

        return Process::result();
    });

    DeployProject::dispatchSync($deployment->id);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->finished_at)->not->toBeNull()
        ->and($deployment->fresh()->output)->toContain('Checking application response', 'returned HTTP 503 after deployment')
        ->not->toContain('Deployment completed successfully.');
});

it('records failed deployments and redacts GitHub and application secrets from output', function () {
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Pending]);
    $authorization = base64_encode('octocat:gho_deploy_test_token');

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($authorization) {
        if (($process->command[1] ?? null) === 'remote') {
            return Process::result(output: 'https://github.com/octocat/customer-portal.git');
        }

        if (($process->command[1] ?? null) === 'fetch') {
            return Process::result(
                errorOutput: "gho_deploy_test_token\nAuthorization: basic {$authorization}\nAPP_KEY=base64:deploy-key-value\nDB_PASSWORD=deploy-password",
                exitCode: 1,
            );
        }

        return Process::result();
    });

    DeployProject::dispatchSync($deployment->id);
    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($deployment->output)->toContain('Fetching main failed.', 'Authorization: basic [redacted]')
        ->and($deployment->output)->not->toContain('gho_deploy_test_token', $authorization, 'deploy-key-value', 'deploy-password');
});

it('does not run processes when the project path escapes the configured applications directory', function () {
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Pending]);
    $outsidePath = storage_path('framework/testing/outside-deployment-'.Str::uuid());
    File::makeDirectory($outsidePath, 0755, true);
    $this->project->update(['path' => $outsidePath]);

    Process::preventStrayProcesses();
    Process::fake();

    DeployProject::dispatchSync($deployment->id);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->output)->toContain('outside the configured applications directory');

    Process::assertNothingRan();
    File::deleteDirectory($outsidePath);
});

it('refuses a fetched commit that contains a tracked application environment file', function () {
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Pending]);
    $originalEnvironment = File::get($this->projectPath.'/.env');
    $commit = str_repeat('c', 40);

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($commit) {
        if (($process->command[1] ?? null) === 'remote') {
            return Process::result(output: 'https://github.com/octocat/customer-portal.git');
        }

        if (($process->command[1] ?? null) === 'rev-parse') {
            return Process::result(output: $commit);
        }

        if (($process->command[1] ?? null) === 'ls-tree') {
            return Process::result(output: '.env');
        }

        return Process::result();
    });

    DeployProject::dispatchSync($deployment->id);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->output)->toContain('tracks an .env file')
        ->and(File::get($this->projectPath.'/.env'))->toBe($originalEnvironment);

    Process::assertNotRan(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'checkout');
    Process::assertNotRan(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === 'composer');
});

it('rechecks that the checked out branch ignores .env before installing dependencies', function () {
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Pending]);
    $ignoreChecks = 0;
    $commit = str_repeat('d', 40);

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use (&$ignoreChecks, $commit) {
        $command = $process->command;

        if ($command === ['git', 'remote', 'get-url', 'origin']) {
            return Process::result(output: 'https://github.com/octocat/customer-portal.git');
        }

        if (($command[1] ?? null) === 'check-ignore') {
            $ignoreChecks++;

            return Process::result(exitCode: $ignoreChecks === 1 ? 0 : 1);
        }

        if (($command[1] ?? null) === 'rev-parse') {
            return Process::result(output: $commit);
        }

        if (($command[1] ?? null) === 'show') {
            return Process::result(output: 'Remove .env ignore rule');
        }

        return Process::result();
    });

    DeployProject::dispatchSync($deployment->id);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->output)->toContain('Checking .env Git ignore rule failed.');

    Process::assertRan(['git', 'checkout', '--force', '--detach', $commit]);
    Process::assertNotRan(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === 'composer');
});

it('fails a queued deployment without starting it when another deployment already runs', function () {
    $this->project->deployments()->create(['status' => DeploymentStatus::Running]);
    $queued = $this->project->deployments()->create(['status' => DeploymentStatus::Pending]);

    Process::preventStrayProcesses();
    Process::fake();

    DeployProject::dispatchSync($queued->id);

    expect($queued->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($queued->fresh()->output)->toContain('Another deployment is already running');

    Process::assertNothingRan();
});

it('uses npm install without a lockfile and skips builds without a build script', function () {
    File::delete($this->projectPath.'/package-lock.json');
    File::put($this->projectPath.'/package.json', json_encode(['scripts' => ['test' => 'pest']]));
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Pending]);

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        if (($process->command[1] ?? null) === 'remote') {
            return Process::result(output: 'https://github.com/octocat/customer-portal.git');
        }

        if (($process->command[1] ?? null) === 'rev-parse') {
            return Process::result(output: str_repeat('b', 40));
        }

        if (($process->command[1] ?? null) === 'show') {
            return Process::result(output: 'Update application');
        }

        return Process::result();
    });

    DeployProject::dispatchSync($deployment->id);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Successful)
        ->and($deployment->fresh()->output)->toContain('Skipping npm build');

    Process::assertRan(['npm', 'install', '--no-audit', '--no-fund']);
    Process::assertNotRan(['npm', 'ci', '--no-audit', '--no-fund']);
    Process::assertNotRan(['npm', 'run', 'build']);
});

it('protects the deployment action behind authentication', function () {
    auth()->logout();

    $this->get(route('apps.show', $this->project))
        ->assertRedirect(route('login'));
});
