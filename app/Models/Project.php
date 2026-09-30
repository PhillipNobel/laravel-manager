<?php

namespace App\Models;

use App\Enums\DatabaseEngine;
use App\Enums\DatabaseStatus;
use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Enums\SslStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'webhook_id', 'webhook_url', 'webhook_status', 'webhook_message',
        'webhook_checked_at', 'webhook_verified_at',
        'name',
        'slug',
        'domain',
        'path',
        'repository_url',
        'repository_name',
        'branch',
        'php_version',
        'database_engine',
        'database_name',
        'database_username',
        'database_status',
        'database_message',
        'status',
        'domain_status',
        'domain_message',
        'ssl_status',
        'ssl_expires_at',
        'ssl_message',
        'provisioning_log',
    ];

    protected function casts(): array
    {
        return [
            'webhook_id' => 'integer',
            'webhook_checked_at' => 'datetime',
            'webhook_verified_at' => 'datetime',
            'status' => ProjectStatus::class,
            'domain_status' => DomainStatus::class,
            'ssl_status' => SslStatus::class,
            'ssl_expires_at' => 'datetime',
            'database_status' => DatabaseStatus::class,
            'database_engine' => DatabaseEngine::class,
        ];
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
