<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\EmailConfigService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class SystemSettingController extends Controller
{
    public function __construct(
        private AuditService $auditService,
        private SettingService $settingService
    ) {}
    /**
     * Get all system settings (admin only).
     */
    public function index(Request $request): JsonResponse
    {
        $settings = SystemSetting::all()
            ->groupBy('group')
            ->map(fn ($group) => $group->pluck('value', 'key'));

        // Inject app_url from env (not stored in DB -- controlled by APP_URL env var)
        $general = $settings->get('general', collect())->toArray();
        $general['app_url'] = config('app.url');
        $settings['general'] = $general;

        return response()->json([
            'settings' => $settings,
        ]);
    }

    /**
     * Get public system settings (no auth required).
     */
    public function publicSettings(EmailConfigService $emailConfigService): JsonResponse
    {
        $settings = Cache::remember('system_settings_public', 3600, function () {
            return SystemSetting::getPublic();
        });

        // Inject app_url from env (not stored in DB -- controlled by APP_URL env var)
        if (!isset($settings['general'])) {
            $settings['general'] = [];
        }
        $settings['general']['app_url'] = config('app.url');

        // Novu public keys (frontend needs these to show Novu Inbox vs local bell)
        $settings['novu'] = [
            'enabled' => filter_var($this->settingService->get('novu', 'enabled', false), FILTER_VALIDATE_BOOLEAN),
            'app_identifier' => $this->settingService->get('novu', 'app_identifier', '') ?? '',
            'api_url' => $this->settingService->get('novu', 'api_url', 'https://api.novu.co') ?? 'https://api.novu.co',
            'socket_url' => $this->settingService->get('novu', 'socket_url', 'https://ws.novu.co') ?? 'https://ws.novu.co',
        ];

        $emailConfigured = $emailConfigService->isConfigured();
        $passwordResetEnabled = $this->settingService->get('auth', 'password_reset_enabled', true);

        $searchEnabled = filter_var(
            $this->settingService->get('search', 'enabled', true),
            FILTER_VALIDATE_BOOLEAN
        );

        return response()->json([
            'settings' => $settings,
            'features' => [
                'email_configured' => $emailConfigured,
                'password_reset_available' => $emailConfigured && $passwordResetEnabled,
                'email_verification_available' => $emailConfigured,
                'email_verification_mode' => $this->settingService->get('auth', 'email_verification_mode', 'optional'),
                'two_factor_mode' => $this->settingService->get('auth', 'two_factor_mode', 'optional'),
                'passkey_mode' => $this->settingService->get('auth', 'passkey_mode', 'disabled'),
                'search_enabled' => $searchEnabled,
                'webpush_enabled' => !empty(config('notifications.channels.webpush.public_key')),
                'webpush_vapid_public_key' => config('notifications.channels.webpush.public_key', ''),
                'graphql_enabled' => filter_var(
                    $this->settingService->get('graphql', 'enabled', false),
                    FILTER_VALIDATE_BOOLEAN
                ),
            ],
        ]);
    }

    /**
     * Get settings for a specific group.
     */
    public function show(Request $request, string $group): JsonResponse
    {
        $settings = SystemSetting::where('group', $group)
            ->get()
            ->pluck('value', 'key')
            ->toArray();

        // Inject app_url from env for the general group
        if ($group === 'general') {
            $settings['app_url'] = config('app.url');
        }

        return response()->json([
            'group' => $group,
            'settings' => $settings,
        ]);
    }

    /**
     * Update system settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.group' => ['required', 'string'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['nullable'], // Allow null/empty for optional fields
            'settings.*.is_public' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        foreach ($validated['settings'] as $setting) {
            // app_url is controlled by APP_URL env var, not stored in DB
            if ($setting['group'] === 'general' && $setting['key'] === 'app_url') {
                continue;
            }

            $this->settingService->set(
                $setting['group'],
                $setting['key'],
                $setting['value'],
                $user->id
            );
        }

        // Clear caches so fresh values are returned
        Cache::forget('system_settings_public');
        $this->settingService->clearCache();

        $this->auditService->logSettings('system', [], ['settings' => $validated['settings']], $user->id);

        return response()->json([
            'message' => 'System settings updated successfully',
        ]);
    }
}
