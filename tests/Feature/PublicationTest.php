<?php

use App\Actions\Projects\ConfigureProjectDatabase;
use App\Actions\Projects\ConfigureProjectDomain;
use App\Actions\Projects\ConfigureProjectSsl;
use App\Actions\Projects\QueueProjectDeployment;
use App\Actions\Projects\QueueProjectPublication;
use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Enums\SslStatus;
use App\Jobs\PublishProject;
use App\Livewire\Apps\Create;
use App\Livewire\Apps\Show;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Queue::fake();
    Process::preventStrayProcesses();
    Process::fake(function ($process) {
        if ($result = fakeAvailableServerCommand($process)) {
            return $result;
        }
        $command = $process->command;
        if (array_slice($command, 0, 3) === ['git', 'remote', 'get-url']) {
            return Process::result(output: 'https://github.com/octocat/portal.git');
        }
        if (($command[1] ?? '') === 'rev-parse') {
            return Process::result(output: str_repeat('a', 40));
        }
        if (($command[1] ?? '') === 'show') {
            return Process::result(output: 'Initial version');
        }

        return Process::result();
    });
    Http::preventStrayRequests();
    Http::fake(['http://127.0.0.1/' => Http::response('Laravel', 200)]);
    $this->root = storage_path('framework/testing/publication-'.Str::uuid());
    $path = $this->root.'/portal';
    File::ensureDirectoryExists($path.'/.git');
    File::put($path.'/.git/config', '');
    File::put($path.'/artisan', '<?php');
    File::put($path.'/composer.json', '{}');
    File::put($path.'/.env', "APP_KEY=local-test-key\nDB_PASSWORD=retained-test-password\nAPP_URL=http://portal.apps.example.test\n");
    chmod($path.'/.env', 0600);
    AppSetting::create(['key' => 'applications_directory', 'value' => $this->root]);
    AppSetting::create(['key' => 'base_domain', 'value' => 'apps.example.test']);
    GitHubConnection::create(['github_user_id' => 1, 'login' => 'octocat', 'access_token' => 'fake-test-token', 'connected_at' => now()]);
    $this->project = Project::create(['name' => 'Portal', 'slug' => 'portal', 'domain' => 'portal.apps.example.test', 'path' => $path,
        'repository_name' => 'octocat/portal', 'repository_url' => 'https://github.com/octocat/portal', 'branch' => 'main',
        'php_version' => '8.3', 'database_engine' => 'mysql', 'status' => ProjectStatus::Active]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function fakePublicationInfrastructure(array &$steps, bool $sslFails = false): void
{
    test()->mock(ConfigureProjectDatabase::class)->shouldReceive('handle')->andReturnUsing(function ($project) use (&$steps) {
        $steps[] = 'database';
        $project->update(['database_status' => DatabaseStatus::Active]);

        return DatabaseStatus::Active;
    });
    test()->mock(ConfigureProjectDomain::class)->shouldReceive('handle')->andReturnUsing(function ($project) use (&$steps) {
        $steps[] = 'domain';
        $project->update(['domain_status' => DomainStatus::Active]);

        return DomainStatus::Active;
    });
    test()->mock(ConfigureProjectSsl::class)->shouldReceive('enable')->andReturnUsing(function ($project) use (&$steps, $sslFails) {
        $steps[] = 'ssl';
        $status = $sslFails ? SslStatus::Failed : SslStatus::Active;
        $project->update(['ssl_status' => $status]);

        return $status;
    });
}

it('publishes in ordered queued stages and preserves secrets while switching the final URL to HTTPS', function () {
    $steps = [];
    fakePublicationInfrastructure($steps);
    app(QueueProjectPublication::class)->handle($this->project, $this->user->id);
    Queue::assertPushed(PublishProject::class, fn ($job) => $job->projectId === $this->project->id && $job->connection === 'database'
        && ! str_contains(serialize($job), 'fake-test-token'));
    for ($i = 0; $i < 7; $i++) {
        $project = $this->project->fresh();
        expect($project->publication_step)->toBe(array_keys(PublishProject::STEPS)[$i]);
        (new PublishProject($project->id, $this->user->id, $project->publication_version))->handle();
    }
    expect($steps)->toBe(['database', 'domain', 'ssl'])
        ->and($this->project->fresh()->publication_status)->toBe('ready')
        ->and($this->project->deployments()->sole()->status)->toBe(DeploymentStatus::Successful)
        ->and(File::get($this->project->path.'/.env'))->toContain('APP_KEY=local-test-key', 'DB_PASSWORD=retained-test-password', 'APP_URL=https://portal.apps.example.test')
        ->and(fileperms($this->project->path.'/.env') & 0777)->toBe(0600);
    Http::assertSent(fn ($r) => $r->url() === 'http://127.0.0.1/' && $r->hasHeader('Host', 'portal.apps.example.test'));
    Livewire::test(Show::class, ['project' => $this->project])->assertSee('Update app')->assertSee('Manual updates');
});

it('preserves HTTP publication on certificate failure and retries only the unfinished SSL step', function () {
    $steps = [];
    fakePublicationInfrastructure($steps, true);
    app(QueueProjectPublication::class)->handle($this->project, $this->user->id);
    runQueuedPublicationForTests();
    expect($this->project->fresh()->publication_status)->toBe('failed')->and($this->project->fresh()->publication_step)->toBe('ssl')
        ->and($this->project->fresh()->domain_status)->toBe(DomainStatus::Active)
        ->and($this->project->deployments()->count())->toBe(1);
    $this->project->update(['ssl_status' => SslStatus::Active]);
    app(QueueProjectPublication::class)->handle($this->project, $this->user->id);
    runQueuedPublicationForTests();
    expect($this->project->fresh()->publication_status)->toBe('ready')->and($steps)->toBe(['database', 'domain', 'ssl'])
        ->and($this->project->deployments()->count())->toBe(1);
});

it('ignores stale or duplicate preparation jobs and rejects duplicate preparation requests', function () {
    app(QueueProjectPublication::class)->handle($this->project, $this->user->id);
    expect(fn () => app(QueueProjectPublication::class)->handle($this->project, $this->user->id))->toThrow(ValidationException::class);
    (new PublishProject($this->project->id, $this->user->id, 0))->handle();
    expect($this->project->fresh()->publication_status)->toBe('queued');
    $this->project->update(['publication_status' => 'running']);
    (new PublishProject($this->project->id, $this->user->id, 1))->handle();
    Process::assertNothingRan();
    Http::assertNothingSent();
});

it('queues Create App without running Composer Git or server helpers in the HTTP request', function () {
    Http::fake(['https://api.github.com/user/repos*' => Http::response([])]);
    Livewire::test(Create::class)->set('name', 'New app')->set('subdomain', 'new-app')->set('repositorySource', 'new')
        ->set('phpVersion', '8.3')->set('databaseEngine', 'mysql')->call('save')->assertHasNoErrors();
    $created = Project::where('slug', 'new-app')->sole();
    expect($created->branch)->toBe('main')->and($created->automatic_deployment)->toBeFalse()
        ->and($created->publication_status)->toBe('queued');
    Queue::assertPushed(PublishProject::class);
    Process::assertNotRan(fn ($p) => in_array($p->command[0] ?? '', ['composer', 'git', '/usr/bin/sudo'], true));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/hooks'));
});

it('rejects publication and preference changes while a Manager update is queued', function () {
    $gate = $this->root.'/gate';
    $state = $this->root.'/state';
    touch($gate);
    File::put($state, '{"state":"queued"}');
    config(['manager.update_lock' => $gate, 'manager.update_state' => $state]);
    expect(fn () => app(QueueProjectPublication::class)->handle($this->project, $this->user->id))->toThrow(ValidationException::class);
    Livewire::test(Show::class, ['project' => $this->project])->call('setDeploymentMode', 'automatic')->assertHasErrors('managerUpdate');
    expect($this->project->fresh()->automatic_deployment)->toBeFalse();
});

it('blocks updates and privileged setup controls while initial publication is running', function () {
    $this->project->update(['database_status' => DatabaseStatus::Active, 'publication_status' => 'queued']);
    Livewire::test(Show::class, ['project' => $this->project])->call('deploy')->assertHasErrors('deployment')
        ->call('configureDomain')->assertHasErrors('publication');
    Queue::assertNothingPushed();
    Process::assertNothingRan();
    $this->artisan('manager:update-ready')->assertExitCode(1);
});

it('rejects creation in production when the fixed database queue worker is unavailable', function () {
    app()->instance('env', 'production');
    Http::fake(['https://api.github.com/user/repos*' => Http::response([])]);
    Process::fake(function ($process) {
        if ($process->command === ['/usr/bin/systemctl', 'is-active', '--quiet', 'laravel-manager-queue.service']) {
            return Process::result(exitCode: 3);
        }

        return fakeAvailableServerCommand($process) ?? Process::result();
    });
    try {
        Livewire::test(Create::class)->set('name', 'Blocked app')->set('subdomain', 'blocked-app')->set('repositorySource', 'new')
            ->set('phpVersion', '8.3')->set('databaseEngine', 'mysql')->call('save')->assertHasErrors('repositorySource');
        expect(Project::where('slug', 'blocked-app')->exists())->toBeFalse();
        Queue::assertNothingPushed();
    } finally {
        app()->instance('env', 'testing');
    }
});

it('marks interrupted initial deployments failed so preparation can safely resume', function () {
    $deployment = $this->project->deployments()->create(['status' => DeploymentStatus::Running]);
    $this->project->update(['publication_status' => 'running', 'publication_step' => 'deployment',
        'publication_version' => 3, 'publication_deployment_id' => $deployment->id]);
    (new PublishProject($this->project->id, $this->user->id, 3))->failed(new RuntimeException('private diagnostic'));
    expect($this->project->fresh()->publication_status)->toBe('failed')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->output)->not->toContain('private diagnostic');
    app(QueueProjectPublication::class)->handle($this->project, $this->user->id);
    expect($this->project->fresh()->publication_step)->toBe('deployment')->and($this->project->fresh()->publication_version)->toBe(4);
});

it('validates the manual versus automatic preference and immediately disables webhook deployment', function () {
    $this->project->update(['automatic_deployment' => true, 'database_status' => DatabaseStatus::Active]);
    Livewire::test(Show::class, ['project' => $this->project])->call('setDeploymentMode', 'invalid')->assertHasErrors('deploymentMode');
    Livewire::test(Show::class, ['project' => $this->project])->call('setDeploymentMode', 'manual')->assertHasNoErrors();
    expect($this->project->fresh()->automatic_deployment)->toBeFalse();
    expect(fn () => app(QueueProjectDeployment::class)->handle($this->project, true, true))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('backfills existing projects to automatic while keeping the database default manual for new projects', function () {
    $migration = require database_path('migrations/2026_09_30_130000_add_publication_fields_to_projects.php');
    $migration->down();
    $migration->up();
    expect($this->project->fresh()->automatic_deployment)->toBeTrue();
    $new = $this->project->replicate();
    $new->slug = 'second';
    $new->domain = 'second.apps.example.test';
    unset($new->automatic_deployment);
    $new->save();
    expect($new->fresh()->automatic_deployment)->toBeFalse();
});
