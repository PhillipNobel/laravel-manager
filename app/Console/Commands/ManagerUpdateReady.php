<?php

namespace App\Console\Commands;

use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\SslStatus;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Console\Command;

class ManagerUpdateReady extends Command
{
    protected $signature = 'manager:update-ready';

    protected $description = 'Check for queued or running infrastructure work before a Manager update';

    public function handle(): int
    {
        $busy = Deployment::query()->whereIn('status', [DeploymentStatus::Pending, DeploymentStatus::Running])->exists()
            || Project::query()->where('status', ProjectStatus::Provisioning)
                ->orWhere('database_status', DatabaseStatus::Provisioning)
                ->orWhere('ssl_status', SslStatus::Provisioning)
                ->orWhereIn('publication_status', ['queued', 'running'])->exists();

        if ($busy) {
            $this->error('Finish queued or running application operations before updating.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
