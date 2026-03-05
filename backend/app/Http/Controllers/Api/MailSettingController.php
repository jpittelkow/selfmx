<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMailSettingRequest;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\EmailConfigService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailSettingController extends Controller
{
    use ApiResponseTrait;

    private const GROUP = 'mail';

    /**
     * Map schema keys to frontend/API keys for response.
     */
    private const SCHEMA_TO_FRONTEND = [
        'mailer' => 'provider',
        'smtp_host' => 'host',
        'smtp_port' => 'port',
        'smtp_encryption' => 'encryption',
        'smtp_username' => 'username',
        'smtp_password' => 'password',
        'from_address' => 'from_address',
        'from_name' => 'from_name',
        'mailgun_domain' => 'mailgun_domain',
        'mailgun_secret' => 'mailgun_secret',
        'sendgrid_api_key' => 'sendgrid_api_key',
        'ses_key' => 'ses_key',
        'ses_secret' => 'ses_secret',
        'ses_region' => 'ses_region',
        'postmark_token' => 'postmark_token',
    ];

    /**
     * Map frontend/API keys to schema keys for storage.
     */
    private const FRONTEND_TO_SCHEMA = [
        'provider' => 'mailer',
        'host' => 'smtp_host',
        'port' => 'smtp_port',
        'encryption' => 'smtp_encryption',
        'username' => 'smtp_username',
        'password' => 'smtp_password',
        'from_address' => 'from_address',
        'from_name' => 'from_name',
        'mailgun_domain' => 'mailgun_domain',
        'mailgun_secret' => 'mailgun_secret',
        'sendgrid_api_key' => 'sendgrid_api_key',
        'ses_key' => 'ses_key',
        'ses_secret' => 'ses_secret',
        'ses_region' => 'ses_region',
        'postmark_token' => 'postmark_token',
    ];

    public function __construct(
        private SettingService $settingService,
        private AuditService $auditService,
        private EmailConfigService $emailConfigService
    ) {}

    /**
     * Get mail settings.
     */
    public function show(): JsonResponse
    {
        $settings = $this->settingService->getGroupMasked(self::GROUP);
        $mapped = [];
        foreach (self::SCHEMA_TO_FRONTEND as $schemaKey => $frontendKey) {
            if (array_key_exists($schemaKey, $settings)) {
                $mapped[$frontendKey] = $settings[$schemaKey];
            }
        }

        return $this->dataResponse([
            'settings' => $mapped,
        ]);
    }

    /**
     * Update mail settings.
     */
    public function update(UpdateMailSettingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $userId = $request->user()->id;
        $oldSettings = $this->settingService->getGroup(self::GROUP);

        // Remap frontend keys to schema keys, then save via setGroup (skips masked values)
        $schemaValidated = [];
        foreach ($validated as $frontendKey => $value) {
            $schemaKey = self::FRONTEND_TO_SCHEMA[$frontendKey] ?? $frontendKey;
            $schemaValidated[$schemaKey] = $value;
        }
        $this->settingService->setGroup(self::GROUP, $schemaValidated, $userId);

        $newSettings = [];
        foreach ($validated as $frontendKey => $value) {
            $schemaKey = self::FRONTEND_TO_SCHEMA[$frontendKey] ?? $frontendKey;
            $newSettings[$schemaKey] = $value;
        }
        $this->auditService->logSettings(self::GROUP, $oldSettings, $newSettings, $userId);

        return $this->successResponse('Mail settings updated successfully');
    }

    /**
     * Reset a mail setting to env default.
     */
    public function reset(Request $request, string $key): JsonResponse
    {
        $schemaKey = $key;
        $schema = config('settings-schema.mail', []);
        if (!array_key_exists($schemaKey, $schema)) {
            return $this->errorResponse('Unknown setting key', 422);
        }
        $this->settingService->reset(self::GROUP, $schemaKey);
        return $this->successResponse('Setting reset to default');
    }

    /**
     * Send test email.
     */
    public function sendTestEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email'],
        ]);

        $settings = $this->settingService->getGroup(self::GROUP);
        $this->emailConfigService->applySettingsToConfig($settings);

        try {
            Mail::raw('This is a test email from your application.', function ($message) use ($validated, $settings) {
                $message->to($validated['to'])
                    ->subject('Test Email')
                    ->from(
                        $settings['from_address'] ?? config('mail.from.address'),
                        $settings['from_name'] ?? config('mail.from.name')
                    );
            });

            return $this->successResponse('Test email sent successfully');
        } catch (\Throwable $e) {
            Log::error('Test email failed', [
                'to' => $validated['to'],
                'mailer' => $settings['mailer'] ?? null,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $message = $e->getMessage();
            if (str_contains($message, 'Class') && str_contains($message, 'not found')) {
                $message = 'Mail driver is not installed. Run composer install in the backend (e.g. symfony/mailgun-mailer for Mailgun).';
            }
            return $this->errorResponse('Failed to send test email: ' . $message, 500);
        }
    }
}
