<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'device_name',
        'user_agent',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'endpoint' => 'encrypted',
            'p256dh' => 'encrypted',
            'auth' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    protected static function booted(): void
    {
        static::saving(function (PushSubscription $model) {
            $model->endpoint_hash = self::hashEndpoint($model->endpoint);
        });
    }

    public static function detectDeviceName(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'Unknown Device';
        }

        if (str_contains($userAgent, 'Mobile') || str_contains($userAgent, 'Android')) {
            if (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
                return str_contains($userAgent, 'iPad') ? 'iPad Safari' : 'iPhone Safari';
            }
            if (str_contains($userAgent, 'Android')) {
                return 'Android ' . (str_contains($userAgent, 'Chrome') ? 'Chrome' : 'Browser');
            }
            return 'Mobile Browser';
        }

        if (str_contains($userAgent, 'Edg')) {
            return 'Desktop Edge';
        }
        if (str_contains($userAgent, 'Chrome')) {
            return 'Desktop Chrome';
        }
        if (str_contains($userAgent, 'Firefox')) {
            return 'Desktop Firefox';
        }
        if (str_contains($userAgent, 'Safari')) {
            return 'Desktop Safari';
        }

        return 'Desktop Browser';
    }
}
