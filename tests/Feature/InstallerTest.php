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
            'PHP-FPM: 8.2, 8.3, and 8.4 (ppa:ondrej/php)',
            'Node.js: 24 LTS',
            'Databases: MySQL and PostgreSQL',
            'Updates: sudo laravel-manager update; version: sudo laravel-manager version',
            'Manager URL: http://SERVER_IP:8080',
            'Firewall: keep Manager port 8080 private or source-IP restricted',
        );
});

it('installs a root-owned update command and keeps updates out of web requests', function () {
    $installer = file_get_contents(base_path('scripts/install.sh'));
    $command = file_get_contents(base_path('scripts/laravel-manager'));
    $syntax = new SymfonyProcess(['/bin/bash', '-n', base_path('scripts/laravel-manager')]);
    $syntax->run();

    expect($syntax->isSuccessful())->toBeTrue()
        ->and($installer)->toContain(
            '/usr/local/bin/laravel-manager',
            'install_manager_command',
            '-type f ! -perm /111 -exec /bin/chmod 0640',
            '-type f -perm /111 -exec /bin/chmod 0750',
        )
        ->and($command)->toContain(
            'Run updates as root: sudo laravel-manager update.',
            'env -i -C "$APP_DIR"',
            'GIT_CONFIG_KEY_0=safe.directory',
            'fetch --prune --tags origin',
            'merge --ff-only FETCH_HEAD',
            'update-incomplete',
            'Resuming an interrupted update',
            'Another Laravel Manager update is already running.',
            'restore_tracked_executable_modes',
            'ls-files --stage',
            'hash-object --',
            "COMPOSER_BIN='/usr/local/bin/composer'",
            '--no-dev --no-interaction --prefer-dist --optimize-autoloader',
            '/usr/bin/npm --prefix',
            ' ci --no-audit --no-fund',
            'migrate --force',
            'artisan optimize',
            'laravel-manager-queue.service',
        )->not->toContain('eval ', 'sudoers');
});

it('rejects unsupported manager command arguments without starting an update', function () {
    $process = new SymfonyProcess(['/bin/bash', base_path('scripts/laravel-manager'), 'shell']);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage:');
});

it('uses the configured manager URL in the installation plan and Laravel environment', function () {
    $managerUrl = 'https://manager.example.test:8080';
    $process = new SymfonyProcess(
        ['/bin/bash', base_path('scripts/install.sh'), '--dry-run'],
        base_path(),
        [
            'LARAVEL_MANAGER_REPOSITORY' => 'https://github.com/acme/laravel-manager.git',
            'MANAGER_URL' => $managerUrl,
        ],
    );
    $process->run();

    $installer = file_get_contents(base_path('scripts/install.sh'));

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain("Manager URL: {$managerUrl}")
        ->and($installer)->toContain('APP_URL=${MANAGER_URL}')
        ->not->toContain('APP_URL=http://localhost:8080');
});

it('rejects malformed manager URLs before showing the installation plan', function (string $managerUrl) {
    $process = new SymfonyProcess(
        ['/bin/bash', base_path('scripts/install.sh'), '--dry-run'],
        base_path(),
        [
            'LARAVEL_MANAGER_REPOSITORY' => 'https://github.com/acme/laravel-manager.git',
            'MANAGER_URL' => $managerUrl,
        ],
    );
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('MANAGER_URL must be an HTTP or HTTPS URL with a hostname or IPv4 address and optional port.');
})->with([
    'unexpected path' => 'http://manager.example.test/path',
    'missing host' => 'http://:8080',
    'unsupported scheme' => 'ftp://manager.example.test',
    'port out of range' => 'http://manager.example.test:65536',
    'shell metacharacter' => 'http://manager.example.test/$(id)',
]);

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
            'SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"',
            'AllowOverride All',
            'Require all granted',
        )->not->toContain('DocumentRoot "/opt/laravel-manager"');
});

it('keeps PHP-FPM selection inside each application virtual host', function () {
    $apacheHelper = file_get_contents(base_path('scripts/laravel-manager-apache'));
    $managerVhost = file_get_contents(base_path('scripts/laravel-manager-vhost.conf'));

    expect($apacheHelper)->toContain('SUPPORTED_PHP_VERSIONS = {"8.2", "8.3", "8.4"}')
        ->and($managerVhost)->toContain('php8.3-fpm.sock')
        ->and(file_get_contents(base_path('scripts/install.sh')))->not->toContain('a2enconf php8.3-fpm');
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

it('installs all supported PHP runtimes and database drivers at setup time', function () {
    $installer = file_get_contents(base_path('scripts/install.sh'));

    expect($installer)->toContain(
        'add-apt-repository --yes ppa:ondrej/php',
        'for version in 8.2 8.3 8.4',
        '"php${version}-fpm"',
        '"php${version}-mysql"',
        '"php${version}-pgsql"',
        'postgresql.service',
    )->not->toContain('MANAGER_PHP_VERSIONS=8.3');
});

it('limits database helper sudo access to one anchored provision argument pattern', function () {
    $sudoers = trim(file_get_contents(base_path('scripts/laravel-manager-database.sudoers')));

    expect($sudoers)->toBe(
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/laravel-manager-database ^provision [a-z0-9]([a-z0-9-]{0,61}[a-z0-9])? (mysql|pgsql)$'
    );
});

it('uses Ubuntu apache2ctl path for configuration validation', function () {
    $installer = file_get_contents(base_path('scripts/install.sh'));

    expect($installer)->toContain('/usr/sbin/apache2ctl configtest')
        ->not->toContain('/usr/bin/apache2ctl');
});
