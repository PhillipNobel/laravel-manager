<?php

namespace App\Jobs;

use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Models\AppSetting;
use App\Models\Deployment;
use App\Models\GitHubConnection;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class DeployProject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    private const MAX_LOG_LENGTH = 30000;

    private const MAX_PROCESS_OUTPUT_LENGTH = 6000;

    private ?GitHubConnection $githubConnection = null;

    public function __construct(public int $deploymentId) {}

    public function handle(): void
    {
        $deployment = $this->claimDeployment();

        if (! $deployment) {
            return;
        }

        try {
            $project = $deployment->project;
            $path = $this->projectPath($project);
            $connection = GitHubConnection::query()->first();

            if (! $connection) {
                throw new RuntimeException('Connect GitHub before deploying this application.');
            }

            $this->githubConnection = $connection;
            $authorization = $this->gitAuthorization($connection);
            $branch = $this->validBranch($project->branch);
            $repositoryUrl = $this->repositoryUrl($project);

            $remoteUrl = $this->runCommand('Checking GitHub repository', $path, ['git', 'remote', 'get-url', 'origin'], 30);
            if (! in_array(trim($remoteUrl), [$repositoryUrl, $repositoryUrl.'.git'], true)) {
                throw new RuntimeException('The application Git remote does not match its configured GitHub repository.');
            }

            $this->assertEnvironmentIgnored($path);
            $this->runCommand('Validating branch', $path, ['git', 'check-ref-format', '--branch', $branch], 30);
            $this->runCommand(
                'Fetching '.$branch,
                $path,
                ['git', 'fetch', '--no-tags', 'origin', 'refs/heads/'.$branch.':refs/remotes/origin/'.$branch],
                180,
                $this->gitEnvironment($authorization),
            );

            $commitHash = trim($this->runCommand(
                'Reading current commit',
                $path,
                ['git', 'rev-parse', '--verify', 'refs/remotes/origin/'.$branch],
                30,
            ));

            if (! preg_match('/\A[0-9a-f]{40,64}\z/i', $commitHash)) {
                throw new RuntimeException('GitHub returned an invalid commit identifier.');
            }

            $environmentInCommit = trim($this->runCommand(
                'Checking commit for a tracked .env file',
                $path,
                ['git', 'ls-tree', '--name-only', $commitHash, '--', '.env'],
                30,
            ));

            if ($environmentInCommit === '.env') {
                throw new RuntimeException('The selected commit tracks an .env file. Remove it from Git before deploying.');
            }

            $commitMessage = trim($this->runCommand(
                'Reading commit message',
                $path,
                ['git', 'show', '-s', '--format=%s', $commitHash],
                30,
            ));

            Deployment::query()->whereKey($this->deploymentId)->update([
                'commit_hash' => strtolower($commitHash),
                'commit_message' => mb_substr($this->sanitizeOutput($commitMessage, $connection, $path), 0, 500),
            ]);

            $this->runCommand('Checking out current commit', $path, ['git', 'checkout', '--force', '--detach', $commitHash], 120);
            $this->assertRequiredFiles($path);
            $this->assertEnvironmentIgnored($path);
            $this->runCommand(
                'Installing Composer dependencies',
                $path,
                ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader'],
                900,
            );

            $this->installFrontendDependencies($path);

            $this->runCommand('Clearing Laravel caches', $path, ['php', 'artisan', 'optimize:clear'], 180);
            $this->runCommand('Running database migrations', $path, ['php', 'artisan', 'migrate', '--force'], 300);
            $this->runCommand('Optimizing Laravel application', $path, ['php', 'artisan', 'optimize'], 180);
            $this->runCommand('Restarting Laravel queue workers', $path, ['php', 'artisan', 'queue:restart'], 180);
            $this->checkApplicationResponse($project);

            $this->appendLog('Deployment completed successfully.');
            Deployment::query()->whereKey($this->deploymentId)->update([
                'status' => DeploymentStatus::Successful,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The deployment stopped unexpectedly. Review the deployment output and server logs before retrying.';

            $this->markFailed($message);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed('The deployment was interrupted or exceeded the queue worker time limit.');
    }

    private function claimDeployment(): ?Deployment
    {
        return DB::transaction(function (): ?Deployment {
            $candidate = Deployment::query()->find($this->deploymentId);

            if (! $candidate) {
                return null;
            }

            $project = Project::query()->lockForUpdate()->find($candidate->project_id);
            $deployment = Deployment::query()->lockForUpdate()->find($this->deploymentId);

            if (! $project || ! $deployment || $deployment->status !== DeploymentStatus::Pending) {
                return null;
            }

            $anotherDeploymentIsRunning = $project->deployments()
                ->where('status', DeploymentStatus::Running)
                ->whereKeyNot($deployment->id)
                ->exists();

            if ($anotherDeploymentIsRunning) {
                $deployment->update([
                    'status' => DeploymentStatus::Failed,
                    'output' => $this->timestamped('Another deployment is already running; this request was skipped.'),
                    'finished_at' => now(),
                ]);

                return null;
            }

            $deployment->update([
                'status' => DeploymentStatus::Running,
                'started_at' => now(),
            ]);

            return $deployment->fresh('project');
        });
    }

    private function projectPath(Project $project): string
    {
        if ($project->status !== ProjectStatus::Active || $project->database_status !== DatabaseStatus::Active) {
            throw new RuntimeException('The application and its database must be active before deployment.');
        }

        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/', $project->slug)) {
            throw new RuntimeException('The application subdomain is invalid.');
        }

        $configuredRoot = rtrim(trim(AppSetting::valueFor('applications_directory')), DIRECTORY_SEPARATOR);
        $root = realpath($configuredRoot);

        if ($configuredRoot === ''
            || ! str_starts_with($configuredRoot, DIRECTORY_SEPARATOR)
            || str_contains($configuredRoot, "\0")
            || is_link($configuredRoot)
            || $root === false
            || $root === DIRECTORY_SEPARATOR
            || ! is_dir($root)) {
            throw new RuntimeException('The configured applications directory is invalid.');
        }

        $path = $project->path;

        if (blank($path) || is_link($path) || ! is_dir($path)) {
            throw new RuntimeException('The application directory is missing or unsafe.');
        }

        $realPath = realpath($path);

        if ($realPath === false || $realPath !== $root.DIRECTORY_SEPARATOR.$project->slug) {
            throw new RuntimeException('The application directory is outside the configured applications directory.');
        }

        $this->assertRequiredFiles($realPath);

        return $realPath;
    }

    private function assertRequiredFiles(string $path): void
    {
        foreach (['.git', '.env', 'artisan', 'composer.json'] as $requiredPath) {
            $file = $path.DIRECTORY_SEPARATOR.$requiredPath;

            if (is_link($file) || ! file_exists($file)) {
                throw new RuntimeException('The application is missing a required Laravel or Git file.');
            }
        }

        if (! is_dir($path.'/.git')
            || ! is_file($path.'/.git/config')
            || is_link($path.'/.git/config')
            || ! is_file($path.'/.env')
            || ! is_file($path.'/artisan')
            || ! is_file($path.'/composer.json')) {
            throw new RuntimeException('The application is missing a required Laravel or Git file.');
        }
    }

    private function assertEnvironmentIgnored(string $path): void
    {
        $this->runCommand('Checking .env Git ignore rule', $path, ['git', 'check-ignore', '--quiet', '--', '.env'], 30);
    }

    private function validBranch(string $branch): string
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,119}\z/', $branch)) {
            throw new RuntimeException('The configured Git branch is invalid.');
        }

        return $branch;
    }

    private function repositoryUrl(Project $project): string
    {
        if (! is_string($project->repository_name)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $project->repository_name)) {
            throw new RuntimeException('The configured GitHub repository is invalid.');
        }

        $expectedUrl = 'https://github.com/'.$project->repository_name;

        if ($project->repository_url !== $expectedUrl) {
            throw new RuntimeException('The configured GitHub repository is invalid.');
        }

        return $expectedUrl;
    }

    private function gitAuthorization(GitHubConnection $connection): string
    {
        if (! preg_match('/\A[A-Za-z0-9-]+\z/', $connection->login)
            || $connection->access_token === ''
            || preg_match('/[\r\n\0]/', $connection->access_token)) {
            throw new RuntimeException('The stored GitHub connection is invalid.');
        }

        return 'AUTHORIZATION: basic '.base64_encode($connection->login.':'.$connection->access_token);
    }

    private function gitEnvironment(string $authorization): array
    {
        return [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
            'GIT_CONFIG_VALUE_0' => $authorization,
            'GIT_TERMINAL_PROMPT' => '0',
        ];
    }

    private function installFrontendDependencies(string $path): void
    {
        $packageFile = $path.'/package.json';

        if (! file_exists($packageFile) && ! is_link($packageFile)) {
            $this->appendLog('Skipping npm install and build; package.json is not present.');

            return;
        }

        if (is_link($packageFile) || ! is_file($packageFile) || filesize($packageFile) > 1048576) {
            throw new RuntimeException('The package.json file is missing or unsafe.');
        }

        $package = json_decode(file_get_contents($packageFile), true);

        if (! is_array($package)) {
            throw new RuntimeException('The package.json file is invalid.');
        }

        $lockFile = $path.'/package-lock.json';
        if (is_link($lockFile)) {
            throw new RuntimeException('The package-lock.json file is unsafe.');
        }

        $installCommand = is_file($lockFile)
            ? ['npm', 'ci', '--no-audit', '--no-fund']
            : ['npm', 'install', '--no-audit', '--no-fund'];

        $this->runCommand('Installing npm dependencies', $path, $installCommand, 900);

        if (filled(data_get($package, 'scripts.build'))) {
            $this->runCommand('Building frontend assets', $path, ['npm', 'run', 'build'], 600);
        } else {
            $this->appendLog('Skipping npm build; package.json does not define a build script.');
        }
    }

    private function checkApplicationResponse(Project $project): void
    {
        if ($project->domain_status !== DomainStatus::Active) {
            $this->appendLog('Skipping application health check; the Apache domain is not active.');

            return;
        }

        if (! is_string($project->domain)
            || filter_var($project->domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new RuntimeException('The configured application domain is invalid for the health check.');
        }

        $this->appendLog('Checking application response.');

        try {
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->withHeaders(['Host' => $project->domain])
                ->withOptions(['allow_redirects' => false])
                ->get('http://127.0.0.1/');
        } catch (ConnectionException) {
            throw new RuntimeException('The application did not respond to the health check after deployment.');
        }

        if ($response->serverError()) {
            throw new RuntimeException('The application returned HTTP '.$response->status().' after deployment.');
        }

        $this->appendLog('Application responded with HTTP '.$response->status().'.');
    }

    private function runCommand(string $step, string $path, array $command, int $timeout, array $environment = []): string
    {
        $this->appendLog($step.'.');

        try {
            $process = Process::path($path)->timeout($timeout);

            if ($environment !== []) {
                $process = $process->env($environment);
            }

            $result = $process->run($command);
        } catch (Throwable) {
            $this->appendLog($step.' timed out or could not start.');
            throw new RuntimeException($step.' failed.');
        }

        $output = trim($result->output()."\n".$result->errorOutput());
        $safeOutput = $this->sanitizeOutput($output, $this->githubConnection, $path);

        if ($safeOutput !== '') {
            $this->appendLog($safeOutput);
        }

        if (! $result->successful()) {
            throw new RuntimeException($step.' failed.');
        }

        return trim($result->output());
    }

    private function sanitizeOutput(string $output, ?GitHubConnection $connection, string $path): string
    {
        if ($connection) {
            $encodedAuthorization = base64_encode($connection->login.':'.$connection->access_token);
            $output = str_replace([
                $connection->access_token,
                $encodedAuthorization,
                'AUTHORIZATION: basic '.$encodedAuthorization,
            ], '[redacted]', $output);
        }

        $environmentPath = $path.'/.env';
        if (is_file($environmentPath) && ! is_link($environmentPath)) {
            foreach (file($environmentPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (! preg_match('/\A\s*([A-Z0-9_]+)\s*=\s*(.*)\z/i', $line, $matches)
                    || ! preg_match('/PASSWORD|TOKEN|SECRET|KEY/i', $matches[1])) {
                    continue;
                }

                $secret = trim(trim($matches[2]), "\"'");
                if ($secret !== '') {
                    $output = str_replace([$matches[2], $secret], '[redacted]', $output);
                }
            }
        }

        $output = preg_replace('/authorization:\s*basic\s+[A-Za-z0-9+\/=]+/i', 'Authorization: basic [redacted]', $output) ?? '';
        $output = preg_replace('~(https?://)[^/@\s]+:[^/@\s]+@~i', '$1[redacted]@', $output) ?? '';
        $output = preg_replace('/(?im)^([A-Z0-9_]*(?:PASSWORD|TOKEN|SECRET|KEY)[A-Z0-9_]*)=.*$/', '$1=[redacted]', $output) ?? '';
        $output = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output) ?? '';

        return mb_substr(trim($output), 0, self::MAX_PROCESS_OUTPUT_LENGTH);
    }

    private function appendLog(string $message): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);

        if (! $deployment) {
            return;
        }

        $log = trim(($deployment->output ?? '')."\n".$this->timestamped($message));
        $deployment->update(['output' => mb_substr($log, -self::MAX_LOG_LENGTH)]);
    }

    private function timestamped(string $message): string
    {
        return now()->utc()->format('Y-m-d H:i:s').' UTC  '.$message;
    }

    private function markFailed(string $message): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);

        if (! $deployment || in_array($deployment->status, [DeploymentStatus::Successful, DeploymentStatus::Failed], true)) {
            return;
        }

        $log = trim(($deployment->output ?? '')."\n".$this->timestamped($message));
        $deployment->update([
            'status' => DeploymentStatus::Failed,
            'output' => mb_substr($log, -self::MAX_LOG_LENGTH),
            'finished_at' => now(),
        ]);
    }
}
