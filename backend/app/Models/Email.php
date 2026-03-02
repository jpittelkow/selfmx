<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Email extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'user_id',
        'mailbox_id',
        'message_id',
        'thread_id',
        'provider_message_id',
        'direction',
        'from_address',
        'from_name',
        'subject',
        'body_text',
        'body_html',
        'headers',
        'in_reply_to',
        'references',
        'is_read',
        'is_starred',
        'is_draft',
        'is_spam',
        'is_trashed',
        'is_archived',
        'delivery_status',
        'spam_score',
        'sent_at',
        'send_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'is_read' => 'boolean',
            'is_starred' => 'boolean',
            'is_draft' => 'boolean',
            'is_spam' => 'boolean',
            'is_trashed' => 'boolean',
            'is_archived' => 'boolean',
            'spam_score' => 'float',
            'sent_at' => 'datetime',
            'send_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'thread_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailRecipient::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(EmailLabel::class, 'email_label_assignments');
    }

    /**
     * Scope: only inbound emails.
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', 'inbound');
    }

    /**
     * Scope: only outbound emails.
     */
    public function scopeOutbound($query)
    {
        return $query->where('direction', 'outbound');
    }

    /**
     * Scope: unread emails.
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope: not trashed and not spam (normal inbox view).
     */
    public function scopeInbox($query)
    {
        return $query->where('is_trashed', false)
            ->where('is_spam', false)
            ->where('is_draft', false);
    }

    /**
     * Get the indexable data array for Meilisearch.
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['recipients', 'attachments', 'labels']);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'subject' => $this->subject,
            'from_address' => $this->from_address,
            'from_name' => $this->from_name,
            'body_text' => $this->body_text ? mb_substr($this->body_text, 0, 10000) : null,
            'to_addresses' => $this->recipients->where('type', 'to')->pluck('address')->implode(' '),
            'cc_addresses' => $this->recipients->where('type', 'cc')->pluck('address')->implode(' '),
            'direction' => $this->direction,
            'is_read' => $this->is_read,
            'is_starred' => $this->is_starred,
            'is_draft' => $this->is_draft,
            'is_spam' => $this->is_spam,
            'is_trashed' => $this->is_trashed,
            'has_attachment' => $this->attachments->count() > 0,
            'label_ids' => $this->labels->pluck('id')->toArray(),
            'sent_at' => $this->sent_at?->timestamp,
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return !$this->is_trashed && !$this->is_spam;
    }

    public function searchableAs(): string
    {
        return 'emails';
    }
}
