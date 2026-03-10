<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailProviderAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'name',
        'credentials',
        'is_default',
        'is_active',
        'last_health_check',
        'health_status',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'last_health_check' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(EmailDomain::class);
    }

    /**
     * Get a specific credential value from the encrypted credentials array.
     */
    public function getCredential(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? $default;
    }

    /**
     * Get the supported provider types.
     */
    public static function supportedProviders(): array
    {
        return ['mailgun', 'ses', 'postmark', 'resend', 'mailersend', 'smtp2go'];
    }

    /**
     * Get the required credential fields for a given provider.
     */
    public static function credentialFieldsFor(string $provider): array
    {
        return match ($provider) {
            'mailgun' => ['api_key', 'region', 'webhook_signing_key'],
            'ses' => ['access_key_id', 'secret_access_key', 'region', 'configuration_set'],
            'postmark' => ['server_token'],
            'resend' => ['api_key', 'webhook_signing_secret'],
            'mailersend' => ['api_key', 'webhook_signing_secret'],
            'smtp2go' => ['api_key'],
            default => [],
        };
    }
}
