<?php

namespace App\Actions\Projects;

use App\Enums\DatabaseEngine;
use App\Enums\DatabaseStatus;
use App\Enums\ProjectStatus;
use App\Models\AppSetting;
use App\Models\Project;
use App\Support\ProjectDatabaseNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

class ConfigureProjectDatabase
{
    public function handle(Project $project): DatabaseStatus
    {
        if ($project->database_status === DatabaseStatus::Active) {
            return DatabaseStatus::Active;
        }

        if ($project->status !== ProjectStatus::Active || ! filled($project->path)) {
            if ($project->database_status !== DatabaseStatus::Active) {
                $project->update([
                    'database_status' => DatabaseStatus::Pending,
                    'database_message' => 'Finish application provisioning before creating its database.',
                ]);
            }

            return $project->database_status ?? DatabaseStatus::Pending;
        }

        $problem = $this->projectPathProblem($project);
        if ($problem !== null) {
            return $this->failed($project, $problem);
        }

        try {
            $names = ProjectDatabaseNames::for($project->slug);
        } catch (InvalidArgumentException) {
            return $this->failed($project, 'The application slug is invalid. Review the project before creating its database.');
        }

        $engine = $project->database_engine;
        if (! $engine instanceof DatabaseEngine) {
            return $this->failed($project, 'Choose a supported database engine before creating the application database.');
        }

        $claimed = DB::transaction(function () use ($project, $names): bool {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);

            if (in_array($lockedProject->database_status, [DatabaseStatus::Active, DatabaseStatus::Provisioning], true)) {
                return false;
            }

            $lockedProject->update([
                ...$names,
                'database_status' => DatabaseStatus::Provisioning,
                'database_message' => 'Creating the application database and checking its connection.',
            ]);

            return true;
        });

        if (! $claimed) {
            $project->refresh();

            return $project->database_status;
        }

        try {
            $result = Process::timeout(120)->run([
                '/usr/bin/sudo',
                '-n',
                config('manager.database_helper'),
                'provision',
                $project->slug,
                $engine->value,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Project database provisioning failed.', [
                'project_id' => $project->id,
                'reason' => $exception::class,
            ]);

            return $this->failed($project, 'The database could not be created. Check the Laravel Manager log, then retry.');
        }

        if (! $result->successful() || trim($result->output()) !== 'READY') {
            $errorCode = $this->errorCode($result->errorOutput() ?: $result->output());
            Log::warning('Project database provisioning failed.', [
                'project_id' => $project->id,
                'exit_code' => $result->exitCode(),
                'error_code' => $errorCode,
            ]);

            return $this->failed($project, $this->failureMessage($errorCode));
        }

        $project->update([
            'database_status' => DatabaseStatus::Active,
            'database_message' => 'Database is ready.',
        ]);

        return DatabaseStatus::Active;
    }

    private function failed(Project $project, string $message): DatabaseStatus
    {
        $project->update([
            'database_status' => DatabaseStatus::Failed,
            'database_message' => $message,
        ]);

        return DatabaseStatus::Failed;
    }

    private function errorCode(string $output): ?string
    {
        if (! preg_match('/\AERROR: (MYSQL_UNAVAILABLE|POSTGRESQL_UNAVAILABLE|GIT_CHECK_FAILED|ENV_NOT_IGNORED|INVALID_PROJECT|UNSAFE_ENV|DATABASE_EXISTS|PROVISION_FAILED|CLEANUP_REQUIRED)\z/', trim($output), $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function failureMessage(?string $errorCode): string
    {
        return match ($errorCode) {
            'MYSQL_UNAVAILABLE' => 'Laravel Manager could not access MySQL. Check MySQL and its local socket configuration, then retry.',
            'POSTGRESQL_UNAVAILABLE' => 'PostgreSQL is not available. Start the PostgreSQL service, then retry.',
            'GIT_CHECK_FAILED' => 'Laravel Manager could not verify that Git ignores the application .env file. Check the repository, then retry.',
            'ENV_NOT_IGNORED' => 'The repository does not ignore its .env file. Add .env to .gitignore before creating a database.',
            'INVALID_PROJECT' => 'The application directory or environment file is missing or unsafe. Restore it before retrying.',
            'UNSAFE_ENV' => 'The application .env file could not be updated securely. Check its ownership and permissions, then retry.',
            'DATABASE_EXISTS' => 'A database or database account with this name already exists. Have an administrator review it before retrying.',
            'CLEANUP_REQUIRED' => 'Database setup could not be fully rolled back. Have an administrator review MySQL and the app .env before retrying.',
            'PROVISION_FAILED' => 'The database engine could not create or verify the application database connection. Check the Laravel Manager log, then retry.',
            default => 'The database could not be created. Check the Laravel Manager log, then retry.',
        };
    }

    private function projectPathProblem(Project $project): ?string
    {
        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $project->slug)) {
            return 'The application slug is invalid. Review the project before creating its database.';
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
            return 'Use a safe, absolute applications directory in Settings before creating a database.';
        }

        $realRoot = realpath($applicationsRoot);
        if ($realRoot === false || $realRoot === DIRECTORY_SEPARATOR || ! is_dir($realRoot) || is_link($applicationsRoot)) {
            return 'The configured applications directory is unavailable or unsafe.';
        }

        $expectedPath = rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$project->slug;
        if (is_link($project->path) || realpath($project->path) !== $expectedPath) {
            return 'The application directory is missing or outside the configured applications directory.';
        }

        return null;
    }
}
