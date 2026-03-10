<?php

namespace App\Services\Email;

use App\Models\EmailProviderAccount;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

class ProviderAccountService
{
    public function __construct(
        private AuditService $auditService,
        private DomainService $domainService,
    ) {}

    /**
     * Create a new provider account.
     */
    public function createAccount(User $user, string $provider, string $name, array $credentials): EmailProviderAccount
    {
        // If this is the first account for this provider for this user, make it the default
        $isFirst = ! EmailProviderAccount::where('provider', $provider)
            ->where('user_id', $user->id)
            ->exists();

        $account = EmailProviderAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'name' => $name,
            'credentials' => $credentials,
            'is_default' => $isFirst,
            'is_active' => true,
        ]);

        $this->auditService->log('email_provider_account.created', $account, [], [
            'name' => $name,
            'provider' => $provider,
        ]);

        return $account;
    }

    /**
     * Update an existing provider account.
     */
    public function updateAccount(EmailProviderAccount $account, array $data): EmailProviderAccount
    {
        $old = $account->only(array_keys($data));

        // Don't log credential values in audit
        $auditOld = $old;
        $auditNew = $data;
        if (isset($auditOld['credentials'])) {
            $auditOld['credentials'] = '[redacted]';
        }
        if (isset($auditNew['credentials'])) {
            $auditNew['credentials'] = '[redacted]';
        }

        $account->update($data);

        $this->auditService->log('email_provider_account.updated', $account, $auditOld, $auditNew);

        return $account->fresh();
    }

    /**
     * Delete a provider account. Blocks if domains are still linked.
     */
    public function deleteAccount(EmailProviderAccount $account): void
    {
        $linkedDomainCount = $account->domains()->count();
        if ($linkedDomainCount > 0) {
            abort(422, "Cannot delete account — {$linkedDomainCount} domain(s) are still linked to it. Reassign them first.");
        }

        $this->auditService->log('email_provider_account.deleted', $account, [
            'name' => $account->name,
            'provider' => $account->provider,
        ], []);

        $account->delete();

        // If this was the default, promote another account of the same provider for this user
        if ($account->is_default) {
            $next = EmailProviderAccount::where('provider', $account->provider)
                ->where('user_id', $account->user_id)
                ->where('is_active', true)
                ->first();
            $next?->update(['is_default' => true]);
        }
    }

    /**
     * Test the connection for a provider account.
     *
     * @return array{healthy: bool, latency_ms: int, error?: string}
     */
    public function testConnection(EmailProviderAccount $account): array
    {
        $credentials = $account->credentials ?? [];

        // Providers without adapters yet cannot be tested
        try {
            $provider = $this->domainService->resolveProvider($account->provider);
        } catch (\InvalidArgumentException) {
            return [
                'healthy' => false,
                'latency_ms' => 0,
                'error' => "Provider '{$account->provider}' does not have a test adapter yet.",
            ];
        }

        $start = microtime(true);
        try {
            if ($provider instanceof ProviderManagementInterface) {
                $ok = $provider->checkApiHealth($credentials);
            } else {
                // For providers without management interface, assume healthy if we can instantiate
                $ok = true;
            }
        } catch (\Exception $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            $account->update([
                'last_health_check' => now(),
                'health_status' => 'unhealthy',
            ]);

            return [
                'healthy' => false,
                'latency_ms' => $latencyMs,
                'error' => $e->getMessage(),
            ];
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $account->update([
            'last_health_check' => now(),
            'health_status' => $ok ? 'healthy' : 'unhealthy',
        ]);

        return [
            'healthy' => $ok,
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Set an account as the default for its provider type.
     */
    public function setDefault(EmailProviderAccount $account): void
    {
        DB::transaction(function () use ($account) {
            // Unset current default for this provider for this user
            EmailProviderAccount::where('provider', $account->provider)
                ->where('user_id', $account->user_id)
                ->where('is_default', true)
                ->where('id', '!=', $account->id)
                ->update(['is_default' => false]);

            $account->update(['is_default' => true]);
        });

        $this->auditService->log('email_provider_account.set_default', $account, [], [
            'name' => $account->name,
            'provider' => $account->provider,
        ]);
    }

    /**
     * Get the default account for a provider type for a given user.
     */
    public function getDefaultAccount(User $user, string $provider): ?EmailProviderAccount
    {
        return EmailProviderAccount::where('provider', $provider)
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }
}
