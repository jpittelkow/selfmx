<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Contact extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'user_id',
        'email_address',
        'display_name',
        'avatar_url',
        'notes',
        'email_count',
        'last_emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_emailed_at' => 'datetime',
            'email_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mergedAddresses(): HasMany
    {
        return $this->hasMany(ContactMerge::class);
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'email_address' => $this->email_address,
            'display_name' => $this->display_name,
        ];
    }

    public function searchableAs(): string
    {
        return 'contacts';
    }
}
