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
     *
     * @return array{domain: EmailDomain, warnings: string[]}
     */
    public function createDomain(User $user, string $domainName, string $provider, array $providerConfig = []): array
    {
        $emailProvider = $this->resolveProvider($provider);
        $warnings = [];

        // Register with provider
        $result = $emailProvider->addDomain($domainName, $providerConfig);

        if (! $result->success) {
            throw new \RuntimeException("Failed to register domain with {$provider}: {$result->error}");
        }

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

        // Try to configure inbound webhook route
        $webhookUrl = url("/api/email/webhook/{$provider}");
        try {
            $webhookOk = $emailProvider->configureDomainWebhook($domainName, $webhookUrl, $providerConfig);
            if (! $webhookOk) {
                Log::warning("Failed to configure inbound webhook for {$domainName}");
                $warnings[] = 'Inbound webhook route could not be created. Configure it manually in the domain settings.';
            }
        } catch (\Exception $e) {
            Log::warning("Failed to configure inbound webhook for {$domainName}", ['error' => $e->getMessage()]);
            $warnings[] = "Inbound webhook setup failed: {$e->getMessage()}";
        }

        // Register delivery event webhooks (delivered, bounced, complained)
        if ($emailProvider instanceof MailgunProvider) {
            $eventsUrl = url("/api/email/webhook/{$provider}/events");
            foreach (['delivered', 'bounced', 'complained'] as $event) {
                try {
                    $emailProvider->createWebhook($domainName, $event, $eventsUrl, $providerConfig);
                } catch (\Exception $e) {
                    Log::warning("Failed to configure {$event} webhook for {$domainName}", ['error' => $e->getMessage()]);
                    $warnings[] = "Delivery webhook ({$event}) setup failed: {$e->getMessage()}";
                }
            }
        }

        return ['domain' => $domain, 'warnings' => $warnings];
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
