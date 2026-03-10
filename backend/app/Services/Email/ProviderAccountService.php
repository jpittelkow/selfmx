<?php

namespace App\Services\Email;

use App\Models\EmailDomain;
use App\Models\EmailProviderAccount;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Email\Concerns\HasDomainListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /**
     * Fetch domains from the provider API and indicate which are already imported.
     *
     * @return array{domains: array, imported: int, available: int}
     */
    public function fetchProviderDomains(EmailProviderAccount $account): array
    {
        $provider = $this->domainService->resolveProvider($account->provider);

        if (! $provider instanceof HasDomainListing) {
            return ['domains' => [], 'imported' => 0, 'available' => 0];
        }

        $result = $provider->listProviderDomains($account->credentials ?? []);
        $providerDomains = $result['domains'] ?? [];

        // Get domain names already in the system globally (domain names are globally unique)
        $existingNames = EmailDomain::pluck('name')
            ->map(fn ($n) => strtolower($n))
            ->toArray();

        $imported = 0;
        $available = 0;
        $domains = [];

        foreach ($providerDomains as $pd) {
            $name = strtolower($pd['name']);
            $alreadyImported = in_array($name, $existingNames);

            if ($alreadyImported) {
                $imported++;
            } else {
                $available++;
            }

            $domains[] = [
                'name' => $name,
                'state' => $pd['state'] ?? 'unknown',
                'created_at' => $pd['created_at'] ?? null,
                'type' => $pd['type'] ?? null,
                'is_disabled' => $pd['is_disabled'] ?? false,
                'already_imported' => $alreadyImported,
            ];
        }

        return [
            'domains' => $domains,
            'imported' => $imported,
            'available' => $available,
        ];
    }

    /**
     * Import active domains from a provider account into the system.
     *
     * @param  array|null  $domainNames  Specific domains to import. Null = all active domains.
     * @return array{imported: EmailDomain[], skipped: string[], errors: string[]}
     */
    public function importDomainsFromProvider(EmailProviderAccount $account, ?array $domainNames = null): array
    {
        $provider = $this->domainService->resolveProvider($account->provider);

        if (! $provider instanceof HasDomainListing) {
            return ['imported' => [], 'skipped' => [], 'errors' => ["Provider '{$account->provider}' does not support domain listing."]];
        }

        $result = $provider->listProviderDomains($account->credentials ?? []);
        $providerDomains = $result['domains'] ?? [];

        // Always filter out disabled domains; when specific names given, allow unverified
        $providerDomains = array_filter($providerDomains, function ($pd) use ($domainNames) {
            if ($pd['is_disabled'] ?? false) {
                return false;
            }
            if ($domainNames !== null) {
                return in_array(strtolower($pd['name']), array_map('strtolower', $domainNames));
            }
            return ($pd['state'] ?? '') === 'active';
        });

        // Get existing domain names globally (domain names are globally unique)
        $existingNames = EmailDomain::pluck('name')
            ->map(fn ($n) => strtolower($n))
            ->toArray();

        $imported = [];
        $skipped = [];
        $errors = [];

        foreach ($providerDomains as $pd) {
            $name = strtolower($pd['name']);

            if (in_array($name, $existingNames)) {
                $skipped[] = $name;
                continue;
            }

            try {
                $isVerified = ($pd['state'] ?? '') === 'active';

                $domain = EmailDomain::create([
                    'user_id' => $account->user_id,
                    'name' => $name,
                    'provider' => $account->provider,
                    'email_provider_account_id' => $account->id,
                    'is_verified' => $isVerified,
                    'verified_at' => $isVerified ? now() : null,
                    'is_active' => $isVerified,
                ]);

                $this->auditService->log('email_domain.imported', $domain, [], [
                    'name' => $name,
                    'provider' => $account->provider,
                    'account_id' => $account->id,
                    'provider_state' => $pd['state'] ?? 'unknown',
                ]);

                $imported[] = $domain;
            } catch (\Exception $e) {
                Log::warning("Failed to import domain {$name} from provider account {$account->id}", [
                    'error' => $e->getMessage(),
                ]);
                $isDuplicate = str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate');
                $errors[] = $isDuplicate
                    ? "{$name}: domain already exists in the system"
                    : "{$name}: import failed";
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }
}
