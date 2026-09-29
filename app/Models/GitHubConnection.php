<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GitHubConnection extends Model
{
    protected $table = 'github_connections';

    protected $fillable = [
        'github_user_id',
        'login',
        'name',
        'avatar_url',
        'scopes',
        'access_token',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }
}
