<?php

namespace App\Enums;

enum SslStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not enabled',
            self::Provisioning => 'Setting up',
            self::Active => 'HTTPS enabled',
            self::Failed => 'Failed',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Active => 'secondary',
            self::Provisioning => 'primary',
            self::Failed => 'destructive',
            self::Pending => 'outline',
        };
    }
}
