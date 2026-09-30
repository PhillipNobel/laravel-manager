<?php

namespace App\Support;

use InvalidArgumentException;

class ProjectDatabaseNames
{
    public static function for(string $slug): array
    {
        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $slug)) {
            throw new InvalidArgumentException('The application slug is invalid.');
        }

        $hash = substr(hash('sha256', $slug), 0, 8);
        $safeSlug = str_replace('-', '_', $slug);

        return [
            'database_name' => 'lm_'.substr($safeSlug, 0, 51).'_'.$hash,
            'database_username' => 'lm_'.substr($safeSlug, 0, 20).'_'.$hash,
        ];
    }
}
