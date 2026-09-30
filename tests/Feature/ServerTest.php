<?php

use App\Models\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('shows service and PHP-FPM availability checks for supported app choices', function () {
    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        $binary = $command[0] ?? '';

        if ($binary === 'apache2') {
            return Process::result(output: 'Server version: Apache/2.4.58');
        }

        if ($binary === 'git') {
            return Process::result(output: 'git version 2.46.0');
        }

        if ($binary === 'composer') {
            return Process::result(output: 'Composer version 2.8.0');
        }

        if ($binary === 'node') {
            return Process::result(output: 'v24.0.0');
        }

        if ($binary === 'certbot') {
            return Process::result(exitCode: 127);
        }

        if ($binary === '/usr/bin/php8.2') {
            return Process::result(exitCode: 127);
        }

        if (in_array($binary, ['/usr/bin/php8.3', '/usr/bin/php8.4'], true)) {
            return Process::result(output: 'PHP '.substr($binary, -3).'.10.0 (cli)');
        }

        if ($binary === '/usr/bin/systemctl') {
            return Process::result(exitCode: in_array('php8.2-fpm.service', $command, true) ? 3 : 0);
        }

        if ($binary === '/usr/bin/test') {
            return Process::result();
        }

        if ($binary === '/usr/bin/mysql') {
            return Process::result(output: 'mysql Ver 8.0.42');
        }

        if ($binary === '/usr/bin/pg_isready') {
            return Process::result(output: 'accepting connections');
        }

        if ($binary === '/usr/bin/psql') {
            return Process::result(output: 'psql (PostgreSQL) 16.9');
        }

        throw new RuntimeException('Unexpected server check: '.implode(' ', $command));
    });
    Process::preventStrayProcesses();

    $this->actingAs(User::factory()->create())
        ->get(route('server'))
        ->assertOk()
        ->assertSee('Server')
        ->assertSee('Operating system')
        ->assertSee('PHP runtime')
        ->assertSee('Default PHP version')
        ->assertSee(config('manager.default_php_version'))
        ->assertSee(PHP_VERSION)
        ->assertSee('PHP 8.2-FPM')
        ->assertSee('PHP 8.3-FPM')
        ->assertSee('PHP 8.4-FPM')
        ->assertSee('PostgreSQL')
        ->assertSee('Server version: Apache/2.4.58')
        ->assertSee('git version 2.46.0')
        ->assertSee('Install PHP 8.2 CLI and FPM.')
        ->assertSee('Not detected');

    Process::assertRanTimes(fn (PendingProcess $process) => $process->command === ['git', '--version']
        && $process->timeout === 2);
});
