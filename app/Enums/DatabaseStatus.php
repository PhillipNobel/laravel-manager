<?php

namespace App\Enums;

enum DatabaseStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not configured',
            self::Provisioning => 'Creating',
            self::Active => 'Active',
            self::Failed => 'Failed',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Active => 'secondary',
            self::Failed => 'destructive',
            self::Pending, self::Provisioning => 'outline',
        };
    }
}
