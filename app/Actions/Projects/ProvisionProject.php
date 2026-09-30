<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\AppSetting;
use App\Models\GitHubConnection;
use App\Models\Project;
use App\Support\InfrastructureLock;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class ProvisionProject
{
    private const MAX_LOG_LENGTH = 20000;

    private const MAX_PROCESS_OUTPUT_LENGTH = 4000;

    public function handle(Project $project, array $repository, GitHubConnection $connection): void
    {
        InfrastructureLock::run(fn () => $this->executeHandle($project, $repository, $connection));
    }

    private function executeHandle(Project $project, array $repository, GitHubConnection $connection): void
    {
        $claimed = DB::transaction(function () use ($project): bool {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($lockedProject->status !== ProjectStatus::Pending) {
                return false;
            }

            $lockedProject->update([
                'status' => ProjectStatus::Provisioning,
                'provisioning_log' => null,
            ]);

            return true;
        });

        if (! $claimed) {
            return;
        }

        try {
            $this->appendLog($project, 'Preparing application directory.');

            $slug = $this->validateSlug($project->slug);
            $branch = $this->validateBranch($project->branch);
            $cloneUrl = $this->validateRepository($repository);
            $authorization = $this->gitAuthorization($connection);
            $applicationsRoot = $this->applicationsRoot();

            $branchCheck = Process::path($applicationsRoot)
                ->timeout(10)
                ->run(['git', 'check-ref-format', '--branch', $branch]);

            if (! $branchCheck->successful()) {
                $this->appendLog($project, 'The selected Git branch failed validation.');

                throw new RuntimeException('The selected Git branch is invalid.');
            }

            $projectPath = $applicationsRoot.DIRECTORY_SEPARATOR.$slug;

            if (file_exists($projectPath) || is_link($projectPath)) {
                throw new RuntimeException('The application path already exists. Existing files were left untouched.');
            }

            if (! @mkdir($projectPath, 0755)) {
                throw new RuntimeException('The application directory could not be created.');
            }

            $project->update(['path' => $projectPath]);
            $this->appendLog($project, 'Cloning '.$repository['full_name'].' ('.$branch.').');

            try {
                $clone = Process::path($applicationsRoot)
                    ->timeout(180)
                    ->env([
                        'GIT_CONFIG_COUNT' => '1',
                        'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
                        'GIT_CONFIG_VALUE_0' => $authorization,
                        'GIT_TERMINAL_PROMPT' => '0',
                    ])
                    ->run(['git', 'clone', '--branch', $branch, '--single-branch', '--', $cloneUrl, $projectPath]);
            } catch (Throwable) {
                throw new RuntimeException('GitHub clone failed or timed out.');
            }

            $this->appendProcessOutput($project, $clone, $connection);

            if (! $clone->successful()) {
                throw new RuntimeException('GitHub clone failed.');
            }

            $this->appendLog($project, 'Checking Laravel project files.');
            $this->validateLaravelProject($projectPath);
            $this->writeEnvironmentFile($project, $projectPath);
            $this->setRuntimePermissions($projectPath);

            $project->update(['status' => ProjectStatus::Active]);
            $this->appendLog($project, 'Application is ready.');
        } catch (RuntimeException $exception) {
            $project->update(['status' => ProjectStatus::Failed]);
            $this->appendLog($project, $exception->getMessage());
        } catch (Throwable) {
            $project->update(['status' => ProjectStatus::Failed]);
            $this->appendLog($project, 'Provisioning failed. Review the steps above and correct the server or repository configuration before trying again.');
        }
    }

    private function applicationsRoot(): string
    {
        $root = trim(AppSetting::valueFor('applications_directory'));
        $root = rtrim($root, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;

        if ($root === '' || ! str_starts_with($root, DIRECTORY_SEPARATOR) || str_contains($root, "\0")) {
            throw new RuntimeException('The applications directory must be an absolute path.');
        }

        if ($root === DIRECTORY_SEPARATOR || is_link($root)) {
            throw new RuntimeException('The applications directory cannot be a symbolic link.');
        }

        if (! is_dir($root) && ! @mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new RuntimeException('The applications directory could not be created.');
        }

        $realRoot = realpath($root);

        if ($realRoot === false || $realRoot === DIRECTORY_SEPARATOR || ! is_dir($realRoot)) {
            throw new RuntimeException('The applications directory is invalid.');
        }

        return rtrim($realRoot, DIRECTORY_SEPARATOR);
    }

    private function validateSlug(string $slug): string
    {
        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/', $slug)) {
            throw new RuntimeException('The application subdomain is invalid.');
        }

        return $slug;
    }

    private function validateBranch(string $branch): string
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,119}\z/', $branch)) {
            throw new RuntimeException('The selected Git branch is invalid.');
        }

        return $branch;
    }

    private function validateRepository(array $repository): string
    {
        $fullName = $repository['full_name'] ?? null;
        $cloneUrl = $repository['clone_url'] ?? null;

        if (! is_string($fullName)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $fullName)
            || ! is_string($cloneUrl)
            || ! hash_equals('https://github.com/'.$fullName.'.git', $cloneUrl)) {
            throw new RuntimeException('The selected GitHub repository is invalid.');
        }

        return $cloneUrl;
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

    private function validateLaravelProject(string $path): void
    {
        foreach (['artisan', 'composer.json', '.env.example'] as $file) {
            $filePath = $path.DIRECTORY_SEPARATOR.$file;

            if (is_link($filePath) || ! is_file($filePath)) {
                throw new RuntimeException('The repository is missing required Laravel files.');
            }
        }

        $environmentExample = $path.DIRECTORY_SEPARATOR.'.env.example';

        if (filesize($environmentExample) > 262144) {
            throw new RuntimeException('The repository .env.example file is unexpectedly large.');
        }

        $environmentPath = $path.DIRECTORY_SEPARATOR.'.env';

        if (file_exists($environmentPath) || is_link($environmentPath)) {
            throw new RuntimeException('The repository already contains an .env file; it was left untouched.');
        }
    }

    private function writeEnvironmentFile(Project $project, string $path): void
    {
        $environmentExample = $path.DIRECTORY_SEPARATOR.'.env.example';
        $environmentPath = $path.DIRECTORY_SEPARATOR.'.env';
        $contents = File::get($environmentExample);
        $name = addcslashes($project->name, '\\"');
        $values = [
            'APP_NAME' => '"'.$name.'"',
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false',
            'APP_URL' => 'http://'.$project->domain,
        ];
        $lines = preg_split('/\r\n|\r|\n/', rtrim($contents, "\r\n")) ?: [];
        $updatedLines = [];
        $written = [];

        foreach ($lines as $line) {
            $replaced = false;

            foreach ($values as $key => $value) {
                if (preg_match('/\A\s*'.preg_quote($key, '/').'\s*=/', $line)) {
                    $updatedLines[] = $key.'='.$value;
                    $written[$key] = true;
                    $replaced = true;

                    break;
                }
            }

            if (! $replaced) {
                $updatedLines[] = $line;
            }
        }

        foreach ($values as $key => $value) {
            if (! isset($written[$key])) {
                $updatedLines[] = $key.'='.$value;
            }
        }

        if (file_put_contents($environmentPath, implode(PHP_EOL, $updatedLines).PHP_EOL, LOCK_EX) === false
            || ! @chmod($environmentPath, 0600)) {
            throw new RuntimeException('The application environment file could not be written securely.');
        }
    }

    private function setRuntimePermissions(string $projectPath): void
    {
        if (! @chmod($projectPath, 0755)) {
            throw new RuntimeException('The application directory permissions could not be set.');
        }

        foreach (['storage', 'bootstrap/cache'] as $relativePath) {
            $directory = $this->ensureProjectDirectory($projectPath, $relativePath);

            if (! @chmod($directory, 0775)) {
                throw new RuntimeException('A Laravel runtime directory permission could not be set.');
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $entry) {
                if ($entry->isLink()) {
                    continue;
                }

                if ($entry->isDir()) {
                    if (! @chmod($entry->getPathname(), 0775)) {
                        throw new RuntimeException('A Laravel runtime directory permission could not be set.');
                    }
                } elseif ($entry->isFile()) {
                    if (! @chmod($entry->getPathname(), 0664)) {
                        throw new RuntimeException('A Laravel runtime file permission could not be set.');
                    }
                }
            }
        }
    }

    private function ensureProjectDirectory(string $projectPath, string $relativePath): string
    {
        $currentPath = $projectPath;

        foreach (explode('/', $relativePath) as $segment) {
            $currentPath .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($currentPath) || (file_exists($currentPath) && ! is_dir($currentPath))) {
                throw new RuntimeException('A required Laravel runtime directory is unsafe.');
            }

            if (! is_dir($currentPath) && ! @mkdir($currentPath, 0775) && ! is_dir($currentPath)) {
                throw new RuntimeException('A required Laravel runtime directory could not be created.');
            }
        }

        return $currentPath;
    }

    private function appendProcessOutput(Project $project, ProcessResult $result, GitHubConnection $connection): void
    {
        $output = trim($result->output()."\n".$result->errorOutput());

        if ($output !== '') {
            $this->appendLog($project, $this->sanitizeOutput($output, $connection));
        }
    }

    private function sanitizeOutput(string $output, GitHubConnection $connection): string
    {
        $encodedAuthorization = base64_encode($connection->login.':'.$connection->access_token);
        $output = str_replace([
            $connection->access_token,
            $encodedAuthorization,
            'AUTHORIZATION: basic '.$encodedAuthorization,
        ], '[redacted]', $output);
        $output = preg_replace('/authorization:\s*basic\s+[A-Za-z0-9+\/=]+/i', 'Authorization: basic [redacted]', $output) ?? '';
        $output = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output) ?? '';

        return mb_substr(trim($output), 0, self::MAX_PROCESS_OUTPUT_LENGTH);
    }

    private function appendLog(Project $project, string $message): void
    {
        $project->refresh();
        $timestampedMessage = now()->utc()->format('Y-m-d H:i:s').' UTC  '.$message;
        $log = trim(($project->provisioning_log ?? '')."\n".$timestampedMessage);

        $project->update([
            'provisioning_log' => mb_substr($log, -self::MAX_LOG_LENGTH),
        ]);
    }
}
