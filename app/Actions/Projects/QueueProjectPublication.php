<?php

namespace App\Actions\Projects;

use App\Jobs\PublishProject;
use App\Models\Project;
use App\Support\InfrastructureLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueProjectPublication
{
    public function handle(Project $project, int $userId): void
    {
        InfrastructureLock::run(function () use ($project, $userId): void {
            DB::transaction(function () use ($project, $userId): void {
                $project = Project::query()->lockForUpdate()->findOrFail($project->id);
                if (in_array($project->publication_status, ['queued', 'running', 'ready'], true)) {
                    throw ValidationException::withMessages(['publication' => 'Preparation is already queued, running or complete.']);
                }
                $project->update(['publication_status' => 'queued', 'publication_step' => $project->publication_step ?: 'repository',
                    'publication_version' => $project->publication_version + 1,
                    'publication_message' => 'Preparation queued. Keep the database queue worker running.']);
                PublishProject::dispatch($project->id, $userId, $project->publication_version)->onConnection('database')->afterCommit();
            });
        });
    }
}
