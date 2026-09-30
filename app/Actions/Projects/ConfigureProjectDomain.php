<?php

namespace App\Actions\Projects;

use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Models\AppSetting;
use App\Models\Project;
use App\Support\DomainGenerator;
use App\Support\InfrastructureLock;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class ConfigureProjectDomain
{
    public function handle(Project $project): DomainStatus
    {
        return InfrastructureLock::run(fn () => $this->executeHandle($project));
    }

    private function executeHandle(Project $project): DomainStatus
    {
        return $this->run($project, 'enable');
    }

    public function refreshStatus(Project $project): DomainStatus
    {
        return InfrastructureLock::run(fn () => $this->executeRefreshStatus($project));
    }

    private function executeRefreshStatus(Project $project): DomainStatus
    {
        return $this->run($project, 'status');
    }

    private function run(Project $project, string $operation): DomainStatus
    {
        $problem = $this->projectProblem($project);

        if ($problem !== null) {
            $status = $project->path ? DomainStatus::Failed : DomainStatus::Pending;
            $project->update([
                'domain_status' => $status,
                'domain_message' => $problem,
            ]);

            return $status;
        }

        $command = [
            '/usr/bin/sudo',
            '-n',
            config('manager.apache_helper'),
            $operation,
            $project->slug,
            $project->php_version ?: '8.3',
        ];

        try {
            $result = Process::timeout(35)->run($command);
        } catch (Throwable $exception) {
            return $this->failed($project, $operation, null, $exception);
        }

        $output = trim($result->output());
        $expectedOutput = $operation === 'enable'
            ? $output === 'ACTIVE'
            : in_array($output, ['ACTIVE', 'PENDING'], true);

        if (! $result->successful() || ! $expectedOutput) {
            return $this->failed($project, $operation, $result);
        }

        $status = $output === 'ACTIVE' ? DomainStatus::Active : DomainStatus::Pending;
        $project->update([
            'domain_status' => $status,
            'domain_message' => $status === DomainStatus::Pending
                ? 'The Apache virtual host is not enabled.'
                : 'Apache accepted the virtual host configuration and the service is active.',
        ]);

        return $status;
    }

    private function projectProblem(Project $project): ?string
    {
        if ($project->status !== ProjectStatus::Active) {
            return 'Finish application provisioning before configuring its Apache domain.';
        }

        if (! $project->path) {
            return 'Provision this application before configuring its Apache domain.';
        }

        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $project->slug)) {
            return 'The application slug is invalid. Review the project before configuring Apache.';
        }

        if (! in_array($project->php_version ?: '8.3', config('manager.php_versions'), true)) {
            return 'Choose a supported PHP-FPM version before configuring Apache.';
        }

        $baseDomain = AppSetting::valueFor('base_domain');
        if ($project->domain !== DomainGenerator::generate($project->slug, $baseDomain)) {
            return 'The project domain no longer matches the configured applications domain.';
        }

        $applicationsRoot = trim(AppSetting::valueFor('applications_directory'));
        $rootSegments = explode(DIRECTORY_SEPARATOR, trim($applicationsRoot, DIRECTORY_SEPARATOR));
        if (
            ! str_starts_with($applicationsRoot, DIRECTORY_SEPARATOR)
            || str_contains($applicationsRoot, "\0")
            || ! preg_match('/\A\/[A-Za-z0-9._\/ -]+\z/', $applicationsRoot)
            || str_contains($applicationsRoot, '//')
            || in_array('', $rootSegments, true)
            || in_array('.', $rootSegments, true)
            || in_array('..', $rootSegments, true)
        ) {
            return 'Use an absolute applications directory with letters, numbers, spaces, dots, underscores, hyphens, and slashes.';
        }

        $realRoot = realpath($applicationsRoot);
        if ($realRoot === false || $realRoot === DIRECTORY_SEPARATOR || ! is_dir($realRoot) || is_link($applicationsRoot)) {
            return 'The configured applications directory is unavailable or unsafe.';
        }

        $expectedPath = rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$project->slug;
        if (is_link($project->path) || realpath($project->path) !== $expectedPath) {
            return 'The application directory is missing or outside the configured root. Restore its provisioned path before retrying.';
        }

        $publicPath = $expectedPath.DIRECTORY_SEPARATOR.'public';
        if (is_link($publicPath) || realpath($publicPath) !== $publicPath || ! is_dir($publicPath)) {
            return 'The Laravel public directory is missing or unsafe.';
        }

        return null;
    }

    private function failed(
        Project $project,
        string $operation,
        ?ProcessResult $result = null,
        ?Throwable $exception = null,
    ): DomainStatus {
        $diagnostics = trim(implode("\n", array_filter([
            $result?->output(),
            $result?->errorOutput(),
            $exception?->getMessage(),
        ])));

        Log::warning('Apache domain operation failed.', [
            'project_id' => $project->id,
            'operation' => $operation,
            'exit_code' => $result?->exitCode(),
            'diagnostics' => Str::limit($diagnostics, 4000),
        ]);

        $project->update([
            'domain_status' => DomainStatus::Failed,
            'domain_message' => 'Apache could not complete this operation. Check the Laravel Manager log, then retry.',
        ]);

        return DomainStatus::Failed;
    }
}
