<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class SpamFilterList extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'match_type',
        'value',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeAllowList(Builder $query): Builder
    {
        return $query->where('type', 'allow');
    }

    public function scopeBlockList(Builder $query): Builder
    {
        return $query->where('type', 'block');
    }
}
