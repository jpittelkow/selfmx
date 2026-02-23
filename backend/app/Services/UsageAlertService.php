<?php

namespace App\Services;

use App\Models\IntegrationUsage;
use App\Models\User;
use App\Services\Notifications\NotificationOrchestrator;
use Illuminate\Support\Facades\Log;

class UsageAlertService
{
    private const BUDGET_SETTINGS = [
        IntegrationUsage::INTEGRATION_LLM => 'budget_llm',
        IntegrationUsage::INTEGRATION_EMAIL => 'budget_email',
        IntegrationUsage::INTEGRATION_SMS => 'budget_sms',
        IntegrationUsage::INTEGRATION_STORAGE => 'budget_storage',
        IntegrationUsage::INTEGRATION_BROADCASTING => 'budget_broadcasting',
        IntegrationUsage::INTEGRATION_PAYMENTS => 'budget_payments',
    ];

    public function __construct(
        private SettingService $settingService,
        private NotificationOrchestrator $orchestrator
    ) {}

    /**
     * Check all integration budgets and notify admins if thresholds are exceeded.
     *
     * @return array List of alerts triggered
     */
    public function checkBudgets(): array
    {
        $alerts = [];
        $alertThreshold = (float) ($this->settingService->get('usage', 'alert_threshold') ?? 80);

        foreach (self::BUDGET_SETTINGS as $integration => $settingKey) {
            $budget = $this->settingService->get('usage', $settingKey);

            if (!$budget || (float) $budget <= 0) {
                continue;
            }

            $budget = (float) $budget;

            // Get current month's cost for this integration
            $monthStart = now()->startOfMonth()->format('Y-m-d');
            $today = now()->format('Y-m-d');

            $currentCost = (float) IntegrationUsage::byIntegration($integration)
                ->byDateRange($monthStart, $today)
                ->sum('estimated_cost');

            $percentUsed = ($currentCost / $budget) * 100;

            if ($percentUsed >= 100) {
                $alerts[] = [
                    'integration' => $integration,
                    'type' => 'exceeded',
                    'budget' => $budget,
                    'current_cost' => $currentCost,
                    'percent' => round($percentUsed, 1),
                ];
            } elseif ($percentUsed >= $alertThreshold) {
                $alerts[] = [
                    'integration' => $integration,
                    'type' => 'warning',
                    'budget' => $budget,
                    'current_cost' => $currentCost,
                    'percent' => round($percentUsed, 1),
                ];
            }
        }

        if (!empty($alerts)) {
            $this->notifyAdmins($alerts);
        }

        return $alerts;
    }

    /**
     * Send budget alert notifications to all admin users.
     */
    private function notifyAdmins(array $alerts): void
    {
        $admins = User::whereHas('groups', fn ($q) => $q->where('slug', 'admin'))->get();

        foreach ($alerts as $alert) {
            $integrationLabel = strtoupper($alert['integration']);
            $type = $alert['type'] === 'exceeded' ? 'usage.budget_exceeded' : 'usage.budget_warning';

            $title = $alert['type'] === 'exceeded'
                ? "{$integrationLabel} budget exceeded"
                : "{$integrationLabel} budget warning";

            $message = sprintf(
                '%s usage has reached %s%% of the monthly budget ($%.2f of $%.2f).',
                $integrationLabel,
                $alert['percent'],
                $alert['current_cost'],
                $alert['budget']
            );

            foreach ($admins as $admin) {
                try {
                    $this->orchestrator->sendByType(
                        $admin,
                        $type,
                        [
                            'title' => $title,
                            'message' => $message,
                            'integration' => $integrationLabel,
                            'budget' => number_format($alert['budget'], 2),
                            'current_cost' => number_format($alert['current_cost'], 2),
                            'percent' => (string) $alert['percent'],
                            'action_url' => '/configuration',
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning("Failed to send usage alert to admin {$admin->id}", [
                        'error' => $e->getMessage(),
                        'alert' => $alert,
                    ]);
                }
            }
        }
    }
}
