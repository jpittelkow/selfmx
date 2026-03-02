<?php

namespace App\Services;

use App\Models\User;

class UserSettingService
{
    /**
     * Setting field definitions: validated key => [group, key].
     * Fields listed in $clearable can be set to null to revert to defaults.
     */
    private const SETTINGS_MAP = [
        'theme'                 => ['appearance', 'theme'],
        'color_theme'           => ['appearance', 'color_theme'],
        'default_llm_mode'      => ['defaults', 'llm_mode'],
        'notification_channels' => ['notifications', 'preferences'],
        'timezone'              => ['general', 'timezone'],
    ];

    private const CLEARABLE = ['color_theme', 'timezone'];

    /**
     * Apply validated preference data to a user's settings.
     */
    public function applyPreferences(User $user, array $validated): void
    {
        foreach (self::SETTINGS_MAP as $field => [$group, $key]) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }

            $value = $validated[$field];

            if ($value !== null) {
                $user->setSetting($group, $key, $value);
            } elseif (in_array($field, self::CLEARABLE, true)) {
                $user->settings()
                    ->where('group', $group)
                    ->where('key', $key)
                    ->delete();
            }
        }
    }

    /**
     * Build the preferences response payload for a user.
     */
    public function getPreferences(User $user): array
    {
        return [
            'theme' => $user->getSetting('appearance', 'theme', 'system'),
            'color_theme' => $user->getSetting('appearance', 'color_theme'),
            'default_llm_mode' => $user->getSetting('defaults', 'llm_mode', 'single'),
            'notification_channels' => $user->getSetting('notifications', 'preferences', []),
            'timezone' => $user->getSetting('general', 'timezone'),
            'effective_timezone' => $user->getTimezone(),
        ];
    }
}
