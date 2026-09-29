<?php

namespace App\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not configured',
            self::Active => 'Active',
            self::Failed => 'Failed',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Active => 'secondary',
            self::Failed => 'destructive',
            self::Pending => 'outline',
        };
    }
}
