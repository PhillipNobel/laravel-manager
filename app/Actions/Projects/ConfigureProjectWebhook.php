<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Support\GitHubApi;
use App\Support\InfrastructureLock;
use App\Support\WebhookSecret;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ConfigureProjectWebhook
{
    public function __construct(private GitHubApi $github) {}

    public function handle(Project $project): void
    {
        InfrastructureLock::run(function () use ($project): void {
            $repository = $project->repository_name;
            if (! is_string($repository) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $repository)) {
                $this->fail($project, 'Configure a canonical GitHub repository first.');

                return;
            }
            try {
                $lock = Cache::lock('github-hook:'.hash('sha256', strtolower($repository)), 180);
                $acquired = $lock->get();
            } catch (Throwable) {
                $this->fail($project, 'Webhook configuration could not acquire its lock. Check the Manager cache and retry.');

                return;
            }
            if (! $acquired) {
                $this->fail($project, 'Webhook configuration is already running. Try again shortly.');

                return;
            }
            try {
                $this->configure($project, $repository);
            } finally {
                $lock->release();
            }
        });
    }

    private function configure(Project $project, string $repository): void
    {
        $project->refresh();
        $base = rtrim(AppSetting::valueFor('manager_url'), '/');
        $parts = parse_url($base);
        $host = $parts['host'] ?? '';
        if (($parts['scheme'] ?? '') !== 'https' || ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) || preg_match('/(?:\.localhost|\.local|\.test|\.internal|\.invalid)\z/i', $host)
            || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || array_intersect(['user', 'pass', 'query', 'fragment', 'port'], array_keys($parts ?: []))
            || ! empty($parts['path'])) {
            $this->fail($project, 'Set a public HTTPS Manager URL in Settings before configuring automatic deployment.');

            return;
        }
        $connection = GitHubConnection::query()->first();
        if (! $connection || $project->status !== ProjectStatus::Active
            || $project->repository_url !== "https://github.com/{$repository}") {
            $this->fail($project, 'Connect GitHub and finish provisioning this application first.');

            return;
        }
        $url = $base.'/webhooks/github';
        try {
            $repo = $this->github->repository($connection->access_token, $repository);
            if (($repo['full_name'] ?? null) !== $repository || data_get($repo, 'permissions.admin') !== true) {
                $this->fail($project, 'The connected GitHub account needs administrator access to this repository.');

                return;
            }
            $hooks = $this->github->hooks($connection->access_token, $repository);
            $known = Project::query()->where('repository_name', $repository)->whereNotNull('webhook_id')->get();
            $matches = array_values(array_filter($hooks, function ($hook) use ($known, $url): bool {
                return is_array($hook) && (data_get($hook, 'config.url') === $url
                    || $known->contains(fn (Project $record) => $record->webhook_id === ($hook['id'] ?? null)
                        && $record->webhook_url === data_get($hook, 'config.url')));
            }));
            if (count($matches) > 1) {
                $this->fail($project, 'Multiple matching webhooks exist. Review repository webhooks in GitHub before retrying.');

                return;
            }
            $hook = $matches[0] ?? null;
            if ($hook && (($hook['name'] ?? null) !== 'web' || ($hook['events'] ?? null) !== ['push']
                || data_get($hook, 'config.content_type') !== 'json' || data_get($hook, 'config.insecure_ssl') !== '0'
                || ! is_int($hook['id'] ?? null) || $hook['id'] < 1)) {
                $this->fail($project, 'A conflicting webhook exists. Preserve it and review its configuration in GitHub.');

                return;
            }
            $payload = ['name' => 'web', 'active' => true, 'events' => ['push'], 'config' => [
                'url' => $url, 'content_type' => 'json', 'insecure_ssl' => '0', 'secret' => WebhookSecret::ensure(),
            ]];
            try {
                $saved = $this->github->saveHook($connection->access_token, $repository, $payload, $hook['id'] ?? null);
            } catch (Throwable $exception) {
                // A lost POST response can still have created the hook. Reconcile before another create.
                if ($hook) {
                    throw $exception;
                }
                $found = array_values(array_filter($this->github->hooks($connection->access_token, $repository),
                    fn ($candidate) => data_get($candidate, 'config.url') === $url));
                if (count($found) !== 1) {
                    throw $exception;
                }
                $saved = $found[0];
                if (! is_int($saved['id'] ?? null) || $saved['id'] < 1 || ($saved['name'] ?? null) !== 'web'
                    || ($saved['events'] ?? null) !== ['push'] || data_get($saved, 'config.content_type') !== 'json'
                    || data_get($saved, 'config.insecure_ssl') !== '0') {
                    throw $exception;
                }
                $saved = $this->github->saveHook($connection->access_token, $repository, $payload, $saved['id'] ?? null);
            }
            if (! is_int($saved['id'] ?? null) || $saved['id'] < 1 || data_get($saved, 'config.url') !== $url
                || ($saved['name'] ?? null) !== 'web' || ($saved['events'] ?? null) !== ['push'] || ($saved['active'] ?? null) !== true) {
                throw new RuntimeException('Invalid webhook response.');
            }
            foreach (Project::query()->where('repository_name', $repository)->get() as $related) {
                $verified = $related->webhook_id === $saved['id'] && $related->webhook_url === $url
                    ? $related->webhook_verified_at : null;
                $related->update(['webhook_id' => $saved['id'], 'webhook_url' => $url,
                    'webhook_status' => $verified ? 'verified' : 'configured', 'webhook_verified_at' => $verified,
                    'webhook_message' => 'Webhook configured. Waiting for a signed GitHub ping.', 'webhook_checked_at' => now()]);
            }
            $this->github->pingHook($connection->access_token, $repository, $saved['id']);
        } catch (Throwable) {
            $this->fail($project, 'GitHub could not finish webhook configuration or ping. Check access and retry; the application has been preserved.');
        }
    }

    private function fail(Project $project, string $message): void
    {
        $project->update(['webhook_status' => 'failed', 'webhook_message' => $message, 'webhook_checked_at' => now()]);
    }
}
