<?php

namespace App\Actions\Projects;

use App\Models\GitHubConnection;
use App\Models\Project;
use App\Support\GitHubApi;
use App\Support\InfrastructureLock;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CreateProjectRepository
{
    public function __construct(private GitHubApi $github) {}

    public function handle(Project $project, GitHubConnection $connection): array
    {
        return InfrastructureLock::run(fn () => $this->executeHandle($project, $connection));
    }

    private function executeHandle(Project $project, GitHubConnection $connection): array
    {
        $this->validateConnection($connection);

        $stagingRoot = $this->stagingRoot();
        $starterPath = $stagingRoot.DIRECTORY_SEPARATOR.'starter-'.Str::uuid();
        $repository = null;

        try {
            $version = match ($project->php_version) {
                '8.2' => '^12.0',
                '8.3', '8.4' => '^13.0',
                default => throw new RuntimeException('The selected PHP version cannot be used to create a Laravel starter.'),
            };

            $this->run(
                Process::path($stagingRoot)->timeout(180),
                ['composer', 'create-project', 'laravel/laravel:'.$version, $starterPath, '--no-install', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist'],
                'The Laravel starter could not be created. Check Composer access and try again.',
            );

            $this->validateStarter($starterPath, $stagingRoot);

            $this->run(
                Process::path($starterPath)->timeout(30),
                ['git', 'init', '--initial-branch=main'],
                'The Laravel starter Git repository could not be initialized.',
            );
            $this->run(
                Process::path($starterPath)->timeout(60),
                ['git', 'add', '--all'],
                'The Laravel starter files could not be staged.',
            );
            $this->run(
                Process::path($starterPath)->timeout(30),
                ['git', '-c', 'user.name=Laravel Manager', '-c', 'user.email=laravel-manager@localhost', 'commit', '--message=Initial Laravel application'],
                'The initial Laravel starter commit could not be created.',
            );

            try {
                $repository = $this->github->createPrivateRepository(
                    $connection->access_token,
                    $connection->login,
                    $project->slug,
                    $project->name,
                );
            } catch (Throwable) {
                throw new RuntimeException('GitHub could not create the private repository. Check the connected account permissions and whether the name is already in use.');
            }

            $project->update([
                'repository_name' => $repository['full_name'],
                'repository_url' => $repository['url'],
                'branch' => 'main',
            ]);

            $this->run(
                Process::path($starterPath)->timeout(30),
                ['git', 'remote', 'add', 'origin', $repository['clone_url']],
                'The initial Laravel repository could not be configured.',
            );
            $this->run(
                Process::path($starterPath)->timeout(180)->env($this->gitEnvironment($connection)),
                ['git', 'push', '--set-upstream', 'origin', 'main'],
                'The private GitHub repository was created, but the initial push failed. The repository is saved on this Project; check GitHub access and try again.',
            );

            return $repository;
        } finally {
            $this->removeStagingDirectory($starterPath);
        }
    }

    private function stagingRoot(): string
    {
        $storage = realpath(storage_path());
        $framework = storage_path('framework');

        if ($storage === false || is_link(storage_path()) || is_link($framework)) {
            throw new RuntimeException('The Manager staging directory is unavailable.');
        }

        if (! is_dir($framework) && ! @mkdir($framework, 0750, true) && ! is_dir($framework)) {
            throw new RuntimeException('The Manager staging directory is unavailable.');
        }

        $stagingRoot = $framework.DIRECTORY_SEPARATOR.'laravel-manager-starters';

        if (is_link($stagingRoot)
            || (! is_dir($stagingRoot) && ! @mkdir($stagingRoot, 0700) && ! is_dir($stagingRoot))) {
            throw new RuntimeException('The Manager staging directory is unavailable.');
        }

        if (! @chmod($stagingRoot, 0700)) {
            throw new RuntimeException('The Manager staging directory permissions could not be secured.');
        }

        $realFramework = realpath($framework);
        $realStagingRoot = realpath($stagingRoot);

        if ($realFramework === false
            || $realStagingRoot === false
            || ! str_starts_with($realFramework, $storage.DIRECTORY_SEPARATOR)
            || dirname($realStagingRoot) !== $realFramework
            || (fileperms($realStagingRoot) & 0777) !== 0700
            || ! is_writable($realStagingRoot)) {
            throw new RuntimeException('The Manager staging directory is unsafe or not writable.');
        }

        return $realStagingRoot;
    }

    private function validateStarter(string $path, string $stagingRoot): void
    {
        $realPath = realpath($path);

        if ($realPath === false
            || dirname($realPath) !== $stagingRoot
            || is_link($path)
            || file_exists($path.'/.env')
            || is_link($path.'/.env')
            || file_exists($path.'/.git')
            || is_link($path.'/.git')) {
            throw new RuntimeException('Composer returned an unsafe Laravel starter directory.');
        }

        foreach (['artisan', 'composer.json', '.env.example'] as $file) {
            $filePath = $path.DIRECTORY_SEPARATOR.$file;

            if (is_link($filePath) || ! is_file($filePath)) {
                throw new RuntimeException('Composer did not create the required Laravel starter files.');
            }
        }
    }

    private function run(PendingProcess $process, array $command, string $failure): void
    {
        try {
            $result = $process->run($command);
        } catch (Throwable) {
            throw new RuntimeException($failure);
        }

        if (! $result->successful()) {
            throw new RuntimeException($failure);
        }
    }

    private function gitEnvironment(GitHubConnection $connection): array
    {
        return [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
            'GIT_CONFIG_VALUE_0' => 'AUTHORIZATION: basic '.base64_encode($connection->login.':'.$connection->access_token),
            'GIT_TERMINAL_PROMPT' => '0',
        ];
    }

    private function validateConnection(GitHubConnection $connection): void
    {
        if (! preg_match('/\A[A-Za-z0-9-]+\z/', $connection->login)
            || $connection->access_token === ''
            || preg_match('/[\r\n\0]/', $connection->access_token)) {
            throw new RuntimeException('The stored GitHub connection is invalid.');
        }
    }

    private function removeStagingDirectory(string $path): void
    {
        if (is_link($path)) {
            if (! @unlink($path) || is_link($path)) {
                throw new RuntimeException('The Manager starter staging directory could not be removed.');
            }

            return;
        }

        if (is_dir($path) && ! File::deleteDirectory($path)) {
            throw new RuntimeException('The Manager starter staging directory could not be removed.');
        }

        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('The Manager starter staging directory could not be removed.');
        }
    }
}
