<?php

/**
 * Novu Notification Infrastructure
 *
 * When enabled, notifications are sent via Novu (Cloud or self-hosted).
 * When disabled, the local NotificationOrchestrator is used.
 */

return [
    'enabled' => env('NOVU_ENABLED', false),
    'api_key' => env('NOVU_API_KEY', ''),
    'app_identifier' => env('NOVU_APP_IDENTIFIER', ''),
    'api_url' => rtrim(env('NOVU_API_URL', 'https://api.novu.co'), '/'),
    'socket_url' => rtrim(env('NOVU_SOCKET_URL', 'https://ws.novu.co'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Workflow mapping (notification type => Novu workflow identifier)
    |--------------------------------------------------------------------------
    */
    'workflow_map' => [
        'backup.completed' => 'backup-completed',
        'backup.failed' => 'backup-failed',
        'auth.login' => 'auth-login',
        'auth.password_reset' => 'auth-password-reset',
        'system.update' => 'system-update',
        'llm.quota_warning' => 'llm-quota-warning',
        'storage.warning' => 'storage-warning',
        'storage.critical' => 'storage-critical',
        'suspicious_activity' => 'suspicious-activity',
        'usage.budget_warning' => 'usage-budget-warning',
        'usage.budget_exceeded' => 'usage-budget-exceeded',
        'payment.succeeded' => 'payment-succeeded',
        'payment.failed' => 'payment-failed',
        'payment.refunded' => 'payment-refunded',
    ],
];
