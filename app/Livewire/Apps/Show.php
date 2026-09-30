<?php

namespace App\Livewire\Apps;

use App\Actions\Projects\ConfigureProjectDatabase;
use App\Actions\Projects\ConfigureProjectDomain;
use App\Actions\Projects\ConfigureProjectSsl;
use App\Actions\Projects\ConfigureProjectWebhook;
use App\Actions\Projects\QueueProjectDeployment;
use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Enums\SslStatus;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Support\LocalDevelopmentGuide;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Project')]
class Show extends Component
{
    #[Locked]
    public Project $project;

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    public function configureDomain(ConfigureProjectDomain $configureProjectDomain): void
    {
        $configureProjectDomain->handle($this->project);
        $this->project->refresh();
    }

    public function refreshDomainStatus(ConfigureProjectDomain $configureProjectDomain): void
    {
        $configureProjectDomain->refreshStatus($this->project);
        $this->project->refresh();
    }

    public function enableHttps(ConfigureProjectSsl $configureProjectSsl): void
    {
        $configureProjectSsl->queue($this->project, auth()->user()->email);
        $this->project->refresh();
    }

    public function refreshHttpsStatus(ConfigureProjectSsl $configureProjectSsl): void
    {
        $configureProjectSsl->refreshStatus($this->project);
        $this->project->refresh();
    }

    public function provisionDatabase(ConfigureProjectDatabase $configureProjectDatabase): void
    {
        $configureProjectDatabase->handle($this->project);
        $this->project->refresh();
    }

    public function deploy(QueueProjectDeployment $queueProjectDeployment): void
    {
        $queueProjectDeployment->handle($this->project);
        $this->project->refresh();
        session()->flash('status', 'Deployment queued.');
    }

    public function configureWebhook(ConfigureProjectWebhook $action): void
    {
        $action->handle($this->project);
        $this->project->refresh();
    }

    public function render(): View
    {
        $this->project->refresh();

        $deployments = $this->project->deployments()->latest()->get();
        $deploymentInProgress = $deployments->contains(fn ($deployment): bool => in_array(
            $deployment->status,
            [DeploymentStatus::Pending, DeploymentStatus::Running],
            true,
        ));

        return view('livewire.apps.show', [
            'localCommands' => LocalDevelopmentGuide::commands($this->project),
            'canConfigureDomain' => $this->project->status === ProjectStatus::Active && filled($this->project->path),
            'domainStatus' => $this->project->domain_status ?? DomainStatus::Pending,
            'sslStatus' => $this->project->ssl_status ?? SslStatus::Pending,
            'sslProvisioning' => $this->project->ssl_status === SslStatus::Provisioning,
            'canConfigureSsl' => $this->project->status === ProjectStatus::Active
                && $this->project->domain_status === DomainStatus::Active
                && filled($this->project->path),
            'databaseStatus' => $this->project->database_status ?? DatabaseStatus::Pending,
            'canConfigureDatabase' => $this->project->status === ProjectStatus::Active
                && filled($this->project->path)
                && ! in_array($this->project->database_status, [DatabaseStatus::Active, DatabaseStatus::Provisioning], true),
            'canDeploy' => $this->project->status === ProjectStatus::Active
                && $this->project->database_status === DatabaseStatus::Active
                && filled($this->project->path)
                && filled($this->project->repository_url)
                && GitHubConnection::query()->exists()
                && ! $deploymentInProgress,
            'deploymentInProgress' => $deploymentInProgress,
            'deployments' => $deployments,
        ]);
    }
}
