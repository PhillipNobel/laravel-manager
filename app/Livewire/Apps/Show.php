<?php

namespace App\Livewire\Apps;

use App\Actions\Projects\ConfigureProjectDatabase;
use App\Actions\Projects\ConfigureProjectDomain;
use App\Actions\Projects\ConfigureProjectSsl;
use App\Actions\Projects\ConfigureProjectWebhook;
use App\Actions\Projects\QueueProjectDeployment;
use App\Actions\Projects\QueueProjectPublication;
use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Enums\SslStatus;
use App\Jobs\PublishProject;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Support\InfrastructureLock;
use App\Support\LocalDevelopmentGuide;
use Illuminate\Validation\ValidationException;
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
        $this->assertPublicationIdle();
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
        $this->assertPublicationIdle();
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
        $this->assertPublicationIdle();
        $configureProjectDatabase->handle($this->project);
        $this->project->refresh();
    }

    public function deploy(QueueProjectDeployment $queueProjectDeployment): void
    {
        if ($this->project->fresh()->publication_status !== null && $this->project->publication_status !== 'ready') {
            $this->addError('deployment', 'Wait for initial preparation to finish.');

            return;
        }
        $queueProjectDeployment->handle($this->project);
        $this->project->refresh();
        session()->flash('status', 'Deployment queued.');
    }

    public function retryPublication(QueueProjectPublication $action): void
    {
        $action->handle($this->project, auth()->id());
        $this->project->refresh();
    }

    public function setDeploymentMode(string $mode): void
    {
        if (! in_array($mode, ['manual', 'automatic'], true)) {
            $this->addError('deploymentMode', 'Choose manual or automatic updates.');

            return;
        }
        InfrastructureLock::run(function () use ($mode): void {
            $this->project->refresh();
            $this->project->update(['automatic_deployment' => $mode === 'automatic']);
        });
        $this->resetValidation('deploymentMode');
        if ($mode === 'automatic') {
            app(ConfigureProjectWebhook::class)->handle($this->project);
        }
    }

    public function configureWebhook(ConfigureProjectWebhook $action): void
    {
        if (! $this->project->fresh()->automatic_deployment) {
            $this->addError('deploymentMode', 'Enable automatic updates before configuring a webhook.');

            return;
        }
        $action->handle($this->project);
        $this->project->refresh();
    }

    private function assertPublicationIdle(): void
    {
        if (in_array($this->project->fresh()->publication_status, ['queued', 'running'], true)) {
            throw ValidationException::withMessages(['publication' => 'Wait for initial preparation to finish.']);
        }
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
            'publicationSteps' => PublishProject::STEPS,
            'publicationInProgress' => in_array($this->project->publication_status, ['queued', 'running'], true),
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
                && ! $deploymentInProgress
                && ($this->project->publication_status === null || $this->project->publication_status === 'ready'),
            'deploymentInProgress' => $deploymentInProgress,
            'deployments' => $deployments,
        ]);
    }
}
