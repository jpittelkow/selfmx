<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\NotificationTemplate;
use App\Services\AuditService;
use App\Services\NotificationTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationTemplateController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private NotificationTemplateService $templateService,
        private AuditService $auditService
    ) {}

    /**
     * List all notification templates.
     */
    public function index(): JsonResponse
    {
        $templates = NotificationTemplate::orderBy('type')->orderBy('channel_group')->get()->map(fn (NotificationTemplate $t) => [
            'id' => $t->id,
            'type' => $t->type,
            'channel_group' => $t->channel_group,
            'title' => $t->title,
            'is_system' => $t->is_system,
            'is_active' => $t->is_active,
            'updated_at' => $t->updated_at->toISOString(),
        ]);

        return $this->dataResponse(['data' => $templates]);
    }

    /**
     * Get a single template by id (full content).
     */
    public function show(int $id): JsonResponse
    {
        $template = NotificationTemplate::find($id);
        if (!$template) {
            return $this->errorResponse('Template not found.', 404);
        }

        // Include sibling templates (same type, different channel groups) for tab navigation
        $siblings = NotificationTemplate::where('type', $template->type)
            ->where('id', '!=', $template->id)
            ->orderBy('channel_group')
            ->get()
            ->map(fn (NotificationTemplate $s) => [
                'id' => $s->id,
                'channel_group' => $s->channel_group,
            ])
            ->values()
            ->all();

        return $this->dataResponse([
            'data' => [
                'id' => $template->id,
                'type' => $template->type,
                'channel_group' => $template->channel_group,
                'title' => $template->title,
                'body' => $template->body,
                'variables' => $template->variables,
                'variable_descriptions' => $this->variableDescriptions(),
                'is_system' => $template->is_system,
                'is_active' => $template->is_active,
                'updated_at' => $template->updated_at->toISOString(),
                'siblings' => $siblings,
            ],
        ]);
    }

    /**
     * Descriptions for known template variables (for UI reference).
     *
     * @return array<string, string>
     */
    private function variableDescriptions(): array
    {
        return [
            'app_name' => 'Application name from settings',
            'user.name' => "Recipient user's display name",
            'user.email' => "Recipient user's email address",
            'backup_name' => 'Name of the backup',
            'error_message' => 'Error details when operation fails',
            'ip' => 'IP address of the request',
            'timestamp' => 'Date and time of the event',
            'version' => 'Application version number',
            'usage' => 'Current quota/storage usage percentage',
            'threshold' => 'Configured warning threshold percentage',
            'free_formatted' => 'Free disk space (human-readable)',
            'total_formatted' => 'Total disk space (human-readable)',
            'alert_summary' => 'Summary of suspicious activity alerts',
            'alert_count' => 'Number of suspicious patterns detected',
            'integration' => 'Integration/service name (LLM, Email, SMS, etc.)',
            'percent' => 'Budget usage percentage',
            'current_cost' => 'Current month cost (dollar amount)',
            'budget' => 'Monthly budget limit (dollar amount)',
            'amount' => 'Payment amount (formatted, e.g. 25.00)',
            'currency' => 'Currency code (e.g. USD)',
            'description' => 'Payment description',
            'customer_email' => 'Customer email address',
            'payment_id' => 'Internal payment ID',
            'refund_amount' => 'Refund amount (formatted, e.g. 10.00)',
            'refund_type' => 'Type of refund (Full refund or Partial refund)',
        ];
    }

    /**
     * Update template (title, body, is_active).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $template = NotificationTemplate::find($id);
        if (!$template) {
            return $this->errorResponse('Template not found.', 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'body' => ['sometimes', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $oldValues = $template->only(['title', 'body', 'is_active']);
        $template->update($validated);

        $this->auditService->log(
            'notification_template.updated',
            $template,
            $oldValues,
            $validated,
            $request->user()?->id
        );

        return $this->successResponse('Template updated.', [
            'data' => [
                'id' => $template->id,
                'type' => $template->type,
                'channel_group' => $template->channel_group,
                'title' => $template->title,
                'body' => $template->body,
                'is_active' => $template->is_active,
                'updated_at' => $template->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * Preview template with provided or sample variables.
     * Optionally pass title, body to preview unsaved content.
     */
    public function preview(Request $request, int $id): JsonResponse
    {
        $template = NotificationTemplate::find($id);
        if (!$template) {
            return $this->errorResponse('Template not found.', 404);
        }

        $variables = $request->input('variables', []);
        if (!is_array($variables)) {
            $variables = [];
        }
        $variables = array_merge($this->sampleVariables($template->type), $variables);

        $title = $request->input('title');
        $body = $request->input('body');

        if ($title !== null || $body !== null) {
            $rendered = $this->templateService->renderContent(
                (string) ($title ?? $template->title),
                (string) ($body ?? $template->body),
                $variables
            );
        } else {
            $rendered = $this->templateService->renderTemplate($template, $variables);
        }

        return $this->dataResponse([
            'title' => $rendered['title'],
            'body' => $rendered['body'],
        ]);
    }

    /**
     * Reset system template to default content.
     */
    public function reset(Request $request, int $id): JsonResponse
    {
        $template = NotificationTemplate::find($id);
        if (!$template) {
            return $this->errorResponse('Template not found.', 404);
        }
        if (!$template->is_system) {
            return $this->errorResponse('Only system templates can be reset.', 403);
        }

        $defaults = $this->templateService->getDefaultContent($template->type, $template->channel_group);
        if (!$defaults) {
            return $this->errorResponse('No default content for this template.', 422);
        }

        $oldValues = $template->only(['title', 'body']);
        $template->update([
            'title' => $defaults['title'],
            'body' => $defaults['body'],
        ]);

        $this->auditService->log(
            'notification_template.reset',
            $template,
            $oldValues,
            ['title' => $defaults['title'], 'body' => $defaults['body']],
            $request->user()?->id
        );

        return $this->successResponse('Template reset to default.');
    }

    /**
     * Sample variables for preview (nested for dot notation).
     */
    private function sampleVariables(string $type): array
    {
        $appName = config('app.name', 'Sourdough');
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
