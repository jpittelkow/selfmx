<?php

namespace App\GraphQL\Mutations;

use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class UpdateNotificationSettings
{
    private const ALLOWED_SETTING_KEYS = [
        'chat_id',
        'webhook_url',
        'phone_number',
        'room_id',
        'topic',
        'endpoint',
    ];

    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $input = $args['input'];
        $channelId = $input['channel'];

        $channelConfig = config('notifications.channels', []);
        if (!isset($channelConfig[$channelId])) {
            throw new Error("Unknown notification channel: {$channelId}",
                extensions: ['code' => 'VALIDATION_ERROR']);
        }

        if (isset($input['enabled'])) {
            $user->setSetting('notifications', "{$channelId}_enabled", $input['enabled']);
        }

        if (isset($input['settings']) && is_array($input['settings'])) {
            foreach ($input['settings'] as $key => $value) {
                if (!in_array($key, self::ALLOWED_SETTING_KEYS, true)) {
                    throw new Error("Invalid setting key: {$key}",
                        extensions: ['code' => 'VALIDATION_ERROR']);
                }
                $user->setSetting('notifications', "{$channelId}_{$key}", (string) $value);
            }
        }

        return $this->buildNotificationSettings($user);
    }

    private function buildNotificationSettings($user): array
    {
        $userSettings = $user->settings()
            ->where('group', 'notifications')
            ->pluck('value', 'key')
            ->toArray();

        $channelConfig = config('notifications.channels', []);
        $channels = [];

        foreach ($channelConfig as $id => $config) {
            $channels[] = [
                'id' => $id,
                'name' => ucfirst(str_replace('_', ' ', $id)),
                'enabled' => (bool) ($userSettings["{$id}_enabled"] ?? false),
                'configured' => !empty($config['enabled'] ?? false),
            ];
        }

        $typePreferences = $user->getSetting('notifications', 'type_preferences', []);

        return [
            'channels' => $channels,
            'typePreferences' => is_array($typePreferences) ? $typePreferences : [],
        ];
    }
}
