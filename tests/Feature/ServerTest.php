<?php

use App\Models\User;
use Illuminate\Support\Facades\Process;

it('shows environment details and read-only software checks', function () {
    $results = [
        'apache2' => Process::result(output: 'Server version: Apache/2.4.58'),
        'mysql' => Process::result(exitCode: 127),
        'git' => Process::result(output: 'git version 2.46.0'),
        'composer' => Process::result(output: 'Composer version 2.8.0'),
        'node' => Process::result(output: 'v24.0.0'),
        'certbot' => Process::result(exitCode: 127),
    ];

    Process::fake(function ($process) use ($results) {
        return $results[$process->command[0]] ?? new RuntimeException('Unexpected server check.');
    });
    Process::preventStrayProcesses();

    $this->actingAs(User::factory()->create())
        ->get(route('server'))
        ->assertOk()
        ->assertSee('Server')
        ->assertSee('Operating system')
        ->assertSee('PHP runtime')
        ->assertSee('Default PHP version')
        ->assertSee('8.4')
        ->assertSee(PHP_VERSION)
        ->assertSee('Server version: Apache/2.4.58')
        ->assertSee('git version 2.46.0')
        ->assertSee('Not detected');

    Process::assertRanTimes(fn ($process) => $process->command === ['git', '--version']
        && $process->timeout === 2);

    Process::assertRanTimes(fn () => true, 6);
});
