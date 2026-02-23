<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ApiKeyService
{
    public function __construct(
        private AuditService $auditService,
        private UsageTrackingService $usageTrackingService,
        private SettingService $settingService
    ) {}

    /**
     * Create a new API key for a user.
     *
     * @return array{token: ApiToken, plaintext: string}
     */
    public function create(User $user, string $name, ?Carbon $expiresAt = null): array
    {
        $maxKeys = (int) $this->settingService->get('graphql', 'max_keys_per_user', 5);
        $currentCount = $user->apiTokens()->active()->count();

        if ($currentCount >= $maxKeys) {
            throw new \RuntimeException("Maximum of {$maxKeys} active API keys per user reached.");
        }

        $plaintext = 'sk_' . Str::random(64);
        $hash = hash('sha256', $plaintext);
        $prefix = substr($plaintext, 0, 11); // "sk_" + first 8 random chars

        $token = $user->apiTokens()->create([
            'name' => $name,
            'token' => $hash,
            'key_prefix' => $prefix,
            'abilities' => ['*'],
            'expires_at' => $expiresAt,
        ]);

        $this->auditService->log(
            'api_key.created',
            $token,
            [],
            ['name' => $name, 'expires_at' => $expiresAt?->toDateTimeString()],
            $user->id
        );

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    /**
     * Validate a plaintext API key and return the token if valid.
     */
    public function validate(string $plaintext): ?ApiToken
    {
        if (!str_starts_with($plaintext, 'sk_')) {
            return null;
        }

        $hash = hash('sha256', $plaintext);

        // SoftDeletes trait auto-excludes deleted tokens
        $token = ApiToken::where('token', $hash)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$token) {
            return null;
        }

        $token->update(['last_used_at' => now()]);

        return $token;
    }

    /**
     * Revoke an API key.
     */
    public function revoke(ApiToken $token): void
    {
        $token->update(['revoked_at' => now()]);
        $token->delete(); // soft delete

        $this->auditService->log(
            'api_key.revoked',
            $token,
            [],
            ['name' => $token->name],
            $token->user_id
        );
    }

    /**
     * Rotate an API key: create a new key linked to the old one.
     * The old key remains valid for the configured grace period.
     *
     * @return array{token: ApiToken, plaintext: string}
     */
    public function rotate(ApiToken $token): array
    {
        $plaintext = 'sk_' . Str::random(64);
        $hash = hash('sha256', $plaintext);
        $prefix = substr($plaintext, 0, 11);

        $newToken = $token->user->apiTokens()->create([
            'name' => $token->name,
            'token' => $hash,
            'key_prefix' => $prefix,
            'abilities' => $token->abilities,
            'rate_limit' => $token->rate_limit,
            'rotated_from_id' => $token->id,
            'expires_at' => $token->expires_at,
        ]);

        $this->auditService->log(
            'api_key.rotated',
            $newToken,
            ['old_key_id' => $token->id],
            ['new_key_id' => $newToken->id, 'name' => $token->name],
            $token->user_id
        );

        return ['token' => $newToken, 'plaintext' => $plaintext];
    }

    /**
     * Prune expired keys and auto-revoke rotated keys past grace period.
     *
     * @return int Number of keys pruned
     */
    public function pruneExpired(): int
    {
        $count = 0;

        // Soft-delete expired keys (SoftDeletes trait already excludes deleted)
        $expired = ApiToken::expired()->get();

        foreach ($expired as $token) {
            $token->delete();
            $count++;
        }

        // Auto-revoke old rotated keys past grace period
        $graceDays = (int) $this->settingService->get('graphql', 'key_rotation_grace_days', 7);
        $graceDate = now()->subDays($graceDays);

        $rotatedOld = ApiToken::whereNotNull('id')
            ->whereIn('id', function ($query) {
                $query->select('rotated_from_id')
                    ->from('api_tokens')
                    ->whereNotNull('rotated_from_id');
            })
            ->whereNull('revoked_at')
            ->where('created_at', '<', $graceDate)
            ->get();

        foreach ($rotatedOld as $token) {
            $token->update(['revoked_at' => now()]);
            $token->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Determine the display status of an API key.
     */
    public function getKeyStatus(ApiToken $token): string
    {
        if ($token->revoked_at) {
            return 'revoked';
        }
        if ($token->trashed()) {
            return 'deleted';
        }
        if ($token->expires_at && $token->expires_at->isPast()) {
            return 'expired';
        }
        if ($token->expires_at && $token->expires_at->isBefore(now()->addDays(7))) {
            return 'expiring_soon';
        }

        return 'active';
    }

    /**
     * Record an API request for usage tracking.
     */
    public function recordUsage(ApiToken $token, ?array $metadata = null): void
    {
        $this->usageTrackingService->record(
            'api',
            'graphql',
            'request',
            1,
            null,
            $metadata,
            $token->user_id
        );
    }
}
