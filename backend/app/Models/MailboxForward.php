<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxForward extends Model
{
    protected $fillable = [
        'user_id',
        'mailbox_id',
        'forward_to',
        'keep_local_copy',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'keep_local_copy' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
