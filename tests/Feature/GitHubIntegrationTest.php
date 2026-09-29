<?php

use App\Models\GitHubConnection;
use App\Models\User;
use App\Support\GitHubApi;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('starts a protected GitHub OAuth flow with state and PKCE', function () {
    Config::set('services.github.client_id', 'manager-client-id');
    Config::set('services.github.client_secret', 'manager-client-secret');

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.github.connect'));

    $response->assertRedirect();

    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('manager-client-id')
        ->and($query['redirect_uri'])->toBe(route('settings.github.callback'))
        ->and($query['scope'])->toBe('repo')
        ->and($query['state'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($query['code_challenge_method'])->toBe('S256');

    $response->assertSessionHas('github_oauth.state', $query['state']);
    $response->assertSessionHas('github_oauth.code_verifier', function (string $verifier) use ($query): bool {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($query['code_challenge'], $challenge);
    });
});

it('protects GitHub connection routes from guests', function () {
    $this->get(route('settings.github.connect'))->assertRedirect(route('login'));
    $this->get(route('settings.github.callback'))->assertRedirect(route('login'));
});

it('rejects a GitHub callback with an invalid state before making a request', function () {
    Http::preventStrayRequests();

    $this->actingAs(User::factory()->create())
        ->withSession([
            'github_oauth' => [
                'state' => 'expected-state',
                'code_verifier' => 'stored-code-verifier',
            ],
        ])
        ->get(route('settings.github.callback', [
            'code' => 'temporary-code',
            'state' => 'wrong-state',
        ]))
        ->assertRedirect(route('settings'))
        ->assertSessionHas('error', 'GitHub authorization could not be verified. Please try connecting again.');
});

it('validates and securely stores a GitHub account connection', function () {
    Config::set('services.github.client_id', 'manager-client-id');
    Config::set('services.github.client_secret', 'manager-client-secret');

    Http::preventStrayRequests();
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response([
            'access_token' => 'gho_test_secret',
            'token_type' => 'bearer',
            'scope' => 'repo',
        ]),
        'https://api.github.com/user' => Http::response([
            'id' => 583231,
            'login' => 'octocat',
            'name' => 'The Octocat',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/583231',
        ]),
    ]);

    $this->actingAs(User::factory()->create())
        ->withSession([
            'github_oauth' => [
                'state' => 'state-123',
                'code_verifier' => 'verifier-123',
            ],
        ])
        ->get(route('settings.github.callback', [
            'code' => 'temporary-code',
            'state' => 'state-123',
        ]))
        ->assertRedirect(route('settings'))
        ->assertSessionHas('status', 'Connected to GitHub as octocat.');

    $connection = GitHubConnection::query()->firstOrFail();

    expect($connection->login)->toBe('octocat')
        ->and($connection->name)->toBe('The Octocat')
        ->and($connection->access_token)->toBe('gho_test_secret')
        ->and(DB::table('github_connections')->value('access_token'))->not->toBe('gho_test_secret');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://github.com/login/oauth/access_token'
        && $request['client_secret'] === 'manager-client-secret'
        && $request['code'] === 'temporary-code'
        && $request['code_verifier'] === 'verifier-123');
});

it('explains when GitHub OAuth credentials are not configured', function () {
    Config::set('services.github.client_id', null);
    Config::set('services.github.client_secret', null);

    $this->actingAs(User::factory()->create())
        ->get(route('settings.github.connect'))
        ->assertRedirect(route('settings'))
        ->assertSessionHas('error', 'Configure GitHub OAuth credentials before connecting.');
});

it('handles canceled GitHub authorization without making an API request', function () {
    Http::preventStrayRequests();

    $this->actingAs(User::factory()->create())
        ->withSession([
            'github_oauth' => [
                'state' => 'state-123',
                'code_verifier' => 'verifier-123',
            ],
        ])
        ->get(route('settings.github.callback', [
            'error' => 'access_denied',
            'state' => 'state-123',
        ]))
        ->assertRedirect(route('settings'))
        ->assertSessionHas('error', 'GitHub authorization was not completed. Please try connecting again.');

    expect(GitHubConnection::query()->count())->toBe(0);
});

it('does not save a connection when GitHub rejects the code exchange', function () {
    Config::set('services.github.client_id', 'manager-client-id');
    Config::set('services.github.client_secret', 'manager-client-secret');

    Http::preventStrayRequests();
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response(['error' => 'bad_verification_code'], 200),
    ]);

    $this->actingAs(User::factory()->create())
        ->withSession([
            'github_oauth' => [
                'state' => 'state-123',
                'code_verifier' => 'verifier-123',
            ],
        ])
        ->get(route('settings.github.callback', ['code' => 'bad-code', 'state' => 'state-123']))
        ->assertRedirect(route('settings'))
        ->assertSessionHas('error', 'GitHub could not be connected. Check the OAuth configuration and try again.');

    expect(GitHubConnection::query()->count())->toBe(0);
});

it('keeps the existing connection when GitHub account validation fails during reconnection', function () {
    Config::set('services.github.client_id', 'manager-client-id');
    Config::set('services.github.client_secret', 'manager-client-secret');

    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'existing-account',
        'access_token' => 'gho_existing_secret',
        'connected_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response([
            'access_token' => 'gho_new_secret',
            'scope' => 'repo',
        ]),
        'https://api.github.com/user' => Http::response(['message' => 'server error'], 500),
    ]);

    $this->actingAs(User::factory()->create())
        ->withSession([
            'github_oauth' => [
                'state' => 'state-123',
                'code_verifier' => 'verifier-123',
            ],
        ])
        ->get(route('settings.github.callback', ['code' => 'new-code', 'state' => 'state-123']))
        ->assertRedirect(route('settings'))
        ->assertSessionHas('error', 'GitHub could not be connected. Check the OAuth configuration and try again.');

    $connection = GitHubConnection::query()->firstOrFail();

    expect(GitHubConnection::query()->count())->toBe(1)
        ->and($connection->login)->toBe('existing-account')
        ->and($connection->access_token)->toBe('gho_existing_secret');
});

it('shows connected repositories without exposing the access token', function () {
    $user = User::factory()->create();
    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'name' => 'The Octocat',
        'scopes' => 'repo',
        'access_token' => 'gho_test_secret',
        'connected_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'octocat/Hello-World',
                'private' => false,
                'default_branch' => 'main',
                'html_url' => 'https://github.com/octocat/Hello-World',
            ],
            [
                'full_name' => 'octocat/Secret-App',
                'private' => true,
                'default_branch' => 'stable',
                'html_url' => 'https://github.com/octocat/Secret-App',
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('settings.github.repositories'))
        ->assertOk()
        ->assertSee('Repositories')
        ->assertSee('octocat/Hello-World')
        ->assertSee('Public')
        ->assertSee('main')
        ->assertSee('octocat/Secret-App')
        ->assertSee('Private')
        ->assertSee('stable')
        ->assertDontSee('gho_test_secret');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.github.com/user/repos?affiliation=owner%2Ccollaborator%2Corganization&sort=updated&per_page=100'
        && $request->hasHeader('Authorization', 'Bearer gho_test_secret'));
});

it('returns only canonical GitHub clone URLs for repository metadata', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/user/repos*' => Http::response([
            [
                'full_name' => 'octocat/Customer-Portal',
                'private' => true,
                'default_branch' => 'main',
                'html_url' => 'https://github.com/octocat/Customer-Portal',
                'clone_url' => 'https://github.com/octocat/Customer-Portal.git',
            ],
            [
                'full_name' => 'octocat/unsafe',
                'private' => true,
                'default_branch' => 'main',
                'html_url' => 'https://github.com/octocat/unsafe',
                'clone_url' => 'https://attacker.invalid/octocat/unsafe.git',
            ],
        ]),
    ]);

    $repositories = app(GitHubApi::class)->repositories('gho_test_secret');

    expect($repositories)->toHaveCount(2)
        ->and($repositories[0]['clone_url'])->toBe('https://github.com/octocat/Customer-Portal.git')
        ->and($repositories[0]['url'])->toBe('https://github.com/octocat/Customer-Portal')
        ->and($repositories[1]['clone_url'])->toBeNull();
});

it('returns to Settings when GitHub repository loading fails', function () {
    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_test_secret',
        'connected_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/user/repos*' => Http::response(['message' => 'server error'], 500)]);

    $this->actingAs(User::factory()->create())
        ->get(route('settings.github.repositories'))
        ->assertRedirect(route('settings'))
        ->assertSessionHas('error', 'GitHub repositories could not be loaded. Please try again.');
});

it('shows an empty state when the GitHub account has no repositories', function () {
    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_test_secret',
        'connected_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/user/repos*' => Http::response([])]);

    $this->actingAs(User::factory()->create())
        ->get(route('settings.github.repositories'))
        ->assertOk()
        ->assertSee('No repositories found');
});

it('revokes a GitHub token before removing the local connection', function () {
    Config::set('services.github.client_id', 'manager-client-id');
    Config::set('services.github.client_secret', 'manager-client-secret');

    $connection = GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_test_secret',
        'connected_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/applications/manager-client-id/token' => Http::response([], 204),
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('settings.github.disconnect'))
        ->assertRedirect(route('settings'))
        ->assertSessionHas('status', 'GitHub disconnected.');

    expect(GitHubConnection::query()->find($connection->id))->toBeNull();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/applications/manager-client-id/token'
        && $request['access_token'] === 'gho_test_secret'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('manager-client-id:manager-client-secret')));
});

it('preserves the local GitHub connection when revocation fails', function () {
    Config::set('services.github.client_id', 'manager-client-id');
    Config::set('services.github.client_secret', 'manager-client-secret');

    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_test_secret',
        'connected_at' => now(),
    ]);

    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/applications/manager-client-id/token' => Http::response([], 500)]);

    $this->actingAs(User::factory()->create())
        ->post(route('settings.github.disconnect'))
        ->assertRedirect(route('settings'))
        ->assertSessionHas('error', 'GitHub could not be disconnected. Please try again.');

    expect(GitHubConnection::query()->count())->toBe(1);
});
