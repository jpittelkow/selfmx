<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_QUEUED = 'queued';

    protected $fillable = [
        'user_id',
        'notification_type',
        'channel',
        'status',
        'error',
        'attempt',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
