<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAttachment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email_id',
        'filename',
        'mime_type',
        'size',
        'storage_path',
        'content_id',
        'is_inline',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_inline' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
