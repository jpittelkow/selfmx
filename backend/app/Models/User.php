<?php

namespace App\Models;

use App\Mail\TemplatedMail;
use App\Services\EmailTemplateService;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Scout\Searchable;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use App\Models\ApiToken;
use App\Traits\HasGroups;

class User extends Authenticatable implements \Illuminate\Contracts\Auth\MustVerifyEmail, WebAuthnAuthenticatable
{
    use HasApiTokens, HasFactory, HasGroups, MustVerifyEmail, Notifiable, Searchable, WebAuthnAuthentication;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'email_verified_at',
        'disabled_at',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    protected $appends = ['is_admin'];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * Get the indexable data array for the model (Scout/Meilisearch).
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'users';
    }

    /**
     * Check if user account is disabled.
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Check if user has two-factor authentication enabled.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Social accounts (SSO)
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * User settings
     */
    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    /**
     * User notifications
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * AI provider configurations
     */
    public function aiProviders(): HasMany
    {
        return $this->hasMany(AIProvider::class);
    }

    /**
     * API tokens
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * User onboarding record
     */
    public function onboarding(): HasOne
    {
        return $this->hasOne(UserOnboarding::class);
    }

    /**
     * Get a specific setting value.
     * 
     * Supports two signatures:
     * - getSetting('group', 'key', 'default') - group-based (new)
     * - getSetting('key', 'default') - legacy (backward compatible)
     * 
     * @param string $groupOrKey The setting group (new) or key (legacy)
     * @param mixed $keyOrDefault The setting key (new) or default value (legacy)
     * @param mixed $default Default value if setting doesn't exist (new signature only)
     * @return mixed
     */
    public function getSetting(string $groupOrKey, mixed $keyOrDefault = null, mixed $default = null): mixed
    {
        // New signature: getSetting('group', 'key', 'default')
        // Check if we have 3 args and second is a string (indicating it's a key, not a default)
        if (func_num_args() >= 2 && is_string($keyOrDefault)) {
            $group = $groupOrKey;
            $key = $keyOrDefault;
            $setting = $this->settings()
                ->where('group', $group)
                ->where('key', $key)
                ->first();
            return $setting ? $setting->value : ($default ?? null);
        }
        
        // Legacy signature: getSetting('key', 'default')
        $key = $groupOrKey;
        $defaultValue = $keyOrDefault;
        $setting = $this->settings()->where('key', $key)->first();
        return $setting ? $setting->value : $defaultValue;
    }

    /**
     * Set a setting value.
     *
     * @param string $group The setting group (e.g. 'general', 'notifications')
     * @param string $key The setting key
     * @param mixed $value The value to set
     * @return Setting
     */
    public function setSetting(string $group, string $key, mixed $value): Setting
    {
        return $this->settings()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Get the user's effective timezone.
     *
     * Fallback chain: user setting -> admin system default -> APP_TIMEZONE -> UTC
     */
    public function getTimezone(): string
    {
        return $this->getSetting('general', 'timezone')
            ?? SystemSetting::get('default_timezone', null, 'general')
            ?? config('app.timezone', 'UTC');
    }

    /**
     * Computed: true if user is in the admin group (for API/frontend compatibility).
     */
    public function getIsAdminAttribute(): bool
    {
        if (array_key_exists('groups', $this->relations) && $this->relationLoaded('groups')) {
            return $this->groups->contains('slug', 'admin');
        }
        return $this->inGroup('admin');
    }

    /**
     * Check if user has admin privileges (in admin group).
     */
    public function isAdmin(): bool
    {
        return $this->inGroup('admin');
    }

    /**
     * Send the password reset notification.
     * Uses the email template system for customizable content.
     */
    public function sendPasswordResetNotification($token): void
    {
        $templateService = app(EmailTemplateService::class);
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $resetUrl = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($this->email);

        $rendered = $templateService->render('password_reset', [
            'user' => ['name' => $this->name, 'email' => $this->email],
            'reset_url' => $resetUrl,
            'expires_in' => (string) config('auth.passwords.users.expire', 60) . ' minutes',
            'app_name' => config('app.name'),
        ]);

        Mail::to($this->email)->send(new TemplatedMail($rendered));
    }

    /**
     * Send the email verification notification.
     * Uses the email template system for customizable content.
     */
    public function sendEmailVerificationNotification(): void
    {
        $templateService = app(EmailTemplateService::class);
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $verificationUrl = $frontendUrl . '/verify-email?id=' . $this->getKey() . '&hash=' . sha1($this->getEmailForVerification());

        $rendered = $templateService->render('email_verification', [
            'user' => ['name' => $this->name, 'email' => $this->email],
            'verification_url' => $verificationUrl,
            'app_name' => config('app.name'),
        ]);

        Mail::to($this->email)->send(new TemplatedMail($rendered));
    }
}
