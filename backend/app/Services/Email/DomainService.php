<?php

namespace App\Services\Email;

use App\Models\EmailDomain;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\Log;

class DomainService
{
    public function __construct(
        private AuditService $auditService,
    ) {}

    /**
     * Create a new email domain and register it with the provider.
     */
    public function createDomain(User $user, string $domainName, string $provider, array $providerConfig = []): EmailDomain
    {
        $emailProvider = $this->resolveProvider($provider);

        // Register with provider
        $result = $emailProvider->addDomain($domainName, $providerConfig);

        $domain = EmailDomain::create([
            'user_id' => $user->id,
            'name' => strtolower($domainName),
            'provider' => $provider,
            'provider_domain_id' => $result->providerDomainId,
            'provider_config' => $providerConfig,
            'is_verified' => false,
            'is_active' => true,
        ]);

        $this->auditService->log('email_domain.created', $domain, [], [
            'name' => $domain->name,
            'provider' => $provider,
        ]);

        // Try to configure webhook
        $webhookUrl = url("/api/email/webhook/{$provider}");
        $emailProvider->configureDomainWebhook($domainName, $webhookUrl, $providerConfig);

        return $domain;
    }

    /**
     * Verify a domain's DNS records with the provider.
     */
    public function verifyDomain(EmailDomain $domain): DomainVerificationResult
    {
        $provider = $this->resolveProvider($domain->provider);
        $result = $provider->verifyDomain($domain->name, $domain->provider_config ?? []);

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
     * Delete a domain.
     */
    public function deleteDomain(EmailDomain $domain): void
    {
        $this->auditService->log('email_domain.deleted', $domain, [
            'name' => $domain->name,
            'provider' => $domain->provider,
        ], []);

        $domain->delete();
    }

    /**
     * Resolve a provider implementation by name.
     */
    public function resolveProvider(string $provider): EmailProviderInterface
    {
        return match ($provider) {
            'mailgun' => app(MailgunProvider::class),
            'ses' => app(SesProvider::class),
            'sendgrid' => app(SendGridProvider::class),
            'postmark' => app(PostmarkProvider::class),
            default => throw new \InvalidArgumentException("Unknown email provider: {$provider}"),
        };
    }
}
