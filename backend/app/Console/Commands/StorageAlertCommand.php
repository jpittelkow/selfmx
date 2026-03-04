<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\SettingService;
use App\Support\Str;
use Illuminate\Console\Command;

class StorageAlertCommand extends Command
{
    protected $signature = 'storage:check-alerts';

    protected $description = 'Check storage usage against thresholds and notify admins when exceeded.';

    public function __construct(
        private SettingService $settingService
    ) {
        parent::__construct();
    }

    public function handle(NotificationOrchestrator $orchestrator): int
    {
        $enabled = filter_var(
            $this->settingService->get('storage', 'storage_alert_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (! $enabled) {
            $this->info('Storage alerts are disabled.');

            return self::SUCCESS;
        }

        $storagePath = storage_path();
        $diskFree = disk_free_space($storagePath);
        $diskTotal = disk_total_space($storagePath);

        if ($diskFree === false || $diskTotal === false || $diskTotal <= 0) {
            $this->warn('Unable to determine disk space.');

            return self::FAILURE;
        }

        $diskUsedPercent = round((1 - $diskFree / $diskTotal) * 100, 1);
        $threshold = (int) ($this->settingService->get('storage', 'storage_alert_threshold', 80) ?: 80);
        $critical = (int) ($this->settingService->get('storage', 'storage_alert_critical', 95) ?: 95);
        $sendEmail = filter_var(
            $this->settingService->get('storage', 'storage_alert_email', true),
            FILTER_VALIDATE_BOOLEAN
        );

        $channels = ['database'];
        if ($sendEmail) {
            $channels[] = 'email';
        }

        $variables = [
            'usage' => (string) $diskUsedPercent,
            'threshold' => (string) $threshold,
            'free_formatted' => Str::formatBytes((int) $diskFree),
            'total_formatted' => Str::formatBytes((int) $diskTotal),
            'action_url' => '/configuration',
        ];

        $admins = User::whereHas('groups', fn ($q) => $q->where('slug', 'admin'))->get();

        if ($admins->isEmpty()) {
            $this->warn('No admin users to notify.');

            return self::SUCCESS;
        }

        if ($diskUsedPercent >= $critical) {
            $variables['title'] = "Storage critical: {$diskUsedPercent}% used";
            $variables['message'] = "Storage usage is at {$diskUsedPercent}% (critical threshold: {$critical}%). Free: {$variables['free_formatted']} of {$variables['total_formatted']}. Take action immediately.";

            foreach ($admins as $admin) {
                try {
                    $orchestrator->sendByType(
                        $admin,
                        'storage.critical',
                        $variables,
                        $channels
                    );
                } catch (\Throwable $e) {
                    $this->warn("Failed to notify admin {$admin->id}: " . $e->getMessage());
                }
            }

            $this->warn("Critical storage alert: {$diskUsedPercent}% used. Admins notified: " . $admins->count());

            return self::SUCCESS;
        }

        if ($diskUsedPercent >= $threshold) {
            $variables['title'] = "Storage warning: {$diskUsedPercent}% used";
            $variables['message'] = "Storage usage is at {$diskUsedPercent}% (warning threshold: {$threshold}%). Free: {$variables['free_formatted']} of {$variables['total_formatted']}.";

            foreach ($admins as $admin) {
                try {
                    $orchestrator->sendByType(
                        $admin,
                        'storage.warning',
                        $variables,
                        $channels
                    );
                } catch (\Throwable $e) {
                    $this->warn("Failed to notify admin {$admin->id}: " . $e->getMessage());
                }
            }

            $this->info("Storage warning: {$diskUsedPercent}% used. Admins notified: " . $admins->count());

            return self::SUCCESS;
        }

        $this->info("Storage usage OK: {$diskUsedPercent}% (threshold: {$threshold}%).");

        return self::SUCCESS;
    }

}
