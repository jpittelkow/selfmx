<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Models\SystemSetting;
use App\Models\User;

class NotificationRateLimiter
{
    private ?array $cachedSettings = null;

    /**
     * Check if the user has exceeded the rate limit for a channel.
     * Returns true if the send should be blocked.
     */
    public function isLimited(User $user, string $channel): bool
    {
        $settings = $this->getSettings();

        if (!$settings['enabled']) {
            return false;
        }

        $count = NotificationDelivery::where('user_id', $user->id)
            ->where('channel', $channel)
            ->whereIn('status', [NotificationDelivery::STATUS_SUCCESS, NotificationDelivery::STATUS_QUEUED])
            ->where('attempted_at', '>=', now()->subMinutes($settings['window_minutes']))
            ->count();

        return $count >= $settings['max'];
    }

    /**
     * Batch-fetch rate limit settings once per request.
     */
    private function getSettings(): array
    {
        if ($this->cachedSettings === null) {
            $this->cachedSettings = [
                'enabled' => filter_var(
                    SystemSetting::get('rate_limit_enabled', false, 'notifications'),
                    FILTER_VALIDATE_BOOLEAN
                ),
                'max' => (int) SystemSetting::get('rate_limit_max', 10, 'notifications'),
                'window_minutes' => (int) SystemSetting::get('rate_limit_window_minutes', 60, 'notifications'),
            ];
        }

        return $this->cachedSettings;
    }
}
