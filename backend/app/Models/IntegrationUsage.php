<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationUsage extends Model
{
    use HasFactory;

    public const INTEGRATION_LLM = 'llm';
    public const INTEGRATION_EMAIL = 'email';
    public const INTEGRATION_SMS = 'sms';
    public const INTEGRATION_STORAGE = 'storage';
    public const INTEGRATION_BROADCASTING = 'broadcasting';
    public const INTEGRATION_PAYMENTS = 'payments';

    public const INTEGRATIONS = [
        self::INTEGRATION_LLM,
        self::INTEGRATION_EMAIL,
        self::INTEGRATION_SMS,
        self::INTEGRATION_STORAGE,
        self::INTEGRATION_BROADCASTING,
        self::INTEGRATION_PAYMENTS,
    ];

    /**
     * The table associated with the model.
     */
    protected $table = 'integration_usage';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'integration',
        'provider',
        'metric',
        'quantity',
        'estimated_cost',
        'metadata',
        'user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'estimated_cost' => 'decimal:6',
            'metadata' => 'array',
        ];
    }

    /**
     * The user associated with this usage record.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to filter by integration type.
     */
    public function scopeByIntegration(Builder $query, string $integration): Builder
    {
        return $query->where('integration', $integration);
    }

    /**
     * Scope to filter by provider.
     */
    public function scopeByProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeByDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('created_at', [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ]);
    }

    /**
     * Scope to filter by user.
     */
    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
