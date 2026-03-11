<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Mailbox extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email_domain_id',
        'address',
        'domain_name',
        'display_name',
        'is_active',
        'signature',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emailDomain(): BelongsTo
    {
        return $this->belongsTo(EmailDomain::class);
    }

    public function forward(): HasOne
    {
        return $this->hasOne(MailboxForward::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    /**
     * Direct user access assignments for this mailbox.
     */
    public function accessUsers(): HasMany
    {
        return $this->hasMany(MailboxUser::class);
    }

    /**
     * Group access assignments for this mailbox.
     */
    public function accessGroups(): HasMany
    {
        return $this->hasMany(MailboxGroupAssignment::class);
    }

    /**
     * Users with access to this mailbox (via pivot).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'mailbox_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Groups with access to this mailbox (via pivot).
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'mailbox_group_assignments', 'mailbox_id', 'group_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the full email address (localpart@domain).
     */
    public function getFullAddressAttribute(): string
    {
        $domain = $this->emailDomain?->name ?? $this->domain_name;

        return $domain ? $this->address . '@' . $domain : $this->address;
    }

    /**
     * Check if this is a catchall mailbox.
     */
    public function isCatchall(): bool
    {
        return $this->address === '*';
    }
}
