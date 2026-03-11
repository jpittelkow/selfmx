<?php

namespace App\Services\Email;

class DomainResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerDomainId = null,
        public readonly array $dnsRecords = [],
        public readonly ?string $error = null,
        public readonly array $metadata = [],
    ) {}

    public static function success(string $providerDomainId, array $dnsRecords = [], array $metadata = []): self
    {
        return new self(true, $providerDomainId, $dnsRecords, null, $metadata);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, [], $error);
    }
}
