<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subject',
        'last_message_at',
        'message_count',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'message_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class, 'thread_id');
    }

    /**
     * Get the latest email in this thread.
     */
    public function latestEmail()
    {
        return $this->hasOne(Email::class, 'thread_id')->latestOfMany('sent_at');
    }
}
