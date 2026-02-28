<?php

namespace App\Models;

use App\Models\Traits\HasEmailNotifications;
use App\Models\Traits\HasSettings;
use App\Models\Traits\HasUserStatus;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
    use HasApiTokens, HasEmailNotifications, HasFactory, HasGroups, HasSettings, HasUserStatus, MustVerifyEmail, Notifiable, Searchable, WebAuthnAuthentication {
        HasEmailNotifications::sendEmailVerificationNotification insteadof MustVerifyEmail;
    }

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            $user->flushCredentials();
        });
    }

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
     * Social accounts (SSO)
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Push notification subscriptions (one per device).
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
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

}
