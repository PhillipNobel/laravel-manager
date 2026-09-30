<?php

namespace App\Enums;

enum DatabaseEngine: string
{
    case MySql = 'mysql';
    case PostgreSql = 'pgsql';

    public function label(): string
    {
        return match ($this) {
            self::MySql => 'MySQL',
            self::PostgreSql => 'PostgreSQL',
        };
    }
}
