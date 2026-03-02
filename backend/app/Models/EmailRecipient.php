<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailRecipient extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email_id',
        'type',
        'address',
        'name',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
