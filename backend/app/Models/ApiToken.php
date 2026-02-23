<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class ApiToken extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    protected $hidden = ['token'];

    protected $fillable = [
        'user_id',
        'name',
        'token',
        'key_prefix',
        'abilities',
        'rate_limit',
        'rotated_from_id',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the token.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the token this was rotated from.
     */
    public function rotatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }

    /**
     * Generate a new API token.
     */
    public static function generate(): string
    {
        return Str::random(64);
    }

    /**
     * Check if the token is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if the token is revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Check if the token is active (not expired, not revoked).
     */
    public function isActive(): bool
    {
        return !$this->isExpired() && !$this->isRevoked();
    }

    /**
     * Check if the token has a specific ability.
     */
    public function can(string $ability): bool
    {
        if (!$this->abilities) {
            return false;
        }

        return in_array('*', $this->abilities) || in_array($ability, $this->abilities);
    }

    /**
     * Scope: active tokens (not expired, not revoked).
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })->whereNull('revoked_at');
    }

    /**
     * Scope: expired tokens.
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    /**
     * Scope: revoked tokens.
     */
    public function scopeRevoked($query)
    {
        return $query->whereNotNull('revoked_at');
    }

    /**
     * Get the indexable data array for the model (Scout/Meilisearch).
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'api_tokens';
    }
}
