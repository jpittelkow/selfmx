<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\SSOSettingService;
use App\Services\SSOTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SSOSettingController extends Controller
{
    use ApiResponseTrait;

    private const GROUP = 'sso';

    public function __construct(
        private SettingService $settingService,
        private AuditService $auditService,
        private SSOTestService $ssoTestService,
        private SSOSettingService $ssoSettingService
    ) {}

    /**
     * Get SSO settings.
     */
    public function show(): JsonResponse
    {
        $settings = $this->settingService->getGroupMasked(self::GROUP);

        return $this->dataResponse([
            'settings' => $settings,
        ]);
    }

    /**
     * Update SSO settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'allow_linking' => ['sometimes', 'boolean'],
            'auto_register' => ['sometimes', 'boolean'],
            'trust_provider_email' => ['sometimes', 'boolean'],
            'google_enabled' => ['sometimes', 'boolean'],
            'github_enabled' => ['sometimes', 'boolean'],
            'microsoft_enabled' => ['sometimes', 'boolean'],
            'apple_enabled' => ['sometimes', 'boolean'],
            'discord_enabled' => ['sometimes', 'boolean'],
            'gitlab_enabled' => ['sometimes', 'boolean'],
            'oidc_enabled' => ['sometimes', 'boolean'],
            'google_client_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'google_client_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'github_client_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'github_client_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'microsoft_client_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'microsoft_client_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'apple_client_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'apple_client_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'discord_client_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'discord_client_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'gitlab_client_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'gitlab_client_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'oidc_client_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'oidc_client_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'oidc_issuer_url' => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
            'oidc_provider_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $userId = $request->user()->id;
        $oldSettings = $this->settingService->getGroup(self::GROUP);

        $this->ssoSettingService->enforceProviderRules($validated, $oldSettings);

        $this->settingService->setGroup(self::GROUP, $validated, $userId);
        $this->auditService->logSettings(self::GROUP, $oldSettings, $validated, $userId);

        return $this->successResponse('SSO settings updated successfully');
    }

    /**
     * Reset an SSO setting to env default.
     */
    public function reset(Request $request, string $key): JsonResponse
    {
        $schema = config('settings-schema.sso', []);
        if (!array_key_exists($key, $schema)) {
            return $this->errorResponse('Unknown setting key', 422);
        }
        $this->settingService->reset(self::GROUP, $key);

        return $this->successResponse('Setting reset to default');
    }

    /**
     * Test SSO provider configuration by validating credentials at the provider's token endpoint.
     * Uses a fake authorization code; invalid_client = bad credentials, invalid_grant = credentials accepted.
     */
    public function test(Request $request, string $provider): JsonResponse
    {
        $result = $this->ssoTestService->testProvider($provider, $request->user()->id);

        if ($result['success']) {
            return $this->dataResponse($result['data']);
        }

        return $this->errorResponse($result['error'], $result['status'] ?? 422);
    }
}
