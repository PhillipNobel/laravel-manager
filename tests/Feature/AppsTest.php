<?php

use App\Enums\DeploymentStatus;
use App\Livewire\Apps\Create;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('shows a helpful empty state on the apps page', function () {
    $this->get(route('apps.index'))
        ->assertOk()
        ->assertSee('No applications yet')
        ->assertSee('Create App');
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

it('shows project details and the empty deployment state', function () {
    $project = Project::query()->create([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.com',
        'branch' => 'main',
        'php_version' => '8.4',
    ]);

    $this->get(route('apps.show', $project))
        ->assertOk()
        ->assertSee('Customer Portal')
        ->assertSee('customer.apps.example.com')
        ->assertSee('No deployments yet')
        ->assertSee('Not provisioned');
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
