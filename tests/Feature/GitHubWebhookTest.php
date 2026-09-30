<?php

use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Jobs\DeployProject;
use App\Models\Deployment;
use App\Models\GitHubConnection;
use App\Models\GitHubWebhookDelivery;
use App\Models\Project;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

beforeEach(function () {
    Config::set('services.github.webhook_secret', 'webhook-test-secret');
    Queue::fake();
});

it('queues deployments only for projects matching the GitHub repository and configured branch', function () {
    $mainProject = createWebhookProject();
    $secondMainProject = createWebhookProject([
        'name' => 'Customer Admin',
        'slug' => 'customer-admin',
        'domain' => 'customer-admin.apps.example.test',
    ]);
    createWebhookProject([
        'name' => 'Release Portal',
        'slug' => 'release-portal',
        'domain' => 'release-portal.apps.example.test',
        'branch' => 'release',
    ]);
    createWebhookProject([
        'name' => 'Other Repository',
        'slug' => 'other-repository',
        'domain' => 'other-repository.apps.example.test',
        'repository_name' => 'octocat/other-repository',
    ]);
    connectWebhookGitHubAccount();

    $response = postSignedGitHubWebhook($this, webhookPushPayload());

    $response->assertStatus(202)
        ->assertExactJson(['status' => 'queued', 'deployments_queued' => 2]);

    expect(Deployment::query()->pluck('project_id')->all())
        ->toEqualCanonicalizing([$mainProject->id, $secondMainProject->id]);

    Queue::assertPushed(DeployProject::class, 2);

    $delivery = GitHubWebhookDelivery::query()->sole();

    expect($delivery->repository_name)->toBe('octocat/customer-portal')
        ->and($delivery->ref)->toBe('refs/heads/main')
        ->and($delivery->payload_hash)->toBe(hash('sha256', json_encode(webhookPushPayload(), JSON_THROW_ON_ERROR)))
        ->and($delivery->status)->toBe('queued')
        ->and($delivery->deployments_queued)->toBe(2);
});

it('ignores a push to a branch that is not configured for the repository', function () {
    createWebhookProject();
    connectWebhookGitHubAccount();

    postSignedGitHubWebhook($this, webhookPushPayload(ref: 'refs/heads/feature/new-dashboard'))
        ->assertStatus(202)
        ->assertExactJson(['status' => 'ignored', 'deployments_queued' => 0]);

    expect(Deployment::query()->count())->toBe(0)
        ->and(GitHubWebhookDelivery::query()->sole()->status)->toBe('ignored');

    Queue::assertNothingPushed();
});

it('ignores a push from a repository that is not configured', function () {
    createWebhookProject();
    connectWebhookGitHubAccount();

    postSignedGitHubWebhook($this, webhookPushPayload(repository: 'octocat/different-repository'))
        ->assertStatus(202)
        ->assertJsonPath('status', 'ignored');

    expect(Deployment::query()->count())->toBe(0)
        ->and(GitHubWebhookDelivery::query()->sole()->repository_name)->toBe('octocat/different-repository');

    Queue::assertNothingPushed();
});

it('does not queue duplicate GitHub delivery IDs', function () {
    createWebhookProject();
    connectWebhookGitHubAccount();

    postSignedGitHubWebhook($this, webhookPushPayload())
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued');

    postSignedGitHubWebhook($this, webhookPushPayload())
        ->assertStatus(202)
        ->assertExactJson(['status' => 'duplicate', 'deployments_queued' => 0]);

    expect(Deployment::query()->count())->toBe(1)
        ->and(GitHubWebhookDelivery::query()->count())->toBe(1);

    Queue::assertPushed(DeployProject::class, 1);
});

it('deduplicates a captured signed payload if its unsigned delivery header changes', function () {
    createWebhookProject();
    connectWebhookGitHubAccount();
    $payload = webhookPushPayload();

    postSignedGitHubWebhook($this, $payload)
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued');

    postSignedGitHubWebhook(
        $this,
        $payload,
        deliveryId: '72d3162e-cc78-11e3-81ab-4c9367dc0959',
    )
        ->assertStatus(202)
        ->assertExactJson(['status' => 'duplicate', 'deployments_queued' => 0]);

    expect(GitHubWebhookDelivery::query()->count())->toBe(1)
        ->and(Deployment::query()->count())->toBe(1);

    Queue::assertPushed(DeployProject::class, 1);
});

it('does not treat a non-push payload with branch fields as a push event', function () {
    createWebhookProject();
    connectWebhookGitHubAccount();

    $payload = [
        'ref' => 'refs/heads/main',
        'repository' => ['full_name' => 'octocat/customer-portal'],
    ];

    postSignedGitHubWebhook($this, $payload)
        ->assertStatus(202)
        ->assertJsonPath('status', 'ignored');

    expect(GitHubWebhookDelivery::query()->sole()->status)->toBe('ignored')
        ->and(Deployment::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('rejects a request with an invalid signature without recording it', function () {
    $body = json_encode(webhookPushPayload(), JSON_THROW_ON_ERROR);

    $this->call('POST', '/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_DELIVERY' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.str_repeat('0', 64),
    ], $body)->assertUnauthorized();

    expect(GitHubWebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('requires a configured webhook secret', function () {
    Config::set('services.github.webhook_secret', null);

    postSignedGitHubWebhook($this, webhookPushPayload())
        ->assertStatus(503)
        ->assertJsonPath('message', 'GitHub webhook secret is not configured.');

    expect(GitHubWebhookDelivery::query()->count())->toBe(0);
});

it('rejects an oversized webhook before verifying or recording it', function () {
    $body = json_encode(webhookPushPayload(), JSON_THROW_ON_ERROR);

    $this->call('POST', '/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) (25 * 1024 * 1024 + 1),
        'HTTP_X_GITHUB_DELIVERY' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'webhook-test-secret'),
    ], $body)->assertStatus(413);

    expect(GitHubWebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('records but ignores non-push events', function () {
    postSignedGitHubWebhook($this, webhookPushPayload(), event: 'issues');

    expect(GitHubWebhookDelivery::query()->sole()->status)->toBe('ignored')
        ->and(GitHubWebhookDelivery::query()->sole()->message)->toBe('Only push events are used for deployment.')
        ->and(Deployment::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('does not deploy a branch deletion', function () {
    createWebhookProject();
    connectWebhookGitHubAccount();

    postSignedGitHubWebhook($this, webhookPushPayload(deleted: true))
        ->assertStatus(202)
        ->assertJsonPath('status', 'ignored');

    expect(GitHubWebhookDelivery::query()->sole()->message)->toBe('Deleted branches are not deployed.')
        ->and(Deployment::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('skips a matching project that already has a pending deployment', function () {
    $project = createWebhookProject();
    connectWebhookGitHubAccount();
    $project->deployments()->create(['status' => DeploymentStatus::Pending]);

    postSignedGitHubWebhook($this, webhookPushPayload())
        ->assertStatus(202)
        ->assertJsonPath('status', 'ignored');

    expect(Deployment::query()->count())->toBe(1)
        ->and(GitHubWebhookDelivery::query()->sole()->message)
        ->toBe('Matching projects are not ready or already have a deployment.');

    Queue::assertNothingPushed();
});

it('rejects malformed signed JSON before storing a delivery', function () {
    $body = '{not-json';

    $this->call('POST', '/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_DELIVERY' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'webhook-test-secret'),
    ], $body)->assertBadRequest();

    expect(GitHubWebhookDelivery::query()->count())->toBe(0);
});

function postSignedGitHubWebhook(
    TestCase $test,
    array $payload,
    string $event = 'push',
    string $deliveryId = '72d3162e-cc78-11e3-81ab-4c9367dc0958',
): TestResponse {
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'webhook-test-secret'),
    ], $body);
}

function webhookPushPayload(
    string $repository = 'octocat/customer-portal',
    string $ref = 'refs/heads/main',
    bool $deleted = false,
): array {
    return [
        'ref' => $ref,
        'created' => false,
        'deleted' => $deleted,
        'after' => str_repeat('a', 40),
        'repository' => ['full_name' => $repository],
    ];
}

function createWebhookProject(array $overrides = []): Project
{
    return Project::query()->create(array_merge([
        'name' => 'Customer Portal',
        'slug' => 'customer',
        'domain' => 'customer.apps.example.test',
        'path' => '/var/www/apps/customer',
        'repository_url' => 'https://github.com/octocat/customer-portal',
        'repository_name' => 'octocat/customer-portal',
        'branch' => 'main',
        'status' => ProjectStatus::Active,
        'database_status' => DatabaseStatus::Active,
    ], $overrides));
}

function connectWebhookGitHubAccount(): void
{
    GitHubConnection::query()->create([
        'github_user_id' => 583231,
        'login' => 'octocat',
        'access_token' => 'gho_webhook_test_token',
        'connected_at' => now(),
    ]);
}
