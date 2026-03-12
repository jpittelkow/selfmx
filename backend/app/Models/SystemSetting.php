<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'group',
        'key',
        'value',
        'is_encrypted',
        'is_public',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Value attribute: JSON decode on read, JSON encode on write.
     *
     * Encryption/decryption is handled by SettingService (via the schema),
     * not the model. The accessor returns raw values — encrypted strings
     * stay encrypted. SettingService.resolveGroup() decrypts them using
     * the schema's 'encrypted' flag.
     */
    protected function value(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function (mixed $value): mixed {
                if ($value === null || $value === '') {
                    return $value;
                }
                return self::jsonDecodeValue($value);
            },
            set: function (mixed $value): string {
                if ($value === null) {
                    return json_encode(null);
                }
                return is_string($value) ? $value : json_encode($value);
            },
        );
    }

    /**
     * Safely JSON-decode a string value.
     *
     * Uses json_last_error() to distinguish between a valid JSON literal
     * (e.g. "null", "false", "0") and an invalid JSON string. The previous
     * approach used `json_decode($v) ?? $v` which incorrectly discarded
     * legitimate null values via the null-coalescing operator, causing
     * PHP null to be replaced by the raw string "null".
     */
    private static function jsonDecodeValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }

    /**
     * Get the user who last updated this setting.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get a system setting value.
     */
    public static function get(string $key, mixed $default = null, string $group = 'general'): mixed
    {
        $setting = static::where('group', $group)
            ->where('key', $key)
            ->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a system setting value.
     */
    public static function set(string $key, mixed $value, string $group = 'general', ?int $updatedBy = null, bool $isPublic = false): void
    {
        static::updateOrCreate(
            [
                'group' => $group,
                'key' => $key,
            ],
            [
                'value' => $value,
                'updated_by' => $updatedBy,
                'is_public' => $isPublic,
            ]
        );
    }

    /**
     * Get all settings for a group.
     */
    public static function getGroup(string $group): array
    {
        return static::where('group', $group)
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get all public settings.
     * Ensures app_name always has a default value of 'selfmx' if not set in database.
     */
    public static function getPublic(): array
    {
        $settings = static::where('is_public', true)
            ->get()
            ->groupBy('group')
            ->map(fn ($group) => $group->pluck('value', 'key'))
            ->toArray();
        
        // Ensure general group exists
        if (!isset($settings['general'])) {
            $settings['general'] = [];
        }
        
        // Ensure app_name always has a default value
        if (!isset($settings['general']['app_name']) || empty($settings['general']['app_name'])) {
            $settings['general']['app_name'] = 'selfmx';
        }
        
        return $settings;
    }
}
