<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';
    case Disabled = 'disabled';
}
