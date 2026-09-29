<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Successful = 'successful';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Successful => 'Successful',
            self::Failed => 'Failed',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Successful => 'secondary',
            self::Failed => 'destructive',
            self::Pending, self::Running => 'outline',
        };
    }
}
