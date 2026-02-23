<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\Notifications\NotificationChannelMetadata;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingController extends Controller
{
    use ApiResponseTrait;
    use NotificationChannelMetadata;

    private const GROUP = 'notifications';

    public function __construct(
        private SettingService $settingService,
        private NotificationOrchestrator $notificationOrchestrator,
        private AuditService $auditService
    ) {}

    /**
     * Get notification channel settings.
     */
    public function show(): JsonResponse
    {
        $settings = $this->settingService->getGroup(self::GROUP);

        return $this->dataResponse([
            'settings' => $settings,
        ]);
    }

    /**
     * Update notification settings.
     */
    public function update(Request $request): JsonResponse
    {
        $schema = config('settings-schema.notifications', []);
        $rules = [];
        foreach (array_keys($schema) as $key) {
            if (in_array($key, ['ntfy_enabled', 'sns_enabled'], true)) {
                $rules[$key] = ['sometimes', 'boolean'];
            } else {
                $rules[$key] = ['sometimes', 'nullable', 'string'];
            }
        }
        $validated = $request->validate($rules);

        $userId = $request->user()->id;
        $oldSettings = $this->settingService->getGroup(self::GROUP);
        foreach ($validated as $key => $value) {
            $this->settingService->set(self::GROUP, $key, $value, $userId);
        }

        $this->auditService->log(
            'notification_settings.updated',
            null,
            [],
            ['keys_updated' => array_keys($validated)],
            $userId
        );

        return $this->successResponse('Notification settings updated successfully');
    }

    /**
     * Reset a notification setting to env default.
     */
    public function reset(Request $request, string $key): JsonResponse
    {
        $schema = config('settings-schema.notifications', []);
        if (!array_key_exists($key, $schema)) {
            return $this->errorResponse('Unknown setting key', 422);
        }
        $this->settingService->reset(self::GROUP, $key);

        return $this->successResponse('Setting reset to default');
    }

    /**
     * Generate a VAPID key pair for Web Push.
     */
    public function generateVapid(Request $request): JsonResponse
    {
        try {
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();

            $this->auditService->log(
                'notification_settings.vapid_generated',
                null,
                [],
                [],
                $request->user()?->id
            );

            return $this->dataResponse([
                'public_key' => $keys['publicKey'],
                'private_key' => $keys['privateKey'],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to generate VAPID keys: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Test a notification channel.
     */
    public function testChannel(Request $request, string $channel): JsonResponse
    {
        if (!NotificationOrchestrator::isKnownChannel($channel)) {
            return $this->errorResponse("Unknown notification channel: {$channel}", 422);
        }

        $settings = $this->settingService->getGroup(self::GROUP);
        $this->applyNotificationsConfigForRequest($settings);

        try {
            $this->notificationOrchestrator->sendTestNotification($request->user(), $channel);

            return $this->successResponse('Test notification sent', ['channel' => $channel]);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to send test notification: ' . $e->getMessage(), 400);
        }
    }

}
