<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailProviderSettingController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private SettingService $settingService,
        private AuditService $auditService,
    ) {}

    private const PROVIDER_GROUPS = ['email_hosting', 'mailgun', 'ses', 'sendgrid', 'postmark'];

    public function show(): JsonResponse
    {
        $result = [];
        foreach (self::PROVIDER_GROUPS as $group) {
            $result[$group] = $this->settingService->getGroupMasked($group);
        }

        return response()->json($result);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_hosting.default_provider' => ['sometimes', 'string', 'in:mailgun,ses,sendgrid,postmark'],
            'email_hosting.spam_threshold' => ['sometimes', 'numeric'],
            'email_hosting.max_attachment_size' => ['sometimes', 'numeric'],
            'mailgun.api_key' => ['sometimes', 'nullable', 'string'],
            'mailgun.region' => ['sometimes', 'string', 'in:us,eu'],
            'mailgun.webhook_signing_key' => ['sometimes', 'nullable', 'string'],
            'mailgun.auto_configure_webhooks' => ['sometimes', 'boolean'],
            'mailgun.dkim_rotation_interval_days' => ['sometimes', 'integer', 'min:0'],
            'ses.access_key_id' => ['sometimes', 'nullable', 'string'],
            'ses.secret_access_key' => ['sometimes', 'nullable', 'string'],
            'ses.region' => ['sometimes', 'string'],
            'ses.configuration_set' => ['sometimes', 'nullable', 'string'],
            'sendgrid.api_key' => ['sometimes', 'nullable', 'string'],
            'sendgrid.webhook_verification_key' => ['sometimes', 'nullable', 'string'],
            'postmark.server_token' => ['sometimes', 'nullable', 'string'],
        ]);

        // Ensure numeric fields are stored as strings to match schema expectations
        foreach (['spam_threshold', 'max_attachment_size'] as $field) {
            if (isset($validated['email_hosting'][$field])) {
                $validated['email_hosting'][$field] = (string) $validated['email_hosting'][$field];
            }
        }

        $userId = $request->user()->id;

        foreach (self::PROVIDER_GROUPS as $group) {
            if (isset($validated[$group])) {
                $old = $this->settingService->getGroup($group);
                $this->settingService->setGroup($group, $validated[$group], $userId);
                $this->auditService->logSettings($group, $old, $validated[$group], $userId);
            }
        }

        return $this->successResponse('Email provider settings updated successfully');
    }
}
