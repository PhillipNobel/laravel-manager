<?php

namespace App\Models;

use App\Enums\DatabaseStatus;
use App\Enums\DomainStatus;
use App\Enums\ProjectStatus;
use App\Enums\SslStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'domain',
        'path',
        'repository_url',
        'repository_name',
        'branch',
        'php_version',
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
            'status' => ProjectStatus::class,
            'domain_status' => DomainStatus::class,
            'ssl_status' => SslStatus::class,
            'ssl_expires_at' => 'datetime',
            'database_status' => DatabaseStatus::class,
        ];
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
