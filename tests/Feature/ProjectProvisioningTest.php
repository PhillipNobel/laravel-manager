<?php

use App\Enums\ProjectStatus;
use App\Livewire\Apps\Create;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->applicationsRoot = storage_path('framework/testing/apps-'.Str::uuid());
    Process::fake(fn (PendingProcess $process) => fakeAvailableServerCommand($process) ?? Process::result());

    AppSetting::query()->create(['key' => 'applications_directory', 'value' => $this->applicationsRoot]);
    AppSetting::query()->create(['key' => 'base_domain', 'value' => 'apps.example.test']);

    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_provisioning_test_token',
        'connected_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'octocat/customer-portal',
                'private' => true,
                'default_branch' => 'stable',
                'html_url' => 'https://github.com/octocat/customer-portal',
                'clone_url' => 'https://github.com/octocat/customer-portal.git',
            ],
        ]),
    ]);
});

afterEach(function () {
    if (isset($this->applicationsRoot)) {
        File::deleteDirectory($this->applicationsRoot);
    }
});

it('shows connected GitHub repositories and adopts selected repository default branch', function () {
    Livewire::test(Create::class)
        ->assertSee('octocat/customer-portal')
        ->set('repositoryName', 'octocat/customer-portal')
        ->assertSet('branch', 'stable');
});

it('clones a selected repository and prepares a secure Laravel environment', function () {
    $statusDuringClone = null;

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use (&$statusDuringClone) {
        if ($serverCheck = fakeAvailableServerCommand($process)) {
            return $serverCheck;
        }

        $command = $process->command;

        if ($command[1] === 'check-ref-format') {
            return Process::result(output: 'stable');
        }

        if ($command[1] === 'clone') {
            $destination = end($command);
            $statusDuringClone = Project::query()->where('slug', 'customer')->firstOrFail()->status;

            if (! is_dir($destination)) {
                File::makeDirectory($destination, 0755, true);
            }
            File::put($destination.'/artisan', "<?php\n");
            File::put($destination.'/composer.json', '{}');
            File::put($destination.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n");
            File::makeDirectory($destination.'/storage', 0755, true);
            File::makeDirectory($destination.'/bootstrap/cache', 0755, true);
            File::makeDirectory($destination.'/.git', 0755, true);
            File::put($destination.'/.git/config', "[remote \"origin\"]\n\turl = https://github.com/octocat/customer-portal.git\n");

            return Process::result(output: 'Clone completed.');
        }

        return Process::result(exitCode: 1);
    });

    $component = Livewire::test(Create::class)
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'octocat/customer-portal')
        ->set('branch', 'stable')
        ->set('phpVersion', '8.4')
        ->set('databaseEngine', 'pgsql')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->sole();
    $component->assertRedirect(route('apps.show', $project));

    $environment = File::get($this->applicationsRoot.'/customer/.env');

    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($project->php_version)->toBe('8.4')
        ->and($project->database_engine->value)->toBe('pgsql')
        ->and($project->domain)->toBe('customer.apps.example.test')
        ->and($project->repository_name)->toBe('octocat/customer-portal')
        ->and($project->repository_url)->toBe('https://github.com/octocat/customer-portal')
        ->and($project->path)->toBe($this->applicationsRoot.'/customer')
        ->and($statusDuringClone)->toBe(ProjectStatus::Provisioning)
        ->and($environment)->toContain('APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=http://customer.apps.example.test')
        ->and($environment)->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m')
        ->and($environment)->not->toContain('gho_provisioning_test_token')
        ->and($project->provisioning_log)->toContain('Clone completed.')
        ->and($project->provisioning_log)->not->toContain('gho_provisioning_test_token')
        ->and(File::get($this->applicationsRoot.'/customer/.git/config'))->not->toContain('gho_provisioning_test_token')
        ->and(fileperms($this->applicationsRoot.'/customer') & 0777)->toBe(0755)
        ->and(fileperms($this->applicationsRoot.'/customer/storage') & 0777)->toBe(0775)
        ->and(fileperms($this->applicationsRoot.'/customer/bootstrap/cache') & 0777)->toBe(0775)
        ->and(fileperms($this->applicationsRoot.'/customer/.env') & 0777)->toBe(0600);

    Process::assertRan(function (PendingProcess $process): bool {
        $command = $process->command;
        $environment = $process->environment;

        return $command[1] === 'clone'
            && ! str_contains(implode(' ', $command), 'gho_provisioning_test_token')
            && $environment['GIT_CONFIG_COUNT'] === '1'
            && $environment['GIT_CONFIG_KEY_0'] === 'http.https://github.com/.extraheader'
            && $environment['GIT_CONFIG_VALUE_0'] === 'AUTHORIZATION: basic '.base64_encode('octocat:gho_provisioning_test_token')
            && $environment['GIT_TERMINAL_PROMPT'] === '0';
    });
});

it('rejects a repository or branch that was not offered by GitHub', function () {
    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => fakeAvailableServerCommand($process) ?? Process::result());

    Livewire::test(Create::class)
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'attacker/other-repository')
        ->set('branch', '--upload-pack=touch')
        ->call('save')
        ->assertHasErrors(['repositoryName', 'branch'])
        ->assertSee('Use letters, numbers, dots, hyphens, or slashes in the branch name.');

    expect(Project::query()->count())->toBe(0);
    Process::assertNotRan(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === 'git');
});

it('verifies that the selected repository is still accessible before creating a project', function () {
    $requestCount = 0;

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/user/repos*' => function () use (&$requestCount) {
            $requestCount++;

            return Http::response($requestCount === 1 ? [
                [
                    'full_name' => 'octocat/customer-portal',
                    'private' => true,
                    'default_branch' => 'stable',
                    'html_url' => 'https://github.com/octocat/customer-portal',
                    'clone_url' => 'https://github.com/octocat/customer-portal.git',
                ],
            ] : [], 200);
        },
    ]);

    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => fakeAvailableServerCommand($process) ?? Process::result());

    Livewire::test(Create::class)
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'octocat/customer-portal')
        ->set('branch', 'stable')
        ->set('phpVersion', '8.4')
        ->call('save')
        ->assertHasErrors('repositoryName');

    expect(Project::query()->count())->toBe(0);
    Process::assertNotRan(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === 'git');
});

it('marks a failed clone failed without logging GitHub credentials', function () {
    $encodedAuthorization = base64_encode('octocat:gho_provisioning_test_token');

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($encodedAuthorization) {
        if ($serverCheck = fakeAvailableServerCommand($process)) {
            return $serverCheck;
        }

        if ($process->command[1] === 'check-ref-format') {
            return Process::result(output: 'stable');
        }

        return Process::result(
            output: 'clone rejected gho_provisioning_test_token',
            errorOutput: 'Authorization: basic '.$encodedAuthorization,
            exitCode: 128,
        );
    });

    Livewire::test(Create::class)
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'octocat/customer-portal')
        ->set('branch', 'stable')
        ->set('phpVersion', '8.4')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->sole();

    expect($project->status)->toBe(ProjectStatus::Failed)
        ->and($project->provisioning_log)->toContain('GitHub clone failed')
        ->and($project->provisioning_log)->not->toContain('gho_provisioning_test_token', $encodedAuthorization);
});

it('uses Git to reject an invalid branch before making the application directory', function () {
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        if ($serverCheck = fakeAvailableServerCommand($process)) {
            return $serverCheck;
        }

        return Process::result(
            errorOutput: 'fatal: invalid branch name',
            exitCode: $process->command[1] === 'check-ref-format' ? 1 : 2,
        );
    });

    Livewire::test(Create::class)
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'octocat/customer-portal')
        ->set('branch', 'release..bad')
        ->set('phpVersion', '8.4')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->sole();

    expect($project->status)->toBe(ProjectStatus::Failed)
        ->and($project->path)->toBeNull()
        ->and($project->provisioning_log)->toContain('The selected Git branch failed validation.');

    Process::assertRan(['git', 'check-ref-format', '--branch', 'release..bad']);
    Process::assertNotRan(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'clone');
});

it('does not overwrite an existing application path', function () {
    $existingPath = $this->applicationsRoot.'/customer';
    File::makeDirectory($existingPath, 0755, true);
    File::put($existingPath.'/keep.txt', 'leave this data untouched');

    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => fakeAvailableServerCommand($process) ?? Process::result());

    Livewire::test(Create::class)
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'octocat/customer-portal')
        ->set('branch', 'stable')
        ->set('phpVersion', '8.4')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->sole();

    expect($project->status)->toBe(ProjectStatus::Failed)
        ->and(File::get($existingPath.'/keep.txt'))->toBe('leave this data untouched');

    Process::assertRan(['git', 'check-ref-format', '--branch', 'stable']);
});

it('does not follow an existing application path symlink', function () {
    $targetPath = $this->applicationsRoot.'/existing-target';
    $linkPath = $this->applicationsRoot.'/customer';
    File::makeDirectory($targetPath, 0755, true);
    File::put($targetPath.'/keep.txt', 'leave the symlink target untouched');
    symlink($targetPath, $linkPath);

    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => fakeAvailableServerCommand($process) ?? Process::result());

    Livewire::test(Create::class)
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'octocat/customer-portal')
        ->set('branch', 'stable')
        ->set('phpVersion', '8.4')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->sole();

    expect($project->status)->toBe(ProjectStatus::Failed)
        ->and(File::get($targetPath.'/keep.txt'))->toBe('leave the symlink target untouched')
        ->and(is_link($linkPath))->toBeTrue();

    File::delete($linkPath);
    Process::assertRan(['git', 'check-ref-format', '--branch', 'stable']);
});

it('marks a repository without Laravel files as failed', function () {
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) {
        if ($serverCheck = fakeAvailableServerCommand($process)) {
            return $serverCheck;
        }

        if ($process->command[1] === 'check-ref-format') {
            return Process::result(output: 'stable');
        }

        $destination = end($process->command);
        if (! is_dir($destination)) {
            File::makeDirectory($destination, 0755, true);
        }
        File::put($destination.'/README.md', 'This is not a Laravel app.');

        return Process::result(output: 'Clone completed.');
    });

    Livewire::test(Create::class)
        ->set('name', 'Customer Portal')
        ->set('subdomain', 'customer')
        ->set('repositoryName', 'octocat/customer-portal')
        ->set('branch', 'stable')
        ->set('phpVersion', '8.4')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->sole();

    expect($project->status)->toBe(ProjectStatus::Failed)
        ->and($project->provisioning_log)->toContain('missing required Laravel files');
});

it('shows the provisioning log on the project detail page', function () {
    $project = Project::query()->create([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.test',
        'branch' => 'stable',
        'status' => ProjectStatus::Failed,
        'provisioning_log' => "Clone started.\nGitHub clone failed.",
    ]);

    $this->get(route('apps.show', $project))
        ->assertOk()
        ->assertSee('Provisioning log')
        ->assertSee('Clone started.')
        ->assertSee('GitHub clone failed.');
});
