<?php

use App\Enums\ProjectStatus;
use App\Livewire\Apps\Create;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use App\Support\GitHubApi;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    config(['manager.manager_url' => 'http://localhost']);
    $this->actingAs(User::factory()->create());
    $this->applicationsRoot = storage_path('framework/testing/new-apps-'.Str::uuid());
    $this->githubToken = 'gho_new_repository_test_token';

    AppSetting::query()->create(['key' => 'applications_directory', 'value' => $this->applicationsRoot]);
    AppSetting::query()->create(['key' => 'base_domain', 'value' => 'apps.example.test']);

    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => $this->githubToken,
        'connected_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.github.com/user/repos')) {
            return Http::response([]);
        }

        if ($request->method() === 'POST' && $request->url() === 'https://api.github.com/user/repos') {
            return Http::response([
                'full_name' => 'octocat/client-portal',
                'private' => true,
                'default_branch' => null,
                'html_url' => 'https://github.com/octocat/client-portal',
                'clone_url' => 'https://github.com/octocat/client-portal.git',
            ], 201);
        }

        return Http::response(['message' => 'Unexpected request'], 500);
    });
});

afterEach(function () {
    if (isset($this->applicationsRoot)) {
        File::deleteDirectory($this->applicationsRoot);
    }
});

it('creates a private Laravel repository from Create App and provisions its main branch', function () {
    $commands = [];
    $pushEnvironment = [];
    $starterPath = null;

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use (&$commands, &$pushEnvironment, &$starterPath) {
        if ($serverCheck = fakeAvailableServerCommand($process)) {
            return $serverCheck;
        }

        $command = $process->command;
        $commands[] = $command;

        if (($command[0] ?? null) === 'composer' && ($command[1] ?? null) === 'create-project') {
            $destination = $command[3];
            $starterPath = $destination;
            File::makeDirectory($destination, 0755, true);
            File::put($destination.'/artisan', "<?php\n");
            File::put($destination.'/composer.json', '{"name":"laravel/laravel"}');
            File::put($destination.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n");
            File::put($destination.'/.gitignore', ".env\n/vendor\n");

            return Process::result(output: 'Starter created.');
        }

        if (($command[0] ?? null) === 'git' && ($command[1] ?? null) === 'push') {
            $pushEnvironment = $process->environment;
        }

        if (($command[0] ?? null) === 'git' && ($command[1] ?? null) === 'check-ref-format') {
            return Process::result(output: 'main');
        }

        if (($command[0] ?? null) === 'git' && ($command[1] ?? null) === 'clone') {
            $destination = end($command);
            if (! is_dir($destination)) {
                File::makeDirectory($destination, 0755, true);
            }
            File::put($destination.'/artisan', "<?php\n");
            File::put($destination.'/composer.json', '{"name":"laravel/laravel"}');
            File::put($destination.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n");
            File::makeDirectory($destination.'/storage', 0755, true);
            File::makeDirectory($destination.'/bootstrap/cache', 0755, true);
            File::makeDirectory($destination.'/.git', 0755, true);
            File::put($destination.'/.git/config', "[remote \"origin\"]\n\turl = https://github.com/octocat/client-portal.git\n");

            return Process::result(output: 'Clone completed.');
        }

        return Process::result();
    });

    $form = Livewire::test(Create::class)
        ->assertSee('No GitHub repositories are available')
        ->assertSee('Create a new private repository')
        ->set('name', 'Client Portal')
        ->set('subdomain', 'client-portal')
        ->set('repositorySource', 'new')
        ->set('branch', 'attacker-branch')
        ->set('phpVersion', '8.3')
        ->set('databaseEngine', 'mysql')
        ->call('save')
        ->assertHasNoErrors();

    runQueuedPublicationForTests();
    $project = Project::query()->sole();

    $form->assertRedirect(route('apps.show', $project));

    expect($project->webhook_status)->toBe('pending')
        ->and($project->automatic_deployment)->toBeFalse()
        ->and($project->status)->toBe(ProjectStatus::Active)
        ->and($project->repository_name)->toBe('octocat/client-portal')
        ->and($project->repository_url)->toBe('https://github.com/octocat/client-portal')
        ->and($project->branch)->toBe('main')
        ->and($project->path)->toBe($this->applicationsRoot.'/client-portal')
        ->and(file_exists($starterPath))->toBeFalse()
        ->and(File::get($this->applicationsRoot.'/client-portal/.env'))->toContain('APP_ENV=production')
        ->and(File::get($this->applicationsRoot.'/client-portal/.git/config'))->not->toContain($this->githubToken)
        ->and($project->provisioning_log)->not->toContain($this->githubToken);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/user/repos'
        && $request->hasHeader('Authorization', 'Bearer '.$this->githubToken)
        && $request['name'] === 'client-portal'
        && $request['private'] === true
        && $request['auto_init'] === false);

    runQueuedPublicationForTests();
    expect(collect($commands)->first(fn (array $command): bool => ($command[0] ?? null) === 'composer'))
        ->toContain('laravel/laravel:^13.0', '--no-install', '--no-scripts', '--no-plugins');

    Process::assertRan(function (PendingProcess $process) use (&$pushEnvironment): bool {
        return $process->command === ['git', 'push', '--set-upstream', 'origin', 'main']
            && ! str_contains(implode(' ', $process->command), 'gho_new_repository_test_token')
            && ($pushEnvironment['GIT_CONFIG_KEY_0'] ?? null) === 'http.https://github.com/.extraheader'
            && ($pushEnvironment['GIT_CONFIG_VALUE_0'] ?? null) === 'AUTHORIZATION: basic '.base64_encode('octocat:gho_new_repository_test_token')
            && ($pushEnvironment['GIT_TERMINAL_PROMPT'] ?? null) === '0';
    });

    Process::assertNotRan(fn (PendingProcess $process): bool => str_contains(implode(' ', $process->command), $this->githubToken));
});

it('rejects a GitHub response that does not describe the requested private repository', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/user/repos' => Http::response([
            'full_name' => 'octocat/another-repository',
            'private' => true,
            'html_url' => 'https://github.com/octocat/another-repository',
            'clone_url' => 'https://github.com/octocat/another-repository.git',
        ], 201),
    ]);

    expect(fn () => app(GitHubApi::class)->createPrivateRepository(
        $this->githubToken,
        'octocat',
        'client-portal',
        'Client Portal',
    ))->toThrow(UnexpectedValueException::class);
});

it('does not show an invalid subdomain as a GitHub repository name', function () {
    Process::fake(fn (PendingProcess $process) => fakeAvailableServerCommand($process) ?? Process::result());

    Livewire::test(Create::class)
        ->set('repositorySource', 'new')
        ->set('subdomain', 'invalid subdomain!')
        ->assertSee('Enter a valid subdomain to preview the repository name.')
        ->assertDontSee('octocat/invalid subdomain!');
});

it('uses the Laravel 12 starter when PHP 8.2 is selected', function () {
    $composerCommand = null;

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use (&$composerCommand) {
        if ($serverCheck = fakeAvailableServerCommand($process)) {
            return $serverCheck;
        }

        $command = $process->command;

        if (($command[0] ?? null) === 'composer') {
            $composerCommand = $command;
            $destination = $command[3];
            File::makeDirectory($destination, 0755, true);
            File::put($destination.'/artisan', "<?php\n");
            File::put($destination.'/composer.json', '{}');
            File::put($destination.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n");
        }

        if (($command[1] ?? null) === 'check-ref-format') {
            return Process::result(output: 'main');
        }

        if (($command[1] ?? null) === 'clone') {
            $destination = end($command);
            File::makeDirectory($destination, 0755, true);
            File::put($destination.'/artisan', "<?php\n");
            File::put($destination.'/composer.json', '{}');
            File::put($destination.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n");
            File::makeDirectory($destination.'/storage', 0755, true);
            File::makeDirectory($destination.'/bootstrap/cache', 0755, true);
            File::makeDirectory($destination.'/.git', 0755, true);
            File::put($destination.'/.git/config', "[remote \"origin\"]\n\turl = https://github.com/octocat/client-portal.git\n");
        }

        return Process::result();
    });

    Livewire::test(Create::class)
        ->set('name', 'Client Portal')
        ->set('subdomain', 'client-portal')
        ->set('repositorySource', 'new')
        ->set('phpVersion', '8.2')
        ->set('databaseEngine', 'mysql')
        ->call('save')
        ->assertHasNoErrors();

    runQueuedPublicationForTests();
    expect($composerCommand)->toContain('laravel/laravel:^12.0');
});

it('preserves the project and shows a preparation failure when GitHub rejects repository creation', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response([]);
        }

        return Http::response(['message' => 'name already exists'], 422);
    });

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        if ($serverCheck = fakeAvailableServerCommand($process)) {
            return $serverCheck;
        }

        if (($process->command[0] ?? null) === 'composer') {
            $destination = $process->command[3];
            File::makeDirectory($destination, 0755, true);
            File::put($destination.'/artisan', "<?php\n");
            File::put($destination.'/composer.json', '{}');
            File::put($destination.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n");
        }

        return Process::result();
    });

    Livewire::test(Create::class)
        ->set('name', 'Client Portal')
        ->set('subdomain', 'client-portal')
        ->set('repositorySource', 'new')
        ->set('phpVersion', '8.3')
        ->set('databaseEngine', 'mysql')
        ->call('save')
        ->assertHasNoErrors();
    runQueuedPublicationForTests();
    expect(Project::query()->sole()->publication_status)->toBe('failed')
        ->and(Project::query()->sole()->provisioning_log)->toContain('GitHub could not confirm repository creation');
});

it('retains a created private repository after an initial push failure without logging process output', function () {
    $encodedAuthorization = base64_encode('octocat:'.$this->githubToken);

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($encodedAuthorization) {
        if ($serverCheck = fakeAvailableServerCommand($process)) {
            return $serverCheck;
        }

        $command = $process->command;

        if (($command[0] ?? null) === 'composer') {
            $destination = $command[3];
            File::makeDirectory($destination, 0755, true);
            File::put($destination.'/artisan', "<?php\n");
            File::put($destination.'/composer.json', '{}');
            File::put($destination.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n");
        }

        if (($command[1] ?? null) === 'push') {
            return Process::result(
                output: 'failed gho_new_repository_test_token',
                errorOutput: 'Authorization: basic '.$encodedAuthorization,
                exitCode: 128,
            );
        }

        return Process::result();
    });

    Livewire::test(Create::class)
        ->set('name', 'Client Portal')
        ->set('subdomain', 'client-portal')
        ->set('repositorySource', 'new')
        ->set('phpVersion', '8.3')
        ->set('databaseEngine', 'mysql')
        ->call('save')
        ->assertHasNoErrors();

    runQueuedPublicationForTests();
    $project = Project::query()->sole();

    expect($project->status)->toBe(ProjectStatus::Failed)
        ->and($project->repository_name)->toBe('octocat/client-portal')
        ->and($project->provisioning_log)->toContain('private GitHub repository was created')
        ->and($project->provisioning_log)->not->toContain($this->githubToken, $encodedAuthorization);
});
