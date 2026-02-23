<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class NotificationTemplateSeeder extends Seeder
{
    /**
     * Default template definitions. Each type has push, inapp, chat, email.
     */
    public static function defaults(): array
    {
        return [
            // backup.completed
            ['type' => 'backup.completed', 'channel_group' => 'push', 'title' => '{{app_name}}: Backup complete', 'body' => 'Backup "{{backup_name}}" finished successfully.', 'variables' => ['app_name', 'backup_name', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'backup.completed', 'channel_group' => 'inapp', 'title' => 'Backup complete', 'body' => 'Backup "{{backup_name}}" finished successfully.', 'variables' => ['app_name', 'backup_name', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'backup.completed', 'channel_group' => 'chat', 'title' => '{{app_name}}: Backup complete', 'body' => 'Backup "{{backup_name}}" finished successfully.', 'variables' => ['app_name', 'backup_name', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'backup.completed', 'channel_group' => 'email', 'title' => '{{app_name}}: Backup complete', 'body' => '<p>Hi {{user.name}},</p><p>Backup "{{backup_name}}" finished successfully.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'backup_name', 'user.name'], 'is_system' => true, 'is_active' => true],
            // backup.failed
            ['type' => 'backup.failed', 'channel_group' => 'push', 'title' => '{{app_name}}: Backup failed', 'body' => 'Backup "{{backup_name}}" failed: {{error_message}}', 'variables' => ['app_name', 'backup_name', 'error_message', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'backup.failed', 'channel_group' => 'inapp', 'title' => 'Backup failed', 'body' => 'Backup "{{backup_name}}" failed: {{error_message}}', 'variables' => ['app_name', 'backup_name', 'error_message', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'backup.failed', 'channel_group' => 'chat', 'title' => '{{app_name}}: Backup failed', 'body' => 'Backup "{{backup_name}}" failed: {{error_message}}', 'variables' => ['app_name', 'backup_name', 'error_message', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'backup.failed', 'channel_group' => 'email', 'title' => '{{app_name}}: Backup failed', 'body' => '<p>Hi {{user.name}},</p><p>Backup "{{backup_name}}" failed:</p><p>{{error_message}}</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'backup_name', 'error_message', 'user.name'], 'is_system' => true, 'is_active' => true],
            // auth.login
            ['type' => 'auth.login', 'channel_group' => 'push', 'title' => '{{app_name}}: New sign-in', 'body' => 'New sign-in from {{ip}} at {{timestamp}}.', 'variables' => ['app_name', 'ip', 'timestamp', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'auth.login', 'channel_group' => 'inapp', 'title' => 'New sign-in', 'body' => 'New sign-in from {{ip}} at {{timestamp}}.', 'variables' => ['app_name', 'ip', 'timestamp', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'auth.login', 'channel_group' => 'chat', 'title' => '{{app_name}}: New sign-in', 'body' => 'New sign-in from {{ip}} at {{timestamp}}.', 'variables' => ['app_name', 'ip', 'timestamp', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'auth.login', 'channel_group' => 'email', 'title' => '{{app_name}}: New sign-in', 'body' => '<p>Hi {{user.name}},</p><p>A new sign-in to your account was detected from {{ip}} at {{timestamp}}.</p><p>If this wasn\'t you, please change your password immediately.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'ip', 'timestamp', 'user.name'], 'is_system' => true, 'is_active' => true],
            // auth.password_reset
            ['type' => 'auth.password_reset', 'channel_group' => 'push', 'title' => '{{app_name}}: Password changed', 'body' => 'Your password was changed. If this wasn\'t you, contact support.', 'variables' => ['app_name', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'auth.password_reset', 'channel_group' => 'inapp', 'title' => 'Password changed', 'body' => 'Your password was changed. If this wasn\'t you, contact support.', 'variables' => ['app_name', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'auth.password_reset', 'channel_group' => 'chat', 'title' => '{{app_name}}: Password changed', 'body' => 'Your password was changed. If this wasn\'t you, contact support.', 'variables' => ['app_name', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'auth.password_reset', 'channel_group' => 'email', 'title' => '{{app_name}}: Password changed', 'body' => '<p>Hi {{user.name}},</p><p>Your password was changed. If this wasn\'t you, contact support immediately.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'user.name'], 'is_system' => true, 'is_active' => true],
            // system.update
            ['type' => 'system.update', 'channel_group' => 'push', 'title' => '{{app_name}}: Update available', 'body' => 'A new version ({{version}}) is ready to install.', 'variables' => ['app_name', 'version', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'system.update', 'channel_group' => 'inapp', 'title' => 'Update available', 'body' => 'A new version ({{version}}) is ready to install.', 'variables' => ['app_name', 'version', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'system.update', 'channel_group' => 'chat', 'title' => '{{app_name}}: Update available', 'body' => 'A new version ({{version}}) is ready to install.', 'variables' => ['app_name', 'version', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'system.update', 'channel_group' => 'email', 'title' => '{{app_name}}: Update available', 'body' => '<p>Hi {{user.name}},</p><p>A new version ({{version}}) is ready to install.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'version', 'user.name'], 'is_system' => true, 'is_active' => true],
            // llm.quota_warning
            ['type' => 'llm.quota_warning', 'channel_group' => 'push', 'title' => '{{app_name}}: Quota warning', 'body' => 'You have used {{usage}}% of your API quota.', 'variables' => ['app_name', 'usage', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'llm.quota_warning', 'channel_group' => 'inapp', 'title' => 'Quota warning', 'body' => 'You have used {{usage}}% of your API quota.', 'variables' => ['app_name', 'usage', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'llm.quota_warning', 'channel_group' => 'chat', 'title' => '{{app_name}}: Quota warning', 'body' => 'You have used {{usage}}% of your API quota.', 'variables' => ['app_name', 'usage', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'llm.quota_warning', 'channel_group' => 'email', 'title' => '{{app_name}}: Quota warning', 'body' => '<p>Hi {{user.name}},</p><p>You have used {{usage}}% of your API quota.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'usage', 'user.name'], 'is_system' => true, 'is_active' => true],
            // storage.warning
            ['type' => 'storage.warning', 'channel_group' => 'push', 'title' => '{{app_name}}: Storage warning', 'body' => 'Storage usage is at {{usage}}% (threshold: {{threshold}}%). Free: {{free_formatted}} of {{total_formatted}}.', 'variables' => ['app_name', 'usage', 'threshold', 'free_formatted', 'total_formatted', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'storage.warning', 'channel_group' => 'inapp', 'title' => 'Storage warning', 'body' => 'Storage usage is at {{usage}}% (threshold: {{threshold}}%). Free: {{free_formatted}} of {{total_formatted}}.', 'variables' => ['app_name', 'usage', 'threshold', 'free_formatted', 'total_formatted', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'storage.warning', 'channel_group' => 'chat', 'title' => '{{app_name}}: Storage warning', 'body' => 'Storage usage is at {{usage}}% (threshold: {{threshold}}%). Free: {{free_formatted}} of {{total_formatted}}.', 'variables' => ['app_name', 'usage', 'threshold', 'free_formatted', 'total_formatted', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'storage.warning', 'channel_group' => 'email', 'title' => '{{app_name}}: Storage warning', 'body' => '<p>Hi {{user.name}},</p><p>Storage usage is at {{usage}}% (threshold: {{threshold}}%).</p><p>Free: {{free_formatted}} of {{total_formatted}}.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'usage', 'threshold', 'free_formatted', 'total_formatted', 'user.name'], 'is_system' => true, 'is_active' => true],
            // storage.critical
            ['type' => 'storage.critical', 'channel_group' => 'push', 'title' => '{{app_name}}: Storage critical', 'body' => 'Storage usage is at {{usage}}% (critical). Free: {{free_formatted}} of {{total_formatted}}. Take action immediately.', 'variables' => ['app_name', 'usage', 'threshold', 'free_formatted', 'total_formatted', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'storage.critical', 'channel_group' => 'inapp', 'title' => 'Storage critical', 'body' => 'Storage usage is at {{usage}}% (critical). Free: {{free_formatted}} of {{total_formatted}}. Take action immediately.', 'variables' => ['app_name', 'usage', 'threshold', 'free_formatted', 'total_formatted', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'storage.critical', 'channel_group' => 'chat', 'title' => '{{app_name}}: Storage critical', 'body' => 'Storage usage is at {{usage}}% (critical). Free: {{free_formatted}} of {{total_formatted}}. Take action immediately.', 'variables' => ['app_name', 'usage', 'threshold', 'free_formatted', 'total_formatted', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'storage.critical', 'channel_group' => 'email', 'title' => '{{app_name}}: Storage critical', 'body' => '<p>Hi {{user.name}},</p><p><strong>Storage usage is at {{usage}}% (critical).</strong></p><p>Free: {{free_formatted}} of {{total_formatted}}. Take action immediately.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'usage', 'threshold', 'free_formatted', 'total_formatted', 'user.name'], 'is_system' => true, 'is_active' => true],
            // suspicious_activity
            ['type' => 'suspicious_activity', 'channel_group' => 'push', 'title' => '{{app_name}}: Suspicious activity', 'body' => 'Suspicious activity detected: {{alert_summary}}', 'variables' => ['app_name', 'alert_summary', 'alert_count', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'suspicious_activity', 'channel_group' => 'inapp', 'title' => 'Suspicious activity detected', 'body' => '{{alert_count}} suspicious pattern(s) detected: {{alert_summary}}', 'variables' => ['app_name', 'alert_summary', 'alert_count', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'suspicious_activity', 'channel_group' => 'chat', 'title' => '{{app_name}}: Suspicious activity', 'body' => '{{alert_count}} suspicious pattern(s) detected: {{alert_summary}}', 'variables' => ['app_name', 'alert_summary', 'alert_count', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'suspicious_activity', 'channel_group' => 'email', 'title' => '{{app_name}}: Suspicious activity detected', 'body' => '<p>Hi {{user.name}},</p><p><strong>{{alert_count}} suspicious pattern(s) detected:</strong></p><p>{{alert_summary}}</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'alert_summary', 'alert_count', 'user.name'], 'is_system' => true, 'is_active' => true],
            // usage.budget_warning
            ['type' => 'usage.budget_warning', 'channel_group' => 'push', 'title' => '{{app_name}}: {{integration}} budget warning', 'body' => '{{integration}} usage at {{percent}}% of monthly budget (${{current_cost}} of ${{budget}}).', 'variables' => ['app_name', 'integration', 'percent', 'current_cost', 'budget', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'usage.budget_warning', 'channel_group' => 'inapp', 'title' => '{{integration}} budget warning', 'body' => '{{integration}} usage has reached {{percent}}% of the monthly budget (${{current_cost}} of ${{budget}}).', 'variables' => ['app_name', 'integration', 'percent', 'current_cost', 'budget', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'usage.budget_warning', 'channel_group' => 'chat', 'title' => '{{app_name}}: {{integration}} budget warning', 'body' => '{{integration}} usage has reached {{percent}}% of the monthly budget (${{current_cost}} of ${{budget}}).', 'variables' => ['app_name', 'integration', 'percent', 'current_cost', 'budget', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'usage.budget_warning', 'channel_group' => 'email', 'title' => '{{app_name}}: {{integration}} budget warning', 'body' => '<p>Hi {{user.name}},</p><p>{{integration}} usage has reached {{percent}}% of the monthly budget (${{current_cost}} of ${{budget}}).</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'integration', 'percent', 'current_cost', 'budget', 'user.name'], 'is_system' => true, 'is_active' => true],
            // usage.budget_exceeded
            ['type' => 'usage.budget_exceeded', 'channel_group' => 'push', 'title' => '{{app_name}}: {{integration}} budget exceeded', 'body' => '{{integration}} budget exceeded: {{percent}}% used (${{current_cost}} of ${{budget}}).', 'variables' => ['app_name', 'integration', 'percent', 'current_cost', 'budget', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'usage.budget_exceeded', 'channel_group' => 'inapp', 'title' => '{{integration}} budget exceeded', 'body' => '{{integration}} usage has exceeded the monthly budget at {{percent}}% (${{current_cost}} of ${{budget}}).', 'variables' => ['app_name', 'integration', 'percent', 'current_cost', 'budget', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'usage.budget_exceeded', 'channel_group' => 'chat', 'title' => '{{app_name}}: {{integration}} budget exceeded', 'body' => '{{integration}} usage has exceeded the monthly budget at {{percent}}% (${{current_cost}} of ${{budget}}).', 'variables' => ['app_name', 'integration', 'percent', 'current_cost', 'budget', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'usage.budget_exceeded', 'channel_group' => 'email', 'title' => '{{app_name}}: {{integration}} budget exceeded', 'body' => '<p>Hi {{user.name}},</p><p><strong>{{integration}} usage has exceeded the monthly budget</strong> at {{percent}}% (${{current_cost}} of ${{budget}}).</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'integration', 'percent', 'current_cost', 'budget', 'user.name'], 'is_system' => true, 'is_active' => true],
            // payment.succeeded
            ['type' => 'payment.succeeded', 'channel_group' => 'push', 'title' => '{{app_name}}: Payment received', 'body' => 'Payment of {{amount}} {{currency}} succeeded.', 'variables' => ['app_name', 'amount', 'currency', 'description', 'customer_email', 'payment_id', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.succeeded', 'channel_group' => 'inapp', 'title' => 'Payment received', 'body' => 'Payment of {{amount}} {{currency}} succeeded. {{description}}', 'variables' => ['app_name', 'amount', 'currency', 'description', 'customer_email', 'payment_id', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.succeeded', 'channel_group' => 'chat', 'title' => '{{app_name}}: Payment received', 'body' => 'Payment of {{amount}} {{currency}} succeeded. Description: {{description}}. Payment ID: {{payment_id}}.', 'variables' => ['app_name', 'amount', 'currency', 'description', 'customer_email', 'payment_id', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.succeeded', 'channel_group' => 'email', 'title' => '{{app_name}}: Payment received', 'body' => '<p>Hi {{user.name}},</p><p>Payment of {{amount}} {{currency}} succeeded.</p><p>{{description}}</p><p>Payment ID: {{payment_id}}</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'amount', 'currency', 'description', 'customer_email', 'payment_id', 'user.name'], 'is_system' => true, 'is_active' => true],
            // payment.failed
            ['type' => 'payment.failed', 'channel_group' => 'push', 'title' => '{{app_name}}: Payment failed', 'body' => 'Payment of {{amount}} {{currency}} failed.', 'variables' => ['app_name', 'amount', 'currency', 'description', 'customer_email', 'payment_id', 'error_message', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.failed', 'channel_group' => 'inapp', 'title' => 'Payment failed', 'body' => 'Payment of {{amount}} {{currency}} failed. {{error_message}}', 'variables' => ['app_name', 'amount', 'currency', 'description', 'customer_email', 'payment_id', 'error_message', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.failed', 'channel_group' => 'chat', 'title' => '{{app_name}}: Payment failed', 'body' => 'Payment of {{amount}} {{currency}} failed. Error: {{error_message}}. Payment ID: {{payment_id}}.', 'variables' => ['app_name', 'amount', 'currency', 'description', 'customer_email', 'payment_id', 'error_message', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.failed', 'channel_group' => 'email', 'title' => '{{app_name}}: Payment failed', 'body' => '<p>Hi {{user.name}},</p><p>Payment of {{amount}} {{currency}} failed.</p><p>{{error_message}}</p><p>Payment ID: {{payment_id}}</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'amount', 'currency', 'description', 'customer_email', 'payment_id', 'error_message', 'user.name'], 'is_system' => true, 'is_active' => true],
            // payment.refunded
            ['type' => 'payment.refunded', 'channel_group' => 'push', 'title' => '{{app_name}}: Payment refunded', 'body' => 'Refund of {{refund_amount}} {{currency}} processed.', 'variables' => ['app_name', 'amount', 'refund_amount', 'currency', 'description', 'customer_email', 'payment_id', 'refund_type', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.refunded', 'channel_group' => 'inapp', 'title' => 'Payment refunded', 'body' => '{{refund_type}} of {{refund_amount}} {{currency}} processed for payment #{{payment_id}}.', 'variables' => ['app_name', 'amount', 'refund_amount', 'currency', 'description', 'customer_email', 'payment_id', 'refund_type', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.refunded', 'channel_group' => 'chat', 'title' => '{{app_name}}: Payment refunded', 'body' => '{{refund_type}} of {{refund_amount}} {{currency}} processed. Original amount: {{amount}} {{currency}}. Payment ID: {{payment_id}}.', 'variables' => ['app_name', 'amount', 'refund_amount', 'currency', 'description', 'customer_email', 'payment_id', 'refund_type', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.refunded', 'channel_group' => 'email', 'title' => '{{app_name}}: Payment refunded', 'body' => '<p>Hi {{user.name}},</p><p>{{refund_type}} of {{refund_amount}} {{currency}} processed.</p><p>Original amount: {{amount}} {{currency}}. Payment ID: {{payment_id}}.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'amount', 'refund_amount', 'currency', 'description', 'customer_email', 'payment_id', 'refund_type', 'user.name'], 'is_system' => true, 'is_active' => true],
        ];
    }

    /**
     * Get default template content for a type and channel group (for reset).
     *
     * @return array{title: string, body: string}|null
     */
    public static function getDefaultFor(string $type, string $channelGroup): ?array
    {
        foreach (self::defaults() as $def) {
            if ($def['type'] === $type && $def['channel_group'] === $channelGroup) {
                return [
                    'title' => $def['title'],
                    'body' => $def['body'],
                ];
            }
        }
        return null;
    }

    public function run(): void
    {
        foreach (self::defaults() as $attrs) {
            NotificationTemplate::updateOrCreate(
                [
                    'type' => $attrs['type'],
                    'channel_group' => $attrs['channel_group'],
                ],
                $attrs
            );
        }
    }
}
