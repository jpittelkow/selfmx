<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\PushSubscription;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Notifications\NotificationChannelMetadata;
use App\Services\Notifications\NotificationOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserNotificationSettingsController extends Controller
{
    use ApiResponseTrait;
    use NotificationChannelMetadata;
    private const GROUP = 'notifications';

    /**
     * List notification channels available to the current user.
     * Only includes channels where admin has set "available" (and provider configured).
     * For SMS, only the preferred provider is included.
     */
    public function show(Request $request): JsonResponse
    {
        $channelConfig = config('notifications.channels');
        $smsProvider = SystemSetting::get('sms_provider', null, self::GROUP);
        $userSettings = $request->user()->settings()
            ->where('group', self::GROUP)
            ->pluck('value', 'key')
            ->toArray();

        $channelIds = $this->getAvailableChannelIds($channelConfig, $smsProvider);

        $user = $request->user();
        $channels = collect($channelIds)->map(function (string $id) use ($channelConfig, $userSettings, $user) {
            $config = $channelConfig[$id] ?? [];

            return [
                'id' => $id,
                'name' => $this->getChannelName($id),
                'description' => $this->getChannelDescription($id),
                'enabled' => (bool) ($userSettings["{$id}_enabled"] ?? false),
                'configured' => $this->isChannelConfigured($id, $config, $userSettings, $user),
                'usage_accepted' => (bool) ($userSettings["{$id}_usage_accepted"] ?? false),
                'settings' => $this->getChannelSettings($id, $userSettings),
            ];
        })->values();

        return $this->dataResponse(['channels' => $channels]);
    }

    /**
     * Update user notification settings.
     * Rejects enabling a channel that is not available to users.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'usage_accepted' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $channelId = $validated['channel'];

        if (!NotificationOrchestrator::isKnownChannel($channelId)) {
            return $this->errorResponse("Unknown notification channel: {$channelId}", 422);
        }

        if (isset($validated['enabled']) && $validated['enabled']) {
            if (!$this->isChannelAvailableToUser($channelId)) {
                return $this->errorResponse('This channel is not available. An administrator must enable it first.', 403);
            }
        }

        if (isset($validated['enabled'])) {
            $user->setSetting(self::GROUP, "{$channelId}_enabled", $validated['enabled']);
        }

        if (isset($validated['usage_accepted'])) {
            $user->setSetting(self::GROUP, "{$channelId}_usage_accepted", $validated['usage_accepted']);
        }

        if (isset($validated['settings'])) {
            $allowedKeys = collect($this->getRequiredSettings($channelId))->pluck('key')->toArray();
            foreach ($validated['settings'] as $key => $value) {
                if (!in_array($key, $allowedKeys, true)) {
                    continue;
                }
                $user->setSetting(self::GROUP, "{$channelId}_{$key}", (string) $value);
            }
        }

        return $this->successResponse('Notification settings updated');
    }

    /**
     * Store Web Push subscription from the frontend (upserts by endpoint).
     */
    public function storeWebPushSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:512'],
            'keys.auth' => ['required', 'string', 'max:512'],
        ]);

        $user = $request->user();
        $userAgent = $request->userAgent();

        PushSubscription::updateOrCreate(
            [
                'user_id' => $user->id,
                'endpoint_hash' => PushSubscription::hashEndpoint($validated['endpoint']),
            ],
            [
                'endpoint' => $validated['endpoint'],
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
                'user_agent' => $userAgent,
                'device_name' => PushSubscription::detectDeviceName($userAgent),
            ]
        );

        $user->setSetting(self::GROUP, 'webpush_enabled', true);

        return $this->successResponse('Subscription saved');
    }

    /**
     * Remove a Web Push subscription by endpoint.
     */
    public function destroyWebPushSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        $user = $request->user();
        $user->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($validated['endpoint']))
            ->delete();

        // If no subscriptions remain, disable webpush
        if (!$user->pushSubscriptions()->exists()) {
            $user->setSetting(self::GROUP, 'webpush_enabled', false);
        }

        return $this->successResponse('Subscription removed');
    }

    /**
     * List all Web Push subscriptions (devices) for the current user.
     */
    public function listWebPushSubscriptions(Request $request): JsonResponse
    {
        $subscriptions = $request->user()->pushSubscriptions()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (PushSubscription $sub) => [
                'id' => $sub->id,
                'device_name' => $sub->device_name,
                'endpoint' => $sub->endpoint,
                'created_at' => $sub->created_at?->toISOString(),
                'last_used_at' => $sub->last_used_at?->toISOString(),
            ]);

        return $this->dataResponse(['subscriptions' => $subscriptions]);
    }

    /**
     * Remove a specific Web Push subscription by ID.
     */
    public function destroyWebPushSubscriptionById(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $deleted = $user->pushSubscriptions()->where('id', $id)->delete();

        if ($deleted === 0) {
            return $this->errorResponse('Subscription not found', 404);
        }

        if (!$user->pushSubscriptions()->exists()) {
            $user->setSetting(self::GROUP, 'webpush_enabled', false);
        }

        return $this->successResponse('Subscription removed');
    }

    /**
     * Get per-type notification preferences for the current user.
     */
    public function typePreferences(Request $request): JsonResponse
    {
        $prefs = $request->user()->getSetting(self::GROUP, 'type_preferences', []);

        return $this->dataResponse(['preferences' => is_array($prefs) ? $prefs : []]);
    }

    /**
     * Update a single per-type, per-channel notification preference.
     */
    public function updateTypePreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string'],
            'channel' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $orchestrator = app(NotificationOrchestrator::class);
            $result = $orchestrator->setTypePreference(
                $request->user(),
                $validated['type'],
                $validated['channel'],
                $validated['enabled'],
            );

            return $this->successResponse('Type preference updated', [
                'preferences' => $result['preferences'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    private function getAvailableChannelIds(array $channelConfig, ?string $smsProvider): array
    {
        $ids = [];
        foreach ($channelConfig as $id => $config) {
            // Always-available channels (database, email) and user-configurable channels
            // (Slack, Discord - users provide their own webhooks) bypass provider check
            if (!$this->isAlwaysAvailableChannel($id) && !$this->isUserConfigurableChannel($id)) {
                $providerConfigured = (bool) ($config['enabled'] ?? false);
                if (!$providerConfigured) {
                    continue;
                }
            }

            $available = $this->isChannelAvailableToUser($id);
            if (!$available) {
                continue;
            }

            if (in_array($id, ['twilio', 'vonage', 'sns'], true)) {
                if ($smsProvider === null || $id !== $smsProvider) {
                    continue;
                }
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function isChannelAvailableToUser(string $channelId): bool
    {
        if ($this->isAlwaysAvailableChannel($channelId)) {
            return true;
        }

        $value = SystemSetting::get("channel_{$channelId}_available", false, self::GROUP);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function isChannelConfigured(string $id, array $config, array $userSettings, ?User $user = null): bool
    {
        if ($this->isAlwaysAvailableChannel($id)) {
            return true;
        }

        if ($id === 'webpush') {
            return $user ? $user->pushSubscriptions()->exists() : false;
        }

        $required = $this->getRequiredSettings($id);
        if (empty($required)) {
            return true;
        }

        foreach ($required as $setting) {
            $key = "{$id}_{$setting['key']}";
            if (empty($userSettings[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function getChannelSettings(string $id, array $userSettings): array
    {
        $required = $this->getRequiredSettings($id);

        return collect($required)->map(function ($s) use ($id, $userSettings) {
            $key = "{$id}_{$s['key']}";

            return [
                'key' => $s['key'],
                'label' => $s['label'],
                'type' => $s['type'],
                'value' => $userSettings[$key] ?? '',
                'placeholder' => $s['placeholder'] ?? '',
            ];
        })->toArray();
    }

    private function getRequiredSettings(string $id): array
    {
        return match ($id) {
            'telegram' => [
                ['key' => 'chat_id', 'label' => 'Chat ID', 'type' => 'text', 'placeholder' => 'Your Telegram chat ID'],
            ],
            'discord' => [
                ['key' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'text', 'placeholder' => 'https://discord.com/api/webhooks/...'],
            ],
            'slack' => [
                ['key' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'text', 'placeholder' => 'https://hooks.slack.com/services/...'],
            ],
            'signal' => [
                ['key' => 'phone_number', 'label' => 'Phone Number', 'type' => 'text', 'placeholder' => '+1234567890'],
            ],
            'matrix' => [
                ['key' => 'room_id', 'label' => 'Room ID', 'type' => 'text', 'placeholder' => '!roomid:matrix.org'],
            ],
            'twilio', 'vonage', 'sns' => [
                ['key' => 'phone_number', 'label' => 'Phone Number', 'type' => 'text', 'placeholder' => '+1234567890'],
            ],
            'ntfy' => [
                ['key' => 'topic', 'label' => 'Topic', 'type' => 'text', 'placeholder' => 'my-notifications', 'help' => 'Subscribe to this topic in the ntfy app'],
            ],
            default => [],
        };
    }
}
