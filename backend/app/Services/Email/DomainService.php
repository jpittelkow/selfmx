<?php

namespace App\Services\Email;

use App\Models\EmailDomain;
use App\Models\EmailProviderAccount;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DomainService
{
    public function __construct(
        private AuditService $auditService,
    ) {}

    /**
     * Create a new email domain and register it with the provider.
     *
     * @return array{domain: EmailDomain, warnings: string[]}
     */
    public function createDomain(User $user, string $domainName, string $provider, array $providerConfig = [], ?int $accountId = null): array
    {
        // If an account ID is provided, resolve the provider from it
        $account = null;
        $runtimeConfig = $providerConfig;
        if ($accountId) {
            $account = EmailProviderAccount::where('user_id', $user->id)->findOrFail($accountId);
            $provider = $account->provider;
            // Merge account credentials with any domain-specific overrides for the API call
            $runtimeConfig = array_merge($account->credentials ?? [], $providerConfig);
        }

        $emailProvider = $this->resolveProvider($provider);
        $warnings = [];

        // Register with provider (use full credentials for API call)
        $result = $emailProvider->addDomain($domainName, $runtimeConfig);

        if (! $result->success) {
            abort(422, "Failed to register domain with {$provider}: {$result->error}");
        }

        // Only store domain-specific overrides in provider_config, not account credentials.
        // Merge any provider metadata (e.g. SES verification token) for later retrieval.
        $storedConfig = array_merge($providerConfig, $result->metadata);
        $domain = EmailDomain::create([
            'user_id' => $user->id,
            'name' => strtolower($domainName),
            'provider' => $provider,
            'email_provider_account_id' => $accountId,
            'provider_domain_id' => $result->providerDomainId,
            'provider_config' => $storedConfig,
            'is_verified' => false,
            'is_active' => true,
        ]);

        $this->auditService->log('email_domain.created', $domain, [], [
            'name' => $domain->name,
            'provider' => $provider,
            'account_id' => $accountId,
        ]);

        // Try to configure inbound webhook route
        $webhookUrl = url("/api/email/webhook/{$provider}");
        try {
            $webhookOk = $emailProvider->configureDomainWebhook($domainName, $webhookUrl, $runtimeConfig);
            if (! $webhookOk) {
                Log::warning("Failed to configure inbound webhook for {$domainName}");
                $warnings[] = 'Inbound webhook route could not be created. Configure it manually in the domain settings.';
            }
        } catch (\Exception $e) {
            Log::warning("Failed to configure inbound webhook for {$domainName}", ['error' => $e->getMessage()]);
            $warnings[] = "Inbound webhook setup failed: {$e->getMessage()}";
        }

        // Register delivery event webhooks + stored (inbound) for providers that support it
        if ($emailProvider instanceof Concerns\HasWebhookManagement) {
            $eventsUrl = url("/api/email/webhook/{$provider}/events");
            foreach (['delivered', 'permanent_fail', 'complained', 'stored'] as $event) {
                try {
                    $emailProvider->createWebhook($domainName, $event, $eventsUrl, $runtimeConfig);
                } catch (\Exception $e) {
                    Log::warning("Failed to configure {$event} webhook for {$domainName}", ['error' => $e->getMessage()]);
                    $warnings[] = "Delivery webhook ({$event}) setup failed: {$e->getMessage()}";
                }
            }
        }

        // Re-associate orphaned mailboxes from a previously deleted version of this domain
        try {
            $reassociated = Mailbox::whereNull('email_domain_id')
                ->where('domain_name', strtolower($domainName))
                ->where('user_id', $user->id)
                ->update(['email_domain_id' => $domain->id, 'is_active' => true]);

            if ($reassociated > 0) {
                Log::info("Re-associated {$reassociated} orphaned mailboxes with domain {$domainName}");
            }
        } catch (\Exception $e) {
            Log::warning("Failed to re-associate orphaned mailboxes for {$domainName}", ['error' => $e->getMessage()]);
            $warnings[] = 'Some previously existing mailboxes could not be automatically restored.';
        }

        return ['domain' => $domain, 'warnings' => $warnings];
    }

    /**
     * Verify a domain's DNS records with the provider.
     */
    public function verifyDomain(EmailDomain $domain): DomainVerificationResult
    {
        $provider = $this->resolveProvider($domain->provider);
        $config = $this->getCredentialsForDomain($domain);
        $result = $provider->verifyDomain($domain->name, $config);

        if ($result->isVerified && !$domain->is_verified) {
            $domain->update([
                'is_verified' => true,
                'verified_at' => now(),
            ]);

            $this->auditService->log('email_domain.verified', $domain, [
                'is_verified' => false,
            ], [
                'is_verified' => true,
            ]);
        }

        return $result;
    }

    /**
     * Delete a domain. Mailboxes are preserved (detached) so emails persist.
     */
    public function deleteDomain(EmailDomain $domain): void
    {
        $this->auditService->log('email_domain.deleted', $domain, [
            'name' => $domain->name,
            'provider' => $domain->provider,
        ], []);

        // Clean up provider-side resources (receipt rules, SNS topics, etc.)
        try {
            $provider = $this->resolveProvider($domain->provider);
            if (method_exists($provider, 'cleanupDomainResources')) {
                $config = $this->getCredentialsForDomain($domain);
                $provider->cleanupDomainResources($domain->name, $config);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to clean up provider resources for domain', [
                'domain' => $domain->name,
                'provider' => $domain->provider,
                'error' => $e->getMessage(),
            ]);
        }

        DB::transaction(function () use ($domain) {
            // Deactivate mailboxes before deletion — they'll be orphaned with email_domain_id=null
            $domain->mailboxes()->update(['is_active' => false]);
            $domain->delete();
        });
    }

    /**
     * Get credentials for a domain — account FK first, then domain-level config, then default account.
     */
    public function getCredentialsForDomain(EmailDomain $domain): array
    {
        // Priority 1: Provider account credentials merged with domain overrides
        if ($domain->email_provider_account_id) {
            return $domain->getEffectiveConfig();
        }

        // Priority 2: Domain's own provider_config (legacy domains not yet migrated to accounts)
        $domainConfig = $domain->provider_config ?? [];
        if (! empty($domainConfig)) {
            return $domainConfig;
        }

        // Priority 3: Fall back to the user's default account for this provider
        $defaultAccount = EmailProviderAccount::where('provider', $domain->provider)
            ->where('user_id', $domain->user_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        return $defaultAccount ? $defaultAccount->credentials ?? [] : [];
    }

    /**
     * Resolve a provider implementation by name.
     */
    public function resolveProvider(string $provider): EmailProviderInterface
    {
        return match ($provider) {
            'mailgun' => app(MailgunProvider::class),
            'ses' => app(SesProvider::class),
            'postmark' => app(PostmarkProvider::class),
            'resend' => app(ResendProvider::class),
            'mailersend' => app(MailerSendProvider::class),
            'smtp2go' => app(Smtp2GoProvider::class),
            default => throw new \InvalidArgumentException("Unknown email provider: {$provider}"),
        };
    }
}
