<?php

namespace App\Support;

class ProjectProcessEnvironment
{
    public static function clean(array $extra = []): array
    {
        // Child applications must load their own .env, never Manager's credentials or configuration.
        $inherited = array_keys($_ENV + $_SERVER + (getenv() ?: []));
        $system = ['PATH', 'HOME', 'USER', 'LOGNAME', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ', 'TMPDIR', 'TMP', 'TEMP',
            'COMPOSER_HOME', 'npm_config_cache', 'SystemRoot'];
        $removed = array_fill_keys(array_diff($inherited, $system), false);

        return array_replace($removed, $extra);
    }
}
