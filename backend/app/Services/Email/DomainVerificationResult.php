<?php

namespace App\Services\Email;

class DomainVerificationResult
{
    public function __construct(
        public readonly bool $isVerified,
        public readonly array $dnsRecords = [],
        public readonly ?string $error = null,
    ) {}
}
