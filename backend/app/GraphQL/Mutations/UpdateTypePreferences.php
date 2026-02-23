<?php

namespace App\GraphQL\Mutations;

use App\Models\NotificationTemplate;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class UpdateTypePreferences
{
    public function __invoke($root, array $args, $context): array
    {
        $user = Auth::guard('api-key')->user();
        $input = $args['input'];

        $type = $input['type'];
        $channel = $input['channel'];
        $enabled = $input['enabled'];

        $channelConfig = config('notifications.channels', []);
        if (!isset($channelConfig[$channel])) {
            throw new Error("Unknown channel: {$channel}",
                extensions: ['code' => 'VALIDATION_ERROR']);
        }

        $knownTypes = cache()->remember('notification_known_types', 300, function () {
            return NotificationTemplate::query()->distinct()->pluck('type')->toArray();
        });

        if (!in_array($type, $knownTypes, true)) {
            throw new Error("Unknown notification type: {$type}",
                extensions: ['code' => 'VALIDATION_ERROR']);
        }

        $prefs = $user->getSetting('notifications', 'type_preferences', []);
        if (!is_array($prefs)) {
            $prefs = [];
        }

        if ($enabled) {
            unset($prefs[$type][$channel]);
            if (isset($prefs[$type]) && empty($prefs[$type])) {
                unset($prefs[$type]);
            }
        } else {
            if (!isset($prefs[$type])) {
                $prefs[$type] = [];
            }
            $prefs[$type][$channel] = false;
        }

        $user->setSetting('notifications', 'type_preferences', $prefs);

        return ['preferences' => $prefs];
    }
}
