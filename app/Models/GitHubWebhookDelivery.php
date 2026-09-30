<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GitHubWebhookDelivery extends Model
{
    protected $table = 'github_webhook_deliveries';

    protected $fillable = [
        'delivery_id',
        'payload_hash',
        'event',
        'repository_name',
        'ref',
        'status',
        'message',
        'deployments_queued',
    ];
}
