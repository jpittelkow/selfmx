<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\EmailTemplate;
use App\Services\EmailConfigService;
use App\Services\EmailTemplateService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailTemplateController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private EmailTemplateService $templateService,
        private EmailConfigService $emailConfigService,
        private SettingService $settingService
    ) {}

    /**
     * List all email templates (key, name, description, is_system, is_active, updated_at).
     */
    public function index(): JsonResponse
    {
        $templates = EmailTemplate::orderBy('key')->get()->map(fn (EmailTemplate $t) => [
            'key' => $t->key,
            'name' => $t->name,
            'description' => $t->description,
            'is_system' => $t->is_system,
            'is_active' => $t->is_active,
            'updated_at' => $t->updated_at->toISOString(),
        ]);

        return $this->dataResponse(['data' => $templates]);
    }

    /**
     * Get a single template by key (full content).
     */
    public function show(string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->first();
        if (!$template) {
            return $this->errorResponse('Template not found.', 404);
        }

        return $this->dataResponse([
            'data' => [
                'key' => $template->key,
                'name' => $template->name,
                'description' => $template->description,
                'subject' => $template->subject,
                'body_html' => $template->body_html,
                'body_text' => $template->body_text,
                'variables' => $template->variables,
                'is_system' => $template->is_system,
                'is_active' => $template->is_active,
                'updated_at' => $template->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * Update template (subject, body_html, body_text, is_active).
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->first();
        if (!$template) {
            return $this->errorResponse('Template not found.', 404);
        }

        $validated = $request->validate([
            'subject' => ['sometimes', 'string', 'max:500'],
            'body_html' => ['sometimes', 'string'],
            'body_text' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $template->update($validated);

        return $this->successResponse('Template updated.', [
            'data' => [
                'key' => $template->key,
                'name' => $template->name,
                'subject' => $template->subject,
                'body_html' => $template->body_html,
                'body_text' => $template->body_text,
                'is_active' => $template->is_active,
                'updated_at' => $template->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * Preview template with provided or sample variables.
     * Optionally pass subject, body_html, body_text to preview unsaved content.
     */
    public function preview(Request $request, string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->first();
        if (!$template) {
            return $this->errorResponse('Template not found.', 404);
        }

        $variables = $request->input('variables', []);
        if (!is_array($variables)) {
            $variables = [];
        }
        $variables = array_merge($this->sampleVariables($key), $variables);

        $subject = $request->input('subject');
        $bodyHtml = $request->input('body_html');
        $bodyText = $request->input('body_text');

        if ($subject !== null || $bodyHtml !== null || $bodyText !== null) {
            $rendered = $this->templateService->renderContent(
                (string) ($subject ?? $template->subject),
                (string) ($bodyHtml ?? $template->body_html),
                $bodyText !== null ? (string) $bodyText : $template->body_text,
                $variables
            );
        } else {
            $rendered = $this->templateService->renderTemplate($template, $variables);
        }

        return $this->dataResponse([
            'subject' => $rendered->subject,
            'html' => $rendered->html,
            'text' => $rendered->text,
        ]);
    }

    /**
     * Send test email for template.
     */
    public function test(Request $request, string $key): JsonResponse
    {
        if (!$this->emailConfigService->isConfigured()) {
            return $this->errorResponse('Email is not configured. Configure mail settings first.', 503);
        }

        $template = EmailTemplate::where('key', $key)->first();
        if (!$template) {
            return $this->errorResponse('Template not found.', 404);
        }

        $validated = $request->validate([
            'to' => ['nullable', 'email'],
        ]);
        $to = $validated['to'] ?? $request->user()->email;

        $variables = $this->sampleVariables($key);
        $rendered = $this->templateService->renderTemplate($template, $variables);

        $settings = $this->settingService->getGroup('mail');
        $this->applyMailConfig($settings);

        try {
            Mail::html($rendered->html, function ($message) use ($to, $rendered, $settings) {
                $message->to($to)
                    ->subject($rendered->subject)
                    ->from(
                        $settings['from_address'] ?? config('mail.from.address'),
                        $settings['from_name'] ?? config('mail.from.name')
                    );
            });

            return $this->successResponse('Test email sent.');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to send test email: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reset system template to default content.
     */
    public function reset(string $key): JsonResponse
    {
        $template = EmailTemplate::where('key', $key)->first();
        if (!$template) {
            return $this->errorResponse('Template not found.', 404);
        }
        if (!$template->is_system) {
            return $this->errorResponse('Only system templates can be reset.', 403);
        }

        $defaults = $this->templateService->getDefaultContent($key);
        if (!$defaults) {
            return $this->errorResponse('No default content for this template.', 422);
        }

        $template->update([
            'subject' => $defaults['subject'],
            'body_html' => $defaults['body_html'],
            'body_text' => $defaults['body_text'],
        ]);

        return $this->successResponse('Template reset to default.');
    }

    /**
     * Sample variables for preview/test (nested for dot notation).
     */
    private function sampleVariables(string $key): array
    {
        $appName = config('app.name', 'selfmx');
        $user = [
            'name' => 'Sample User',
            'email' => 'sample@example.com',
        ];

        return match ($key) {
            'password_reset' => [
                'user' => $user,
                'reset_url' => url('/reset-password?token=sample-token'),
                'expires_in' => '60 minutes',
                'app_name' => $appName,
            ],
            'email_verification' => [
                'user' => $user,
                'verification_url' => url('/verify-email?token=sample-token'),
                'app_name' => $appName,
            ],
            'welcome' => [
                'user' => $user,
                'login_url' => url('/login'),
                'app_name' => $appName,
            ],
            'notification' => [
                'user' => $user,
                'title' => 'Sample Notification',
                'message' => 'This is a sample notification message.',
                'action_url' => url('/'),
                'action_text' => 'View',
                'app_name' => $appName,
            ],
            default => [
                'user' => $user,
                'app_name' => $appName,
            ],
        };
    }

    /**
     * Apply mail settings to config for sending.
     */
    private function applyMailConfig(array $settings): void
    {
        if (isset($settings['mailer'])) {
            config(['mail.default' => $settings['mailer']]);
        }
        config([
            'mail.mailers.smtp.host' => $settings['smtp_host'] ?? config('mail.mailers.smtp.host'),
            'mail.mailers.smtp.port' => $settings['smtp_port'] ?? config('mail.mailers.smtp.port'),
            'mail.mailers.smtp.encryption' => $settings['smtp_encryption'] ?? config('mail.mailers.smtp.encryption'),
            'mail.mailers.smtp.username' => $settings['smtp_username'] ?? config('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password' => $settings['smtp_password'] ?? config('mail.mailers.smtp.password'),
        ]);
        if (isset($settings['from_address'])) {
            config(['mail.from.address' => $settings['from_address']]);
        }
        if (isset($settings['from_name'])) {
            config(['mail.from.name' => $settings['from_name']]);
        }
    }
}
