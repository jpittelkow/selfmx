<?php

namespace App\GraphQL\Queries;

use Illuminate\Support\Facades\Auth;

class MyNotificationSettings
{
    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
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
