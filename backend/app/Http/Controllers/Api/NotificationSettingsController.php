<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\EmailConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @deprecated Use UserNotificationSettingsController instead.
 * Routes previously pointing to this controller have been migrated.
 * This file is retained for reference only and will be removed in a future release.
 */
class NotificationSettingsController extends Controller
{
    use ApiResponseTrait;
    /**
     * Get notification channel configuration.
     */
    public function show(Request $request): JsonResponse
    {
        $channelConfig = config('notifications.channels');
        $userSettings = $request->user()->settings()
            ->where('group', 'notifications')
            ->pluck('value', 'key')
            ->toArray();

        $channels = collect($channelConfig)->map(function ($config, $id) use ($userSettings) {
            return [
                'id' => $id,
                'name' => $this->getChannelName($id),
                'description' => $this->getChannelDescription($id),
                'enabled' => (bool) ($userSettings["{$id}_enabled"] ?? false),
                'configured' => $this->isChannelConfigured($id, $config, $userSettings),
                'settings' => $this->getChannelSettings($id, $userSettings),
            ];
        })->values();

        return $this->dataResponse(['channels' => $channels]);
    }

    /**
     * Update notification channel settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
        ]);

        $user = $request->user();
        $channelId = $validated['channel'];

        // Update enabled status
        if (isset($validated['enabled'])) {
            $user->setSetting('notifications', "{$channelId}_enabled", $validated['enabled']);
        }

        // Update channel-specific settings
        if (isset($validated['settings'])) {
            foreach ($validated['settings'] as $key => $value) {
                $user->setSetting('notifications', "{$channelId}_{$key}", $value);
            }
        }

        return $this->successResponse('Notification settings updated');
    }

    /**
     * Get display name for a channel.
     */
    private function getChannelName(string $id): string
    {
        return match ($id) {
            'database' => 'In-App',
            'email' => 'Email',
            'telegram' => 'Telegram',
            'discord' => 'Discord',
            'slack' => 'Slack',
            'signal' => 'Signal',
            'matrix' => 'Matrix',
            'twilio' => 'Twilio SMS',
            'vonage' => 'Vonage SMS',
            'sns' => 'AWS SNS',
            'webpush' => 'Web Push',
            'fcm' => 'Firebase Cloud Messaging',
            default => ucfirst($id),
        };
    }

    /**
     * Get description for a channel.
     */
    private function getChannelDescription(string $id): string
    {
        return match ($id) {
            'database' => 'Receive notifications in the application',
            'email' => 'Receive notifications via email',
            'telegram' => 'Receive notifications via Telegram bot',
            'discord' => 'Receive notifications via Discord webhook',
            'slack' => 'Receive notifications via Slack webhook',
            'signal' => 'Receive notifications via Signal messaging',
            'matrix' => 'Receive notifications via Matrix',
            'twilio' => 'Receive notifications via Twilio SMS',
            'vonage' => 'Receive notifications via Vonage SMS',
            'sns' => 'Receive notifications via AWS SNS',
            'webpush' => 'Receive browser push notifications',
            'fcm' => 'Receive notifications via Firebase Cloud Messaging',
            default => "Receive notifications via {$id}",
        };
    }

    /**
     * Check if a channel is configured.
     */
    private function isChannelConfigured(string $id, array $config, array $userSettings): bool
    {
        if ($id === 'email') {
            return app(EmailConfigService::class)->isConfigured();
        }
        if ($id === 'database') {
            return true;
        }

        // Check if required user settings are present
        $requiredSettings = $this->getRequiredSettings($id);
        if (empty($requiredSettings)) {
            return true; // No user settings required
        }

        foreach ($requiredSettings as $setting) {
            $key = "{$id}_{$setting['key']}";
            if (empty($userSettings[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get channel settings configuration.
     */
    private function getChannelSettings(string $id, array $userSettings): array
    {
        $requiredSettings = $this->getRequiredSettings($id);
        
        return collect($requiredSettings)->map(function ($setting) use ($id, $userSettings) {
            $key = "{$id}_{$setting['key']}";
            return [
                'key' => $setting['key'],
                'label' => $setting['label'],
                'type' => $setting['type'],
                'value' => $userSettings[$key] ?? '',
                'placeholder' => $setting['placeholder'] ?? '',
            ];
        })->toArray();
    }

    /**
     * Get required user settings for a channel.
     */
    private function getRequiredSettings(string $id): array
    {
        return match ($id) {
            'telegram' => [
                [
                    'key' => 'chat_id',
                    'label' => 'Chat ID',
                    'type' => 'text',
                    'placeholder' => 'Your Telegram chat ID',
                ],
            ],
            'discord' => [
                [
                    'key' => 'webhook_url',
                    'label' => 'Webhook URL',
                    'type' => 'text',
                    'placeholder' => 'https://discord.com/api/webhooks/...',
                ],
            ],
            'slack' => [
                [
                    'key' => 'webhook_url',
                    'label' => 'Webhook URL',
                    'type' => 'text',
                    'placeholder' => 'https://hooks.slack.com/services/...',
                ],
            ],
            'signal' => [
                [
                    'key' => 'phone_number',
                    'label' => 'Phone Number',
                    'type' => 'text',
                    'placeholder' => '+1234567890',
                ],
            ],
            'matrix' => [
                [
                    'key' => 'room_id',
                    'label' => 'Room ID',
                    'type' => 'text',
                    'placeholder' => '!roomid:matrix.org',
                ],
            ],
            default => [],
        };
    }
}
