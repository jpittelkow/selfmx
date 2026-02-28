<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];
        foreach (self::emailDefaults() as $attrs) {
            $rows[] = array_merge($attrs, [
                'variables' => json_encode($attrs['variables']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('notification_templates')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('channel_group', 'email')->delete();
    }

    /**
     * Inline copy of email-channel notification template defaults.
     * Decoupled from NotificationTemplateSeeder and NotificationTemplate
     * model to ensure migrate:fresh never breaks if those classes change.
     */
    private static function emailDefaults(): array
    {
        return [
            ['type' => 'backup.completed', 'channel_group' => 'email', 'title' => '{{app_name}}: Backup complete', 'body' => '<p>Hi {{user.name}},</p><p>Backup "{{backup_name}}" finished successfully.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'backup_name', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'backup.failed', 'channel_group' => 'email', 'title' => '{{app_name}}: Backup failed', 'body' => '<p>Hi {{user.name}},</p><p>Backup "{{backup_name}}" failed:</p><p>{{error_message}}</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'backup_name', 'error_message', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'auth.login', 'channel_group' => 'email', 'title' => '{{app_name}}: New sign-in', 'body' => '<p>Hi {{user.name}},</p><p>A new sign-in to your account was detected from {{ip}} at {{timestamp}}.</p><p>If this wasn\'t you, please change your password immediately.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'ip', 'timestamp', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'auth.password_reset', 'channel_group' => 'email', 'title' => '{{app_name}}: Password changed', 'body' => '<p>Hi {{user.name}},</p><p>Your password was changed. If this wasn\'t you, contact support immediately.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'system.update', 'channel_group' => 'email', 'title' => '{{app_name}}: Update available', 'body' => '<p>Hi {{user.name}},</p><p>A new version ({{version}}) is ready to install.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'version', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'llm.quota_warning', 'channel_group' => 'email', 'title' => '{{app_name}}: Quota warning', 'body' => '<p>Hi {{user.name}},</p><p>You have used {{usage}}% of your API quota.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'usage', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'storage.warning', 'channel_group' => 'email', 'title' => '{{app_name}}: Storage warning', 'body' => '<p>Hi {{user.name}},</p><p>Storage usage is at {{usage}}% (threshold: {{threshold}}%).</p><p>Free: {{free_formatted}} of {{total_formatted}}.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'usage', 'threshold', 'free_formatted', 'total_formatted', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'storage.critical', 'channel_group' => 'email', 'title' => '{{app_name}}: Storage critical', 'body' => '<p>Hi {{user.name}},</p><p><strong>Storage usage is at {{usage}}% (critical).</strong></p><p>Free: {{free_formatted}} of {{total_formatted}}. Take action immediately.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'usage', 'threshold', 'free_formatted', 'total_formatted', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'suspicious_activity', 'channel_group' => 'email', 'title' => '{{app_name}}: Suspicious activity detected', 'body' => '<p>Hi {{user.name}},</p><p><strong>{{alert_count}} suspicious pattern(s) detected:</strong></p><p>{{alert_summary}}</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'alert_summary', 'alert_count', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'usage.budget_warning', 'channel_group' => 'email', 'title' => '{{app_name}}: {{integration}} budget warning', 'body' => '<p>Hi {{user.name}},</p><p>{{integration}} usage has reached {{percent}}% of the monthly budget (${{current_cost}} of ${{budget}}).</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'integration', 'percent', 'current_cost', 'budget', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'usage.budget_exceeded', 'channel_group' => 'email', 'title' => '{{app_name}}: {{integration}} budget exceeded', 'body' => '<p>Hi {{user.name}},</p><p><strong>{{integration}} usage has exceeded the monthly budget</strong> at {{percent}}% (${{current_cost}} of ${{budget}}).</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'integration', 'percent', 'current_cost', 'budget', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.succeeded', 'channel_group' => 'email', 'title' => '{{app_name}}: Payment received', 'body' => '<p>Hi {{user.name}},</p><p>Payment of {{amount}} {{currency}} succeeded.</p><p>{{description}}</p><p>Payment ID: {{payment_id}}</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'amount', 'currency', 'description', 'customer_email', 'payment_id', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.failed', 'channel_group' => 'email', 'title' => '{{app_name}}: Payment failed', 'body' => '<p>Hi {{user.name}},</p><p>Payment of {{amount}} {{currency}} failed.</p><p>{{error_message}}</p><p>Payment ID: {{payment_id}}</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'amount', 'currency', 'description', 'customer_email', 'payment_id', 'error_message', 'user.name'], 'is_system' => true, 'is_active' => true],
            ['type' => 'payment.refunded', 'channel_group' => 'email', 'title' => '{{app_name}}: Payment refunded', 'body' => '<p>Hi {{user.name}},</p><p>{{refund_type}} of {{refund_amount}} {{currency}} processed.</p><p>Original amount: {{amount}} {{currency}}. Payment ID: {{payment_id}}.</p><p>— {{app_name}}</p>', 'variables' => ['app_name', 'amount', 'refund_amount', 'currency', 'description', 'customer_email', 'payment_id', 'refund_type', 'user.name'], 'is_system' => true, 'is_active' => true],
        ];
    }
};
