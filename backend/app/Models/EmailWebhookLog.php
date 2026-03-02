<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailWebhookLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'payload',
        'status',
        'error_message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
