<?php

namespace App\Services\Email;

class ParsedEmail
{
    public function __construct(
        public readonly string $fromAddress,
        public readonly ?string $fromName,
        public readonly array $to,        // [{address, name}]
        public readonly array $cc,        // [{address, name}]
        public readonly array $bcc,       // [{address, name}]
        public readonly ?string $subject,
        public readonly ?string $bodyText,
        public readonly ?string $bodyHtml,
        public readonly array $headers,
        public readonly array $attachments, // [{filename, mimeType, size, content}]
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly ?string $references,
        public readonly ?float $spamScore,
        public readonly ?string $providerMessageId,
        public readonly ?string $providerEventId,
        public readonly string $recipientAddress, // The address this was delivered to
    ) {}

    /**
     * Extract the local part from the recipient address.
     */
    public function recipientLocalPart(): string
    {
        return explode('@', $this->recipientAddress)[0] ?? '';
    }

    /**
     * Extract the domain from the recipient address.
     */
    public function recipientDomain(): string
    {
        return explode('@', $this->recipientAddress)[1] ?? '';
    }
}
