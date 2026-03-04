<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAIResult extends Model
{
    protected $table = 'email_ai_results';

    protected $fillable = [
        'email_id',
        'thread_id',
        'user_id',
        'type',
        'result',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'duration_ms',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'version' => 'integer',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: filter by AI result type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: order by latest version first.
     */
    public function scopeLatestVersion(Builder $query): Builder
    {
        return $query->orderBy('version', 'desc');
    }
}
