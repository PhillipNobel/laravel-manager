<?php

use App\Enums\DatabaseStatus;
use App\Enums\ProjectStatus;
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
    $this->applicationsRoot = storage_path('framework/testing/databases-'.Str::uuid());
    $this->projectPath = $this->applicationsRoot.'/customer';
    File::makeDirectory($this->projectPath, 0755, true);
    File::put($this->projectPath.'/artisan', "<?php\n");
    File::put($this->projectPath.'/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite\n");
    chmod($this->projectPath.'/.env', 0600);
    AppSetting::query()->create(['key' => 'applications_directory', 'value' => $this->applicationsRoot]);

    $this->project = Project::query()->create([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.test',
        'branch' => 'main',
        'path' => $this->projectPath,
        'status' => ProjectStatus::Active,
    ]);

    $this->admin = User::factory()->create();
});

afterEach(function () {
    if (isset($this->applicationsRoot)) {
        File::deleteDirectory($this->applicationsRoot);
    }
});

it('lets an administrator provision a project database without displaying its password', function () {
    $projectPath = $this->projectPath;

    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($projectPath) {
        File::put($projectPath.'/.env', "DB_CONNECTION=mysql\nDB_URL=null\nDB_HOST=localhost\nDB_PORT=3306\nDB_SOCKET=/var/run/mysqld/mysqld.sock\nDB_DATABASE=lm_customer_b6c45863\nDB_USERNAME=lm_customer_b6c45863\nDB_PASSWORD=show-this-password\n");

        return Process::result(output: 'READY');
    });

    Livewire::actingAs($this->admin)
        ->test(Show::class, ['project' => $this->project])
        ->call('provisionDatabase')
        ->assertHasNoErrors()
        ->assertSee('Application database')
        ->assertSee('Database is ready.')
        ->assertSee('Active')
        ->assertSee('The database password is stored in the application environment file and is not shown here.')
        ->assertDontSee('show-this-password');

    $project = $this->project->fresh();

    expect($project->database_status)->toBe(DatabaseStatus::Active)
        ->and($project->database_name)->toMatch('/\Alm_customer_[a-f0-9]{8}\z/')
        ->and($project->database_username)->toMatch('/\Alm_customer_[a-f0-9]{8}\z/')
        ->and(File::get($this->projectPath.'/.env'))->toContain('DB_CONNECTION=mysql', 'DB_DATABASE='.$project->database_name, 'DB_USERNAME='.$project->database_username, 'DB_PASSWORD=show-this-password');

    Process::assertRan(function (PendingProcess $process): bool {
        return $process->command === [
            '/usr/bin/sudo',
            '-n',
            '/usr/local/sbin/laravel-manager-database',
            'provision',
            'customer',
        ] && $process->timeout === 120;
    });
});

it('records database failures without exposing helper diagnostics', function () {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result(errorOutput: 'mysql admin password: show-this-password', exitCode: 1)]);

    Livewire::actingAs($this->admin)
        ->test(Show::class, ['project' => $this->project])
        ->call('provisionDatabase')
        ->assertSee('The database could not be created. Check the Laravel Manager log, then retry.')
        ->assertDontSee('show-this-password');

    expect($this->project->fresh()->database_status)->toBe(DatabaseStatus::Failed);
});

it('shows a safe recovery message for known MySQL setup failures', function () {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result(errorOutput: 'ERROR: MYSQL_UNAVAILABLE', exitCode: 1)]);

    Livewire::actingAs($this->admin)
        ->test(Show::class, ['project' => $this->project])
        ->call('provisionDatabase')
        ->assertSee('Laravel Manager could not access MySQL. Check MySQL and its local socket configuration, then retry.');
});

it('requires the application environment file to be ignored by Git', function () {
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result(errorOutput: 'ERROR: ENV_NOT_IGNORED', exitCode: 1)]);

    Livewire::actingAs($this->admin)
        ->test(Show::class, ['project' => $this->project])
        ->call('provisionDatabase')
        ->assertSee('The repository does not ignore its .env file. Add .env to .gitignore before creating a database.');
});

it('does not provision a database before the application is active', function () {
    $this->project->update(['status' => ProjectStatus::Pending]);
    Process::preventStrayProcesses();
    Process::fake();

    Livewire::actingAs($this->admin)
        ->test(Show::class, ['project' => $this->project])
        ->call('provisionDatabase')
        ->assertSee('Finish application provisioning before creating its database.');

    expect($this->project->fresh()->database_status)->toBe(DatabaseStatus::Pending);
    Process::assertNothingRan();
});

it('refuses to provision a database outside the configured applications directory', function () {
    $this->project->update(['path' => storage_path('outside-run06-project')]);
    Process::preventStrayProcesses();
    Process::fake();

    Livewire::actingAs($this->admin)
        ->test(Show::class, ['project' => $this->project])
        ->call('provisionDatabase')
        ->assertSee('The application directory is missing or outside the configured applications directory.');

    expect($this->project->fresh()->database_status)->toBe(DatabaseStatus::Failed);
    Process::assertNothingRan();
});

it('protects the database provisioning action behind authentication', function () {
    $this->get(route('apps.show', $this->project))
        ->assertRedirect(route('login'));
});

it('rejects shell metacharacters at the database helper command boundary', function () {
    $process = new SymfonyProcess([
        'python3',
        base_path('scripts/laravel-manager-database'),
        'provision',
        'customer;touch-pwned',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('ERROR: INVALID_PROJECT');
});
