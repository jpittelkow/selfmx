<?php

namespace App\Services\Email;

use App\Models\Mailbox;
use Illuminate\Http\Request;

interface EmailProviderInterface
{
    /**
     * Get the provider name identifier.
     */
    public function getName(): string;

    /**
     * Verify the webhook signature from this provider.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Parse an inbound email from the webhook payload.
     */
    public function parseInboundEmail(Request $request): ParsedEmail;

    /**
     * Send an email via this provider.
     */
    public function sendEmail(
        Mailbox $mailbox,
        array $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $attachments = [],
        array $cc = [],
        array $bcc = [],
        array $headers = [],
    ): SendResult;

    /**
     * Parse a delivery event webhook (delivered, bounced, failed, etc.).
     */
    public function parseDeliveryEvent(Request $request): array;

    /**
     * Register a domain with the provider.
     */
    public function addDomain(string $domain, array $config = []): DomainResult;

    /**
     * Check domain verification status with the provider.
     */
    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult;

    /**
     * Configure the inbound webhook URL for a domain.
     */
    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool;
}
