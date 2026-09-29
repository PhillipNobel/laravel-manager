<?php

namespace App\Jobs;

use App\Actions\Projects\ConfigureProjectSsl;
use App\Enums\SslStatus;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EnableProjectSsl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $projectId, public string $email) {}

    public function handle(ConfigureProjectSsl $configureProjectSsl): void
    {
        $project = Project::query()->find($this->projectId);

        if ($project) {
            $configureProjectSsl->enable($project, $this->email);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Project::query()
            ->whereKey($this->projectId)
            ->where('ssl_status', SslStatus::Provisioning)
            ->update([
                'ssl_status' => SslStatus::Failed,
                'ssl_expires_at' => null,
                'ssl_message' => "Let's Encrypt could not configure HTTPS. Check DNS and public HTTP access, then retry.",
            ]);
    }
}
