<?php

namespace App\Actions\Projects;

use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Enums\SslStatus;
use App\Jobs\EnableProjectSsl;
use App\Models\AppSetting;
use App\Models\Project;
use App\Support\DomainGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class ConfigureProjectSsl
{
    public function queue(Project $project, string $email): SslStatus
    {
        return DB::transaction(function () use ($project, $email): SslStatus {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($project->ssl_status === SslStatus::Active) {
                return SslStatus::Active;
            }

            if ($project->ssl_status === SslStatus::Provisioning) {
                return SslStatus::Provisioning;
            }

            $problem = $this->projectProblem($project);

            if ($problem !== null) {
                $status = $project->status === ProjectStatus::Active
                    && $project->domain_status === DomainStatus::Active
                    ? SslStatus::Failed
                    : SslStatus::Pending;

                return $this->setStatus($project, $status, $problem);
            }

            if (! $this->validEmail($email)) {
                return $this->setStatus(
                    $project,
                    SslStatus::Failed,
                    'Use a valid administrator email address before requesting a certificate.',
                );
            }

            $project->update([
                'ssl_status' => SslStatus::Provisioning,
                'ssl_expires_at' => null,
                'ssl_message' => 'Certificate setup is queued. This page checks progress automatically.',
            ]);

            EnableProjectSsl::dispatch($project->id, $email)->afterCommit();

            return SslStatus::Provisioning;
        });
    }

    public function enable(Project $project, string $email): SslStatus
    {
        $problem = $this->projectProblem($project);

        if ($problem !== null || ! $this->validEmail($email)) {
            return $this->fail(
                $project,
                'enable',
                null,
                new \RuntimeException($problem ?? 'The administrator email address is invalid.'),
                $email,
            );
        }

        return $this->run($project, 'ssl-enable', $email);
    }

    public function refreshStatus(Project $project): SslStatus
    {
        if ($project->ssl_status === SslStatus::Provisioning) {
            return SslStatus::Provisioning;
        }

        $problem = $this->projectProblem($project);

        if ($problem !== null) {
            $status = $project->status === ProjectStatus::Active
                && $project->domain_status === DomainStatus::Active
                ? SslStatus::Failed
                : SslStatus::Pending;

            return $this->setStatus($project, $status, $problem);
        }

        return $this->run($project, 'ssl-status');
    }

    private function run(Project $project, string $operation, ?string $email = null): SslStatus
    {
        $command = [
            '/usr/bin/sudo',
            '-n',
            config('manager.apache_helper'),
            $operation,
            $project->slug,
        ];

        if ($email !== null) {
            $command[] = $email;
        }

        try {
            $result = Process::timeout($operation === 'ssl-enable' ? 720 : 35)->run($command);
        } catch (Throwable $exception) {
            return $this->fail($project, $operation, null, $exception, $email);
        }

        $output = trim($result->output());

        if (! $result->successful()) {
            return $this->fail($project, $operation, $result, email: $email);
        }

        if ($output === 'PENDING' && $operation === 'ssl-status') {
            return $this->setStatus($project, SslStatus::Pending, 'HTTPS is not enabled for this application.');
        }

        if (preg_match('/\A(ACTIVE|EXPIRED)\|([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)\z/', $output, $matches) !== 1) {
            return $this->fail(
                $project,
                $operation,
                $result,
                new \RuntimeException('The Apache helper returned an unexpected HTTPS status.'),
                $email,
            );
        }

        try {
            $expiresAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $matches[2], 'UTC');
        } catch (Throwable $exception) {
            return $this->fail($project, $operation, $result, $exception, $email);
        }

        if (! $expiresAt) {
            return $this->fail(
                $project,
                $operation,
                $result,
                new \RuntimeException('The Apache helper returned an invalid certificate expiry date.'),
                $email,
            );
        }

        if ($matches[1] === 'EXPIRED') {
            return $this->setStatus(
                $project,
                SslStatus::Failed,
                'The HTTPS certificate has expired. Retry HTTPS setup to renew it.',
                $expiresAt,
            );
        }

        return $this->setStatus(
            $project,
            SslStatus::Active,
            'HTTPS is enabled. Certbot renews this certificate automatically.',
            $expiresAt,
        );
    }

    private function projectProblem(Project $project): ?string
    {
        if ($project->status !== ProjectStatus::Active || blank($project->path)) {
            return 'Finish application provisioning before enabling HTTPS.';
        }

        if ($project->domain_status !== DomainStatus::Active) {
            return 'Configure and verify the Apache domain before enabling HTTPS.';
        }

        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $project->slug)) {
            return 'The application subdomain is invalid. Review the project before enabling HTTPS.';
        }

        if ($project->domain !== DomainGenerator::generate($project->slug, AppSetting::valueFor('base_domain'))) {
            return 'The project domain no longer matches the configured applications domain.';
        }

        return null;
    }

    private function validEmail(string $email): bool
    {
        return strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/\A[A-Za-z0-9._%+~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+\z/', $email) === 1;
    }

    private function fail(
        Project $project,
        string $operation,
        ?ProcessResult $result = null,
        ?Throwable $exception = null,
        ?string $email = null,
    ): SslStatus {
        $diagnostics = trim(implode("\n", array_filter([
            $result?->output(),
            $result?->errorOutput(),
            $exception?->getMessage(),
        ])));

        if ($email !== null) {
            $diagnostics = str_replace($email, '[administrator email]', $diagnostics);
        }

        Log::warning('Project HTTPS configuration failed.', [
            'project_id' => $project->id,
            'operation' => $operation,
            'exit_code' => $result?->exitCode(),
            'diagnostics' => Str::limit($diagnostics, 4000),
        ]);

        return $this->setStatus(
            $project,
            SslStatus::Failed,
            "Let's Encrypt could not configure HTTPS. Check DNS and public HTTP access, then retry.",
        );
    }

    private function setStatus(
        Project $project,
        SslStatus $status,
        string $message,
        ?CarbonImmutable $expiresAt = null,
    ): SslStatus {
        $project->update([
            'ssl_status' => $status,
            'ssl_expires_at' => $expiresAt,
            'ssl_message' => $message,
        ]);

        return $status;
    }
}
