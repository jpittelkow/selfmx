<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\Stripe\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeSettingController extends Controller
{
    use ApiResponseTrait;

    private const GROUP = 'stripe';

    /** Keys owned by StripeConnectController — excluded from settings responses. */
    private const CONNECT_KEYS = ['connected_account_id', 'connect_onboarding_state'];

    /** Encrypted keys that must be masked in responses. */
    private const MASKED_KEYS = ['secret_key', 'webhook_secret'];

    private const MASK = '••••••••';

    public function __construct(
        private StripeService $stripeService,
        private SettingService $settingService,
        private AuditService $auditService
    ) {}

    /**
     * Get Stripe settings. Encrypted keys are masked; Connect keys are excluded.
     */
    public function show(): JsonResponse
    {
        $settings = $this->settingService->getGroup(self::GROUP);

        // Exclude Connect-owned keys
        foreach (self::CONNECT_KEYS as $key) {
            unset($settings[$key]);
        }

        // Mask encrypted fields
        foreach (self::MASKED_KEYS as $key) {
            if (! empty($settings[$key] ?? '')) {
                $settings[$key] = self::MASK;
            }
        }

        return $this->dataResponse(['settings' => $settings]);
    }

    /**
     * Update Stripe settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'secret_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'publishable_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'platform_account_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'platform_client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'application_fee_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'mode' => ['sometimes', 'string', 'in:test,live'],
            'deployment_role' => ['sometimes', 'string', 'in:platform,fork'],
        ]);

        // Skip masked placeholders (user did not change the encrypted field)
        foreach (self::MASKED_KEYS as $key) {
            if (isset($validated[$key]) && $validated[$key] === self::MASK) {
                unset($validated[$key]);
            }
        }

        $userId = $request->user()->id;
        $oldSettings = $this->settingService->getGroup(self::GROUP);

        foreach ($validated as $key => $value) {
            $this->settingService->set(self::GROUP, $key, $value === '' ? null : $value, $userId);
        }

        $this->auditService->logSettings(self::GROUP, $oldSettings, $validated, $userId);

        return $this->successResponse('Stripe settings updated successfully');
    }

    /**
     * Test connection to the Stripe API.
     */
    public function testConnection(): JsonResponse
    {
        $result = $this->stripeService->testConnection();

        if (! $result['success']) {
            return $this->errorResponse($result['error'] ?? 'Connection failed', 400);
        }

        return $this->dataResponse([
            'message' => 'Connection successful',
            'account_id' => $result['account_id'],
        ]);
    }

    /**
     * Reset a Stripe setting to its env default.
     */
    public function reset(Request $request, string $key): JsonResponse
    {
        $schema = config('settings-schema.' . self::GROUP, []);
        if (! array_key_exists($key, $schema)) {
            return $this->errorResponse('Unknown setting key', 422);
        }

        $this->auditService->log(
            'stripe.setting_reset',
            null,
            [$key => $this->settingService->get(self::GROUP, $key)],
            [$key => 'default'],
            $request->user()->id
        );

        $this->settingService->reset(self::GROUP, $key);

        return $this->successResponse('Setting reset to default');
    }
}
