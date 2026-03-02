<?php

namespace App\Services\Notifications;

class NotificationTemplateSampleService
{
    /**
     * Get sample variables for template preview by notification type.
     *
     * @return array<string, mixed>
     */
    public function getSampleVariables(string $type): array
    {
        $appName = config('app.name', 'selfmx');
        $user = [
            'name' => 'Sample User',
            'email' => 'sample@example.com',
        ];

        return match ($type) {
            'backup.completed' => [
                'user' => $user,
                'app_name' => $appName,
                'backup_name' => 'Daily Backup',
            ],
            'backup.failed' => [
                'user' => $user,
                'app_name' => $appName,
                'backup_name' => 'Daily Backup',
                'error_message' => 'Disk full',
            ],
            'auth.login' => [
                'user' => $user,
                'app_name' => $appName,
                'ip' => '192.168.1.1',
                'timestamp' => now()->toDateTimeString(),
            ],
            'auth.password_reset' => [
                'user' => $user,
                'app_name' => $appName,
            ],
            'system.update' => [
                'user' => $user,
                'app_name' => $appName,
                'version' => '1.2.0',
            ],
            'llm.quota_warning' => [
                'user' => $user,
                'app_name' => $appName,
                'usage' => '80',
            ],
            'storage.warning' => [
                'user' => $user,
                'app_name' => $appName,
                'usage' => '85',
                'threshold' => '80',
                'free_formatted' => '5.2 GB',
                'total_formatted' => '50.0 GB',
            ],
            'storage.critical' => [
                'user' => $user,
                'app_name' => $appName,
                'usage' => '96',
                'threshold' => '95',
                'free_formatted' => '2.0 GB',
                'total_formatted' => '50.0 GB',
            ],
            'suspicious_activity' => [
                'user' => $user,
                'app_name' => $appName,
                'alert_summary' => 'Multiple failed logins from 192.168.1.100',
                'alert_count' => '3',
            ],
            'usage.budget_warning' => [
                'user' => $user,
                'app_name' => $appName,
                'integration' => 'LLM',
                'percent' => '85',
                'current_cost' => '42.50',
                'budget' => '50.00',
            ],
            'usage.budget_exceeded' => [
                'user' => $user,
                'app_name' => $appName,
                'integration' => 'LLM',
                'percent' => '112',
                'current_cost' => '56.00',
                'budget' => '50.00',
            ],
            'payment.succeeded' => [
                'user' => $user,
                'app_name' => $appName,
                'amount' => '25.00',
                'currency' => 'USD',
                'description' => 'Monthly subscription',
                'customer_email' => 'customer@example.com',
                'payment_id' => '42',
            ],
            'payment.failed' => [
                'user' => $user,
                'app_name' => $appName,
                'amount' => '25.00',
                'currency' => 'USD',
                'description' => 'Monthly subscription',
                'customer_email' => 'customer@example.com',
                'payment_id' => '43',
                'error_message' => 'Your card was declined.',
            ],
            'payment.refunded' => [
                'user' => $user,
                'app_name' => $appName,
                'amount' => '25.00',
                'refund_amount' => '25.00',
                'currency' => 'USD',
                'description' => 'Monthly subscription',
                'customer_email' => 'customer@example.com',
                'payment_id' => '42',
                'refund_type' => 'Full refund',
            ],
            default => [
                'user' => $user,
                'app_name' => $appName,
            ],
        };
    }
}
