<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\SuspiciousActivityService;
use Illuminate\Console\Command;

class CheckSuspiciousActivityCommand extends Command
{
    protected $signature = 'log:check-suspicious';

    protected $description = 'Check audit and access logs for suspicious patterns and notify admins.';

    public function handle(SuspiciousActivityService $service, NotificationOrchestrator $orchestrator): int
    {
        $alerts = $service->check();

        if (empty($alerts)) {
            $this->info('No suspicious activity detected.');
            return self::SUCCESS;
        }

        $admins = User::whereHas('groups', fn ($q) => $q->where('slug', 'admin'))->get();

        $alertSummary = implode('; ', array_column($alerts, 'message'));

        foreach ($admins as $admin) {
            try {
                $orchestrator->sendByType(
                    $admin,
                    'suspicious_activity',
                    [
                        'title' => 'Suspicious activity detected',
                        'message' => $alertSummary,
                        'alert_summary' => $alertSummary,
                        'alert_count' => (string) count($alerts),
                        'alerts' => $alerts,
                        'action_url' => '/configuration',
                    ]
                );
            } catch (\Throwable $e) {
                $this->warn("Failed to notify admin {$admin->id}: " . $e->getMessage());
            }
        }

        $this->warn(count($alerts) . ' alert(s) raised. Admins notified: ' . $admins->count());

        return self::SUCCESS;
    }
}
