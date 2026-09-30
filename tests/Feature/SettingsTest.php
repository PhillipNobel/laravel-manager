<?php

use App\Livewire\Settings\Index;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('shows the configured server settings and disconnected GitHub state', function () {
    $this->get(route('settings'))
        ->assertOk()
        ->assertSee('GitHub')
        ->assertSee('Not connected');

    Livewire::test(Index::class)
        ->assertSet('serverHostname', config('manager.server_hostname'))
        ->assertSet('publicIp', '')
        ->assertSet('baseDomain', 'apps.example.com')
        ->assertSet('applicationsDirectory', '/var/www/apps')
        ->assertSet('defaultPhpVersion', '8.4')
        ->assertSet('managerUrl', config('app.url'));
});

it('shows connection details and repository actions without exposing the token', function () {
    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_test_secret',
        'connected_at' => now(),
    ]);

    $this->get(route('settings'))
        ->assertOk()
        ->assertSee('Connected as octocat')
        ->assertSee('Browse repositories')
        ->assertSee('Disconnect')
        ->assertDontSee('gho_test_secret');
});

it('shows a connect action only when OAuth credentials are configured', function () {
    Config::set('services.github.client_id', 'manager-client-id');
    Config::set('services.github.client_secret', 'manager-client-secret');

    $this->get(route('settings'))
        ->assertOk()
        ->assertSee('Not connected')
        ->assertSee('Connect GitHub');

    Config::set('services.github.client_id', null);
    Config::set('services.github.client_secret', null);

    $this->get(route('settings'))
        ->assertOk()
        ->assertDontSee('Connect GitHub')
        ->assertSee('GITHUB_CLIENT_ID')
        ->assertSee('GITHUB_CLIENT_SECRET');
});

it('saves server settings for new projects', function () {
    Livewire::test(Index::class)
        ->set('serverHostname', 'vps-01.example.com')
        ->set('publicIp', '203.0.113.10')
        ->set('baseDomain', 'apps.example.test')
        ->set('applicationsDirectory', '/srv/laravel-apps')
        ->set('defaultPhpVersion', '8.2')
        ->set('managerUrl', 'https://manager.example.com')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Settings saved.');

    expect(AppSetting::valueFor('server_hostname'))->toBe('vps-01.example.com')
        ->and(AppSetting::valueFor('public_ip'))->toBe('203.0.113.10')
        ->and(AppSetting::valueFor('base_domain'))->toBe('apps.example.test')
        ->and(AppSetting::valueFor('applications_directory'))->toBe('/srv/laravel-apps')
        ->and(AppSetting::valueFor('default_php_version'))->toBe('8.2')
        ->and(AppSetting::valueFor('manager_url'))->toBe('https://manager.example.com');
});

it('normalizes a trailing slash in the applications directory', function () {
    Livewire::test(Index::class)
        ->set('applicationsDirectory', '/srv/laravel-apps/')
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::valueFor('applications_directory'))->toBe('/srv/laravel-apps');
});

it('rejects unsafe applications directory path segments', function () {
    Livewire::test(Index::class)
        ->set('applicationsDirectory', '/srv/laravel-apps/../outside')
        ->call('save')
        ->assertHasErrors('applicationsDirectory')
        ->assertSee('Use an absolute applications directory');

    expect(AppSetting::query()->count())->toBe(0);
});

it('allows the public IP to remain unconfigured', function () {
    Livewire::test(Index::class)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Settings saved.');

    expect(AppSetting::valueFor('public_ip'))->toBe('');
});

it('rejects invalid server settings', function () {
    Livewire::test(Index::class)
        ->set('serverHostname', '')
        ->set('publicIp', 'not-an-ip')
        ->set('baseDomain', 'https://apps.example.com')
        ->set('applicationsDirectory', 'var/www/apps')
        ->set('defaultPhpVersion', '9.9')
        ->set('managerUrl', 'manager.example.com')
        ->call('save')
        ->assertHasErrors([
            'serverHostname',
            'publicIp',
            'baseDomain',
            'applicationsDirectory',
            'defaultPhpVersion',
            'managerUrl',
        ]);

    expect(AppSetting::query()->count())->toBe(0);
});

it('clears a setting validation error after the value is corrected', function () {
    Livewire::test(Index::class)
        ->set('publicIp', 'not-an-ip')
        ->set('baseDomain', 'https://apps.example.com')
        ->call('save')
        ->assertHasErrors(['publicIp', 'baseDomain'])
        ->set('publicIp', '203.0.113.10')
        ->assertHasNoErrors('publicIp')
        ->set('baseDomain', 'apps.example.com')
        ->assertHasNoErrors('baseDomain');
});
