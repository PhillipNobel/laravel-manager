<?php

namespace App\Support;

use App\Enums\DatabaseEngine;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Throwable;

class ServerEnvironment
{
    private const REQUIREMENTS = [
        ['name' => 'Apache', 'command' => ['apache2', '-v'], 'missing' => 'Install Apache.'],
        ['name' => 'Git', 'command' => ['git', '--version'], 'missing' => 'Install Git.'],
        ['name' => 'Composer', 'command' => ['composer', '--version'], 'missing' => 'Install Composer.'],
        ['name' => 'Node.js', 'command' => ['node', '--version'], 'missing' => 'Install Node.js and npm.'],
        ['name' => 'Certbot', 'command' => ['certbot', '--version'], 'missing' => 'Install Certbot.'],
    ];

    public static function operatingSystem(): string
    {
        if (is_readable('/etc/os-release')) {
            $release = parse_ini_file('/etc/os-release', false, INI_SCANNER_RAW);
            $name = is_array($release) ? ($release['PRETTY_NAME'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                return trim($name, " \t\n\r\0\x0B\"'");
            }
        }

        $system = php_uname('s');
        $name = $system === 'Darwin' ? 'macOS' : $system;

        return $name.' '.php_uname('r');
    }

    public static function phpOptions(): array
    {
        return array_map(static function (string $version): array {
            $requirement = self::phpRequirement($version);

            return [
                'value' => $version,
                'available' => $requirement === null,
                'requirement' => $requirement,
            ];
        }, config('manager.php_versions'));
    }

    public static function databaseOptions(string $phpVersion): array
    {
        return array_map(static function (DatabaseEngine $engine) use ($phpVersion): array {
            $requirement = self::databaseRequirement($phpVersion, $engine);

            return [
                'value' => $engine->value,
                'label' => $engine->label(),
                'available' => $requirement === null,
                'requirement' => $requirement,
            ];
        }, DatabaseEngine::cases());
    }

    public static function phpRequirement(string $version): ?string
    {
        if (! in_array($version, config('manager.php_versions'), true)) {
            return 'Choose a supported PHP version.';
        }

        $php = self::run(['/usr/bin/php'.$version, '--version']);
        if (! $php?->successful() || ! str_starts_with(trim($php->output()), 'PHP '.$version.'.')) {
            return "Install PHP {$version} CLI and FPM.";
        }

        if (! self::successful(['/usr/bin/systemctl', 'is-active', '--quiet', 'php'.$version.'-fpm.service'])) {
            return "Start the PHP {$version}-FPM service.";
        }

        if (! self::successful(['/usr/bin/test', '-S', '/run/php/php'.$version.'-fpm.sock'])) {
            return "Start PHP {$version}-FPM so its socket is available.";
        }

        return null;
    }

    public static function databaseRequirement(string $phpVersion, DatabaseEngine $engine): ?string
    {
        $phpRequirement = self::phpRequirement($phpVersion);

        if ($phpRequirement !== null) {
            return $phpRequirement;
        }

        $service = $engine === DatabaseEngine::MySql ? 'mysql.service' : 'postgresql.service';
        if (! self::successful(['/usr/bin/systemctl', 'is-active', '--quiet', $service])) {
            return 'Install and start the '.$engine->label().' service.';
        }

        if ($engine === DatabaseEngine::MySql && ! self::successful(['/usr/bin/mysql', '--version'])) {
            return 'Install the MySQL server and client packages.';
        }

        if ($engine === DatabaseEngine::PostgreSql && ! self::successful(['/usr/bin/pg_isready', '--quiet'])) {
            return 'Start PostgreSQL so it accepts local connections.';
        }

        $module = $engine === DatabaseEngine::MySql ? 'pdo_mysql' : 'pdo_pgsql';
        $modules = self::run(['/usr/bin/php'.$phpVersion, '-m']);
        if (! $modules || ! $modules->successful() || ! in_array($module, array_map('strtolower', preg_split('/\R/', trim($modules->output())) ?: []), true)) {
            return "Install the PHP {$phpVersion} {$engine->label()} driver.";
        }

        return null;
    }

    public static function requirements(): array
    {
        $requirements = array_map(
            static fn (array $requirement): array => self::check(
                $requirement['name'],
                $requirement['command'],
                $requirement['missing'],
            ),
            self::REQUIREMENTS,
        );

        foreach (config('manager.php_versions') as $version) {
            $result = self::run(['/usr/bin/php'.$version, '--version']);
            $requirement = self::phpRequirement($version);

            $requirements[] = [
                'name' => 'PHP '.$version.'-FPM',
                'status' => $requirement === null ? 'available' : 'not-detected',
                'version' => $requirement === null ? self::firstLine($result?->output() ?? '') : $requirement,
            ];
        }

        foreach (DatabaseEngine::cases() as $engine) {
            $service = $engine === DatabaseEngine::MySql ? 'mysql.service' : 'postgresql.service';
            $command = $engine === DatabaseEngine::MySql
                ? ['/usr/bin/mysql', '--version']
                : ['/usr/bin/psql', '--version'];
            $result = self::run($command);
            $available = self::successful(['/usr/bin/systemctl', 'is-active', '--quiet', $service])
                && $result?->successful();

            $requirements[] = [
                'name' => $engine->label(),
                'status' => $available ? 'available' : 'not-detected',
                'version' => $available
                    ? self::firstLine($result->output())
                    : 'Install and start the '.$engine->label().' service.',
            ];
        }

        return $requirements;
    }

    private static function check(string $name, array $command, string $missingRequirement): array
    {
        $result = self::run($command);
        $output = trim($result?->output() ?? '');

        if (! $result?->successful() || $output === '') {
            return [
                'name' => $name,
                'status' => 'not-detected',
                'version' => $missingRequirement,
            ];
        }

        return [
            'name' => $name,
            'status' => 'available',
            'version' => self::firstLine($output),
        ];
    }

    private static function firstLine(string $output): string
    {
        $line = preg_split('/\R/', trim($output), 2)[0] ?? trim($output);

        return mb_substr(trim($line), 0, 160);
    }

    private static function successful(array $command): bool
    {
        $result = self::run($command);

        return $result?->successful() ?? false;
    }

    private static function run(array $command): ?ProcessResult
    {
        try {
            return Process::timeout(2)->run($command);
        } catch (Throwable) {
            return null;
        }
    }
}
