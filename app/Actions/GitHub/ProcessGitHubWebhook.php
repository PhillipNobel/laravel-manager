<?php

namespace App\Actions\GitHub;

use App\Actions\Projects\QueueProjectDeployment;
use App\Models\GitHubWebhookDelivery;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessGitHubWebhook
{
    public function __construct(private QueueProjectDeployment $queueProjectDeployment) {}

    public function handle(string $deliveryId, string $event, array $payload, string $payloadHash): array
    {
        return DB::transaction(function () use ($deliveryId, $event, $payload, $payloadHash): array {
            $inserted = DB::table('github_webhook_deliveries')->insertOrIgnore([
                'delivery_id' => $deliveryId,
                'payload_hash' => $payloadHash,
                'event' => $event,
                'status' => 'processing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return ['status' => 'duplicate', 'deployments_queued' => 0];
            }

            $delivery = GitHubWebhookDelivery::query()->where('delivery_id', $deliveryId)->firstOrFail();

            if ($event !== 'push') {
                return $this->ignore($delivery, 'Only push events are used for deployment.');
            }

            $repositoryName = data_get($payload, 'repository.full_name');
            $ref = data_get($payload, 'ref');
            $created = data_get($payload, 'created');
            $deleted = data_get($payload, 'deleted');
            $after = data_get($payload, 'after');

            if (! is_string($repositoryName)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $repositoryName) !== 1
                || ! is_string($ref)
                || preg_match('/\Arefs\/heads\/[A-Za-z0-9][A-Za-z0-9._\/-]{0,119}\z/', $ref) !== 1
                || ! is_bool($created)
                || ! is_bool($deleted)
                || ! is_string($after)
                || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/i', $after) !== 1) {
                return $this->ignore($delivery, 'Push payload is missing valid repository or branch information.');
            }

            $delivery->update([
                'repository_name' => $repositoryName,
                'ref' => $ref,
            ]);

            if ($deleted) {
                return $this->ignore($delivery, 'Deleted branches are not deployed.');
            }

            $prefix = 'refs/heads/';

            if (! str_starts_with($ref, $prefix)) {
                return $this->ignore($delivery, 'Only branch pushes are deployed.');
            }

            $branch = substr($ref, strlen($prefix));

            if ($branch === '') {
                return $this->ignore($delivery, 'Push payload is missing a branch name.');
            }

            $projects = Project::query()
                ->where('repository_name', $repositoryName)
                ->where('branch', $branch)
                ->get();

            if ($projects->isEmpty()) {
                return $this->ignore($delivery, 'No project is configured for this repository and branch.');
            }

            $queued = 0;
            $skipped = 0;

            foreach ($projects as $project) {
                try {
                    $this->queueProjectDeployment->handle($project);
                    $queued++;
                } catch (ValidationException) {
                    $skipped++;
                }
            }

            if ($queued === 0) {
                return $this->ignore($delivery, 'Matching projects are not ready or already have a deployment.');
            }

            $message = "Queued {$queued} deployment(s).";

            if ($skipped > 0) {
                $message .= " Skipped {$skipped} matching project(s) that are not ready or already deploying.";
            }

            $delivery->update([
                'status' => 'queued',
                'message' => $message,
                'deployments_queued' => $queued,
            ]);

            return ['status' => 'queued', 'deployments_queued' => $queued];
        });
    }

    private function ignore(GitHubWebhookDelivery $delivery, string $message): array
    {
        $delivery->update([
            'status' => 'ignored',
            'message' => $message,
        ]);

        return ['status' => 'ignored', 'deployments_queued' => 0];
    }
}
