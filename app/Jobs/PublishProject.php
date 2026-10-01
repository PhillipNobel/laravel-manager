<?php

namespace App\Jobs;

use App\Actions\Projects\ConfigureProjectDatabase;
use App\Actions\Projects\ConfigureProjectDomain;
use App\Actions\Projects\ConfigureProjectSsl;
use App\Actions\Projects\CreateProjectRepository;
use App\Actions\Projects\ProvisionProject;
use App\Actions\Projects\QueueProjectDeployment;
use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Enums\SslStatus;
use App\Models\AppSetting;
use App\Models\Deployment;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Models\User;
use App\Support\ApplicationHealthCheck;
use App\Support\DomainGenerator;
use App\Support\InfrastructureLock;
use App\Support\ProjectProcessEnvironment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class PublishProject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public const STEPS = ['repository' => 'Create repository', 'files' => 'Prepare files', 'database' => 'Create database',
        'deployment' => 'Deploy initial version', 'domain' => 'Configure subdomain', 'health' => 'Check application', 'ssl' => 'Enable HTTPS'];

    public function __construct(public int $projectId, public int $userId, public int $version) {}

    public function handle(): void
    {
        InfrastructureLock::run(function (): void {
            $project = DB::transaction(function (): ?Project {
                $project = Project::query()->lockForUpdate()->find($this->projectId);
                if (! $project || $project->publication_status !== 'queued' || $project->publication_version !== $this->version) {
                    return null;
                }
                $project->update(['publication_status' => 'running', 'publication_message' => 'Working: '.(self::STEPS[$project->publication_step] ?? 'preparation').'.']);

                return $project;
            });
            if (! $project) {
                return;
            }
            try {
                $this->performStep($project);
                DB::transaction(function () use ($project): void {
                    $project->refresh();
                    $steps = array_keys(self::STEPS);
                    $next = $steps[array_search($project->publication_step, $steps, true) + 1] ?? null;
                    $project->update(['publication_step' => $next ?: 'done', 'publication_status' => $next ? 'queued' : 'ready',
                        'publication_message' => $next ? 'Next: '.self::STEPS[$next].'.' : 'Your application is published. Develop locally, push '.$project->branch.', then click Update app.',
                        'publication_version' => $project->publication_version + 1]);
                    if ($next) {
                        self::dispatch($project->id, $this->userId, $project->publication_version)->onConnection('database')->afterCommit();
                    }
                });
            } catch (Throwable $exception) {
                Log::warning('Project publication failed.', ['project_id' => $project->id,
                    'step' => $project->publication_step, 'reason' => $exception::class]);
                $this->failed(new RuntimeException('Preparation failed.'));
            }
        });
    }

    private function performStep(Project $project): void
    {
        $connection = GitHubConnection::query()->first();
        if (! $connection) {
            throw new RuntimeException('GitHub connection missing.');
        }
        switch ($project->publication_step) {
            case 'repository':
                if (! $project->repository_initialized) {
                    try {
                        app(CreateProjectRepository::class)->handle($project, $connection);
                    } catch (RuntimeException $exception) {
                        $project->update(['provisioning_log' => $exception->getMessage()]);
                        throw $exception;
                    }
                }
                break;
            case 'files':
                if ($project->status !== ProjectStatus::Active) {
                    if ($project->path) {
                        throw new RuntimeException('Existing files require review.');
                    }
                    $project->update(['status' => ProjectStatus::Pending]);
                    app(ProvisionProject::class)->handle($project, [
                        'full_name' => $project->repository_name, 'clone_url' => $project->repository_url.'.git',
                    ], $connection);
                }
                if ($project->fresh()->status !== ProjectStatus::Active) {
                    throw new RuntimeException('File provisioning failed.');
                }
                break;
            case 'database':
                if (app(ConfigureProjectDatabase::class)->handle($project) !== DatabaseStatus::Active) {
                    throw new RuntimeException('Database provisioning failed.');
                }
                break;
            case 'deployment':
                $deployment = Deployment::query()->find($project->publication_deployment_id);
                if ($deployment?->status === DeploymentStatus::Successful) {
                    break;
                }
                if (! $deployment || $deployment->status === DeploymentStatus::Failed) {
                    $deployment = app(QueueProjectDeployment::class)->handle($project, false);
                    $project->update(['publication_deployment_id' => $deployment->id]);
                }
                (new DeployProject($deployment->id))->handle();
                if ($deployment->fresh()->status !== DeploymentStatus::Successful) {
                    throw new RuntimeException('Initial deployment failed.');
                }
                break;
            case 'domain':
                if ($project->domain_status !== DomainStatus::Active && app(ConfigureProjectDomain::class)->handle($project) !== DomainStatus::Active) {
                    throw new RuntimeException('Apache provisioning failed.');
                }
                break;
            case 'health':
                ApplicationHealthCheck::check($project);
                break;
            case 'ssl':
                $user = User::query()->findOrFail($this->userId);
                if ($project->ssl_status !== SslStatus::Active && app(ConfigureProjectSsl::class)->enable($project, $user->email) !== SslStatus::Active) {
                    throw new RuntimeException('HTTPS provisioning failed.');
                }
                $this->setHttpsUrl($project);
                break;
            default:
                throw new RuntimeException('Unknown preparation step.');
        }
    }

    private function setHttpsUrl(Project $project): void
    {
        $root = realpath(AppSetting::valueFor('applications_directory'));
        $path = $project->path;
        $environment = $path.'/.env';
        if (! $root || ! is_string($path) || is_link($path) || realpath($path) !== $root.'/'.$project->slug
            || $project->domain !== DomainGenerator::generate($project->slug, AppSetting::valueFor('base_domain'))
            || is_link($environment) || ! is_file($environment) || filesize($environment) > 1048576) {
            throw new RuntimeException('The application environment path is unsafe.');
        }
        $contents = file_get_contents($environment);
        $line = 'APP_URL=https://'.$project->domain;
        $contents = preg_match('/^APP_URL=.*$/m', $contents)
            ? preg_replace('/^APP_URL=.*$/m', $line, $contents) : rtrim($contents)."\n".$line."\n";
        $temporary = $path.'/.manager-env-'.bin2hex(random_bytes(12));
        $mask = umask(0077);
        try {
            $stream = fopen($temporary, 'x');
        } finally {
            umask($mask);
        }
        try {
            if (! $stream || fileowner($temporary) !== fileowner($environment) || (fileperms($environment) & 0777) !== 0600
                || ! in_array($project->php_version, config('manager.php_versions'), true)) {
                throw new RuntimeException('The application environment owner, mode or PHP version is unsafe.');
            }
            if (fwrite($stream, $contents) !== strlen($contents)) {
                throw new RuntimeException('The application URL could not be saved.');
            }
            fclose($stream);
            $stream = null;
            if (! rename($temporary, $environment)) {
                throw new RuntimeException('The application URL could not be saved.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
        $result = Process::path($path)->timeout(180)->env(ProjectProcessEnvironment::clean())
            ->run(['/usr/bin/php'.$project->php_version, 'artisan', 'config:cache']);
        if (! $result->successful()) {
            throw new RuntimeException('The application configuration cache could not be refreshed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $project = Project::query()->find($this->projectId);
        if (! $project || $project->publication_version !== $this->version || ! in_array($project->publication_status, ['queued', 'running'], true)) {
            return;
        }
        $message = match ($project->publication_step) {
            'repository' => 'Repository setup failed. Check GitHub access and the provisioning log. A previous creation attempt may require review in GitHub before retrying.',
            'files' => 'File preparation failed. Review the provisioning log. Existing directories are preserved and require review before another clone.',
            'database' => 'Database setup failed. Review the database message below before retrying.',
            'deployment' => 'Initial deployment failed. Review deployment output below, then retry preparation.',
            'domain' => 'Subdomain setup failed. Review the Apache message below, then retry preparation.',
            'health' => 'The application did not pass its local response check. Review application logs and retry preparation.',
            'ssl' => 'The app is available over HTTP, but HTTPS is not ready. Check wildcard DNS and ports 80/443, review the certificate message, then retry preparation.',
            default => 'Preparation was interrupted. Check the queue worker and server requirements, then retry preparation.',
        };
        if ($project->publication_step === 'deployment') {
            Deployment::query()->whereKey($project->publication_deployment_id)->whereIn('status', [DeploymentStatus::Pending, DeploymentStatus::Running])
                ->update(['status' => DeploymentStatus::Failed, 'finished_at' => now(), 'output' => 'Initial deployment was interrupted. Retry preparation after checking the server.']);
        }
        $project->update(['publication_status' => 'failed', 'publication_message' => $message,
            'status' => in_array($project->publication_step, ['repository', 'files'], true) ? ProjectStatus::Failed : $project->status]);
    }
}
