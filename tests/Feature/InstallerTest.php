<?php

use Symfony\Component\Process\Process as SymfonyProcess;

it('prints the Ubuntu installation plan without changing the host', function () {
    $process = new SymfonyProcess(
        ['/bin/bash', base_path('scripts/install.sh'), '--dry-run'],
        base_path(),
        ['LARAVEL_MANAGER_REPOSITORY' => 'https://github.com/acme/laravel-manager.git'],
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain(
            'Dry run; no system changes will be made.',
            'Target: Ubuntu 24.04 LTS',
            'Repository: https://github.com/acme/laravel-manager.git',
            'PHP-FPM: 8.3',
            'Node.js: 24 LTS',
            'Manager URL: http://SERVER_IP:8080',
            'Firewall: allow TCP ports 80, 443, and 8080',
        );
});

it('rejects a non-GitHub repository URL before showing the installation plan', function () {
    $process = new SymfonyProcess(
        ['/bin/bash', base_path('scripts/install.sh'), '--dry-run'],
        base_path(),
        ['LARAVEL_MANAGER_REPOSITORY' => 'https://example.com/acme/laravel-manager.git'],
    );
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('LARAVEL_MANAGER_REPOSITORY must be an HTTPS GitHub repository URL.');
});

it('rejects malformed GitHub repository paths', function (string $repositoryUrl) {
    $process = new SymfonyProcess(
        ['/bin/bash', base_path('scripts/install.sh'), '--dry-run'],
        base_path(),
        ['LARAVEL_MANAGER_REPOSITORY' => $repositoryUrl],
    );
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('LARAVEL_MANAGER_REPOSITORY must be an HTTPS GitHub repository URL.');
})->with([
    'https://github.com/.owner/repository.git',
    'https://github.com/owner./repository.git',
    'https://github.com/owner/repository?fork=true',
    'https://github.com/owner/repository/extra',
    'https://user@github.com/owner/repository.git',
]);

it('serves the manager from its public directory on port 8080', function () {
    $portConfiguration = file_get_contents(base_path('scripts/laravel-manager-port.conf'));
    $siteConfiguration = file_get_contents(base_path('scripts/laravel-manager-vhost.conf'));

    expect($portConfiguration)->toContain('Listen 8080')
        ->and($siteConfiguration)->toContain(
            '<VirtualHost *:8080>',
            'DocumentRoot "/opt/laravel-manager/public"',
            '<Directory "/opt/laravel-manager/public">',
            'AllowOverride All',
            'Require all granted',
        )->not->toContain('DocumentRoot "/opt/laravel-manager"');
});

it('runs the persistent database queue worker as www-data', function () {
    $unit = file_get_contents(base_path('scripts/laravel-manager-queue.service'));

    expect($unit)->toContain(
        'User=www-data',
        'Group=www-data',
        'WorkingDirectory=/opt/laravel-manager',
        'queue:work database --sleep=3 --tries=1 --timeout=3600 --max-time=3600',
        'Restart=always',
        'TimeoutStopSec=3700',
    )->not->toContain('User=root');
});
