<?php

use App\Support\ProjectProcessEnvironment;

it('removes inherited Manager settings and secrets while retaining OS and worker cache variables', function () {
    $before = $_ENV;
    $_ENV += ['PATH' => '/usr/bin', 'HOME' => '/var/www', 'COMPOSER_HOME' => '/var/cache/laravel-manager/composer',
        'npm_config_cache' => '/var/cache/laravel-manager/npm'];
    $_ENV['MANAGER_PRIVATE_VALUE'] = 'not-for-apps';
    $_ENV['APP_KEY'] = 'manager-key';
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['GIT_CONFIG_VALUE_0'] = 'old-token';
    try {
        $clean = ProjectProcessEnvironment::clean(['GIT_CONFIG_VALUE_0' => 'transient-git-token']);
        expect($clean['APP_KEY'])->toBeFalse()->and($clean['DB_CONNECTION'])->toBeFalse()
            ->and($clean['MANAGER_PRIVATE_VALUE'])->toBeFalse()->and($clean['GIT_CONFIG_VALUE_0'])->toBe('transient-git-token')
            ->and($clean)->not->toHaveKeys(['PATH', 'HOME', 'COMPOSER_HOME', 'npm_config_cache']);
    } finally {
        $_ENV = $before;
    }
});
