<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailDomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'provider',
        'provider_domain_id',
        'provider_config',
        'catchall_mailbox_id',
        'is_verified',
        'verified_at',
        'dkim_rotated_at',
        'is_active',
    ];

    protected $hidden = [
        'provider_config',
    ];

    protected function casts(): array
    {
        return [
            'provider_config' => 'encrypted:array',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'dkim_rotated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'email_domain_id');
    }

    public function catchallMailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'catchall_mailbox_id');
    }

    /**
     * Get the full email address for a local part on this domain.
     */
    public function fullAddress(string $localPart): string
    {
        return $localPart . '@' . $this->name;
    }
}
