<?php

use App\Actions\Projects\ConfigureProjectWebhook;
use App\Enums\ProjectStatus;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use App\Support\LocalDevelopmentGuide;
use App\Support\WebhookSecret;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['services.github.webhook_secret' => null, 'manager.manager_url' => 'https://manager.example.com']);
    Http::preventStrayRequests();
    Queue::fake();
    $this->project = Project::create(['name' => 'Portal', 'slug' => 'portal', 'domain' => 'portal.example.com',
        'repository_name' => 'octocat/portal', 'repository_url' => 'https://github.com/octocat/portal',
        'branch' => 'main', 'php_version' => '8.3', 'status' => ProjectStatus::Active]);
    GitHubConnection::create(['github_user_id' => 1, 'login' => 'octocat', 'access_token' => 'test-token', 'connected_at' => now()]);
});

function run20Hook(): array
{
    return ['id' => 42, 'name' => 'web', 'active' => true, 'events' => ['push'],
        'config' => ['url' => 'https://manager.example.com/webhooks/github', 'content_type' => 'json', 'insecure_ssl' => '0']];
}

function run20FakeHooks(array $hooks = []): void
{
    Http::fake(function ($request) use ($hooks) {
        if (str_contains($request->url(), '/pings')) {
            return Http::response(null, 204);
        }
        if (str_contains($request->url(), '/hooks')) {
            return Http::response($request->method() === 'GET' ? $hooks : run20Hook(), 200);
        }

        return Http::response(['full_name' => 'octocat/portal', 'permissions' => ['admin' => true]]);
    });
}

it('creates an encrypted persistent secret and a push-only TLS-verified hook without marking delivery verified', function () {
    run20FakeHooks();
    app(ConfigureProjectWebhook::class)->handle($this->project);
    expect($this->project->fresh()->webhook_status)->toBe('configured')
        ->and($this->project->fresh()->webhook_verified_at)->toBeNull();
    $secret = WebhookSecret::read();
    expect(strlen($secret))->toBe(64)->and(WebhookSecret::ensure())->toBe($secret);
    expect(AppSetting::where('key', 'github_webhook_secret_encrypted')->value('value'))->not->toBe($secret);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/hooks')
        && $r['events'] === ['push'] && $r['config']['secret'] === $secret && $r['config']['insecure_ssl'] === '0');
});

it('reuses matching hooks and preserves unrelated ones', function () {
    run20FakeHooks([run20Hook(), ['id' => 99, 'config' => ['url' => 'https://other.example.com/hook']]]);
    app(ConfigureProjectWebhook::class)->handle($this->project);
    app(ConfigureProjectWebhook::class)->handle($this->project);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/hooks'));
    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/hooks/42'));
});

it('rejects conflicts without changing their events', function () {
    $hook = run20Hook();
    $hook['events'] = ['push', 'issues'];
    run20FakeHooks([$hook]);
    app(ConfigureProjectWebhook::class)->handle($this->project);
    expect($this->project->fresh()->webhook_status)->toBe('failed');
    Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
});

it('rejects nonpublic callback URLs without making network requests', function ($url) {
    config(['manager.manager_url' => $url]);
    app(ConfigureProjectWebhook::class)->handle($this->project);
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($this->project->fresh()->webhook_status)->toBe('failed');
    Http::assertNothingSent();
})->with(['http://manager.example.com', 'https://127.0.0.1', 'https://localhost', 'https://x.local', 'https://user:pass@example.com', 'https://example.com/path', 'https://example.com?foo=1']);

it('requires repository administration and retains the provisioned project', function () {
    Http::fake(['*' => Http::response(['full_name' => 'octocat/portal', 'permissions' => ['admin' => false]])]);
    app(ConfigureProjectWebhook::class)->handle($this->project);
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($this->project->fresh()->webhook_message)->toContain('administrator');
});

it('serializes repository configuration including projects with different branches', function () {
    $lock = Cache::lock('github-hook:'.hash('sha256', 'octocat/portal'), 180);
    $lock->get();
    try {
        app(ConfigureProjectWebhook::class)->handle($this->project);
        Http::assertNothingSent();
        expect($this->project->fresh()->webhook_message)->toContain('already running');
    } finally {
        $lock->release();
    }
});

it('preserves the legacy configured secret and does not create a replacement', function () {
    config(['services.github.webhook_secret' => 'legacy-secret']);
    expect(WebhookSecret::ensure())->toBe('legacy-secret');
    expect(AppSetting::where('key', 'github_webhook_secret_encrypted')->exists())->toBeFalse();
});

it('verifies only matching signed pings without deploying', function () {
    run20FakeHooks();
    app(ConfigureProjectWebhook::class)->handle($this->project);
    $body = json_encode(['hook_id' => 42, 'hook' => run20Hook(), 'repository' => ['full_name' => 'octocat/portal']]);
    $this->call('POST', '/webhooks/github', [], [], [], ['CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'ping', 'HTTP_X_GITHUB_DELIVERY' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, WebhookSecret::read())], $body)
        ->assertStatus(202)->assertJson(['status' => 'verified', 'deployments_queued' => 0]);
    expect($this->project->fresh()->webhook_verified_at)->not->toBeNull();
    Queue::assertNothingPushed();
});

it('builds canonical selectable local commands and rejects unsafe metadata', function () {
    $commands = LocalDevelopmentGuide::commands($this->project);
    expect(implode("\n", $commands))->toContain('https://github.com/octocat/portal.git', "'main'", 'touch database/database.sqlite')
        ->not->toContain('test-token');
    $this->project->branch = 'main; curl bad';
    expect(LocalDevelopmentGuide::commands($this->project))->toBe([]);
});

it('reconciles a lost creation response before retrying without a second create', function () {
    $created = false;
    $posts = 0;
    Http::fake(function ($request) use (&$created, &$posts) {
        if (str_contains($request->url(), '/pings')) {
            return Http::response(null, 204);
        }
        if (str_contains($request->url(), '/hooks')) {
            if ($request->method() === 'GET') {
                return Http::response($created ? [run20Hook()] : []);
            }
            if ($request->method() === 'POST') {
                $posts++;
                $created = true;
                throw new ConnectionException('Sensitive network diagnostics');
            }

            return Http::response(run20Hook());
        }

        return Http::response(['full_name' => 'octocat/portal', 'permissions' => ['admin' => true]]);
    });
    app(ConfigureProjectWebhook::class)->handle($this->project);
    expect($posts)->toBe(1)->and($this->project->fresh()->webhook_status)->toBe('configured');
});

it('repairs a recorded hook after the Manager URL changes and shares it across branches', function () {
    $this->project->update(['webhook_id' => 42, 'webhook_url' => 'https://old.example.com/webhooks/github', 'webhook_verified_at' => now()]);
    $second = $this->project->replicate();
    $second->slug = 'portal-two';
    $second->domain = 'two.example.com';
    $second->branch = 'release';
    $second->save();
    $hook = run20Hook();
    $hook['config']['url'] = 'https://old.example.com/webhooks/github';
    run20FakeHooks([$hook]);
    app(ConfigureProjectWebhook::class)->handle($this->project);
    expect($second->fresh()->webhook_url)->toBe('https://manager.example.com/webhooks/github')
        ->and($second->fresh()->webhook_id)->toBe(42)->and($second->fresh()->webhook_verified_at)->toBeNull();
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/hooks'));
});

it('handles rate limiting with a safe summary without deleting or failing provisioning', function () {
    Http::fake(['*' => Http::response(['message' => 'secret-token'], 429)]);
    app(ConfigureProjectWebhook::class)->handle($this->project);
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($this->project->fresh()->webhook_message)->not->toContain('secret-token');
});

it('does not verify mismatched or unsigned pings', function ($signed, $id) {
    $this->project->update(['webhook_id' => 42, 'webhook_url' => run20Hook()['config']['url']]);
    $secret = WebhookSecret::ensure();
    $body = json_encode(['hook_id' => $id, 'hook' => array_replace(run20Hook(), ['id' => $id]), 'repository' => ['full_name' => 'octocat/portal']]);
    $response = $this->call('POST', '/webhooks/github', [], [], [], ['CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'ping', 'HTTP_X_GITHUB_DELIVERY' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $signed ? $secret : 'wrong-secret')], $body);
    $response->assertStatus($signed ? 202 : 401);
    expect($this->project->fresh()->webhook_verified_at)->toBeNull();
    Queue::assertNothingPushed();
})->with([[true, 99], [false, 42]]);

it('does not expose the encrypted fallback secret through serialization or reconnects', function () {
    $secret = WebhookSecret::ensure();
    GitHubConnection::query()->delete();
    expect(WebhookSecret::read())->toBe($secret)
        ->and(AppSetting::where('key', 'github_webhook_secret_encrypted')->first()->toArray())->not->toHaveKey('value');
});

it('shows readable commands, local environment guidance and copy fallback on the protected Project page', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('apps.show', $this->project))->assertOk()->assertSee('Develop locally')
        ->assertSee('Copy commands')->assertSee('Commands selected.')->assertSee('DB_CONNECTION=sqlite')
        ->assertSee('Automatic deployment')->assertDontSee('test-token');
});

it('blocks webhook configuration while a Manager update is queued', function () {
    $directory = sys_get_temp_dir().'/run20-gate-'.uniqid();
    mkdir($directory);
    touch($directory.'/gate');
    file_put_contents($directory.'/state', '{"state":"queued"}');
    config(['manager.update_lock' => $directory.'/gate', 'manager.update_state' => $directory.'/state']);
    try {
        expect(fn () => app(ConfigureProjectWebhook::class)->handle($this->project))->toThrow(ValidationException::class);
        Http::assertNothingSent();
    } finally {
        unlink($directory.'/gate');
        unlink($directory.'/state');
        rmdir($directory);
    }
});
