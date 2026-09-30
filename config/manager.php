<?php

return [
    'server_hostname' => env('MANAGER_SERVER_HOSTNAME', gethostname() ?: 'localhost'),
    'public_ip' => env('MANAGER_PUBLIC_IP', ''),
    'base_domain' => env('MANAGER_BASE_DOMAIN', 'apps.example.com'),
    'applications_directory' => env('MANAGER_APPLICATIONS_DIRECTORY', '/var/www/apps'),
    'default_php_version' => env('MANAGER_DEFAULT_PHP_VERSION', '8.3'),
    'manager_url' => env('MANAGER_URL', env('APP_URL', 'http://localhost')),
    'php_versions' => ['8.2', '8.3', '8.4'],
    'local_admin_name' => env('LOCAL_ADMIN_NAME'),
    'local_admin_email' => env('LOCAL_ADMIN_EMAIL'),
    'local_admin_password' => env('LOCAL_ADMIN_PASSWORD'),
    'apache_helper' => env('MANAGER_APACHE_HELPER', '/usr/local/sbin/laravel-manager-apache'),
    'database_helper' => env('MANAGER_DATABASE_HELPER', '/usr/local/sbin/laravel-manager-database'),
];
