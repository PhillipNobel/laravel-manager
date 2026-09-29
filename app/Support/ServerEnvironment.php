<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

class ServerEnvironment
{
    private const REQUIREMENTS = [
        ['name' => 'Apache', 'command' => ['apache2', '-v']],
        ['name' => 'MySQL', 'command' => ['mysql', '--version']],
        ['name' => 'Git', 'command' => ['git', '--version']],
        ['name' => 'Composer', 'command' => ['composer', '--version']],
        ['name' => 'Node.js', 'command' => ['node', '--version']],
        ['name' => 'Certbot', 'command' => ['certbot', '--version']],
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

    public static function requirements(): array
    {
        return array_map(
            static fn (array $requirement): array => self::check(
                $requirement['name'],
                $requirement['command'],
            ),
            self::REQUIREMENTS,
        );
    }

    private static function check(string $name, array $command): array
    {
        try {
            $result = Process::timeout(2)->run($command);
        } catch (Throwable) {
            return [
                'name' => $name,
                'status' => 'not-detected',
                'version' => 'Not detected',
            ];
        }

        $output = trim($result->output());

        if (! $result->successful() || $output === '') {
            return [
                'name' => $name,
                'status' => 'not-detected',
                'version' => 'Not detected',
            ];
        }

        $firstLine = preg_split('/\R/', $output, 2)[0] ?? $output;

        return [
            'name' => $name,
            'status' => 'available',
            'version' => mb_substr(trim($firstLine), 0, 160),
        ];
    }
}
