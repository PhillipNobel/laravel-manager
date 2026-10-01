<?php

namespace App\Actions\Projects;

use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Jobs\DeployProject;
use App\Models\Deployment;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Support\InfrastructureLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueProjectDeployment
{
    public function handle(Project $project, bool $dispatch = true, bool $fromWebhook = false): Deployment
    {
        return InfrastructureLock::run(fn () => $this->executeHandle($project, $dispatch, $fromWebhook));
    }

    private function executeHandle(Project $project, bool $dispatch, bool $fromWebhook): Deployment
    {
        return DB::transaction(function () use ($project, $dispatch, $fromWebhook): Deployment {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            if (($fromWebhook && ! $project->automatic_deployment)
                || ($dispatch && $project->publication_status !== null && $project->publication_status !== 'ready')) {
                throw ValidationException::withMessages(['deployment' => 'Automatic deployment is disabled or initial preparation is in progress.']);
            }

            if ($project->status !== ProjectStatus::Active || blank($project->path) || blank($project->repository_url)) {
                throw ValidationException::withMessages([
                    'deployment' => 'Finish application provisioning and connect a repository before deploying.',
                ]);
            }

            if ($project->database_status !== DatabaseStatus::Active) {
                throw ValidationException::withMessages([
                    'deployment' => 'Create and verify the application database before deploying.',
                ]);
            }

            if (! GitHubConnection::query()->exists()) {
                throw ValidationException::withMessages([
                    'deployment' => 'Connect GitHub before deploying this application.',
                ]);
            }

            $hasActiveDeployment = $project->deployments()
                ->whereIn('status', [DeploymentStatus::Pending, DeploymentStatus::Running])
                ->exists();

            if ($hasActiveDeployment) {
                throw ValidationException::withMessages([
                    'deployment' => 'A deployment is already queued or running.',
                ]);
            }

            $deployment = $project->deployments()->create([
                'status' => DeploymentStatus::Pending,
            ]);

            if ($dispatch) {
                DeployProject::dispatch($deployment->id)->afterCommit();
            }

            return $deployment;
        });
    }
}
