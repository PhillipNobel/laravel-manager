<?php

use App\Jobs\PublishProject;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function fakeAvailableServerCommand(PendingProcess $process)
{
    $command = $process->command;
    $binary = $command[0] ?? '';

    if (preg_match('/\A\/usr\/bin\/php(8\.[234])\z/', $binary, $matches) && ($command[1] ?? null) === '--version') {
        return Process::result(output: 'PHP '.$matches[1].'.10.0 (cli)');
    }

    if (preg_match('/\A\/usr\/bin\/php8\.[234]\z/', $binary) && ($command[1] ?? null) === '-m') {
        return Process::result(output: "PDO\npdo_mysql\npdo_pgsql\n");
    }

    if ($binary === '/usr/bin/systemctl' || $binary === '/usr/bin/test') {
        return Process::result();
    }

    if ($binary === '/usr/bin/mysql' && ($command[1] ?? null) === '--version') {
        return Process::result(output: 'mysql  Ver 8.0.42');
    }

    if ($binary === '/usr/bin/pg_isready' || $binary === '/usr/bin/psql') {
        return Process::result(output: 'accepting connections');
    }

    return null;
}

function runQueuedPublicationForTests(): void
{
    for ($i = 0; $i < 8; $i++) {
        $projects = Project::where('publication_status', 'queued')->get();
        if ($projects->isEmpty()) {
            return;
        }
        foreach ($projects as $project) {
            (new PublishProject($project->id, auth()->id(), $project->publication_version))->handle();
        }
    }
}
