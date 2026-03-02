<?php

namespace App\Services;

use App\Models\AuditLog;
use Carbon\Carbon;

class SuspiciousActivityService
{
    /** Failed login threshold: alert if this many in the window. */
    public const FAILED_LOGIN_THRESHOLD = 5;

    /** Failed login window (minutes). */
    public const FAILED_LOGIN_WINDOW_MINUTES = 15;

    /**
     * Check for suspicious patterns and return alerts (each with type, message, count).
     *
     * @return array<int, array{type: string, message: string, count: int}>
     */
    public function check(): array
    {
        $alerts = [];

        $failedLoginCount = AuditLog::where('action', 'like', 'auth.login_failed%')
            ->where('created_at', '>=', Carbon::now()->subMinutes(self::FAILED_LOGIN_WINDOW_MINUTES))
            ->count();

        if ($failedLoginCount >= self::FAILED_LOGIN_THRESHOLD) {
            $alerts[] = [
                'type' => 'failed_logins',
                'message' => sprintf(
                    '%d failed login attempts in the last %d minutes.',
                    $failedLoginCount,
                    self::FAILED_LOGIN_WINDOW_MINUTES
                ),
                'count' => $failedLoginCount,
            ];
        }

        return $alerts;
    }
}
