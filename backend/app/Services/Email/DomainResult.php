<?php

namespace App\Services\Email;

class DomainResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerDomainId = null,
        public readonly array $dnsRecords = [],
        public readonly ?string $error = null,
    ) {}

    public static function success(string $providerDomainId, array $dnsRecords = []): self
    {
        return new self(true, $providerDomainId, $dnsRecords);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, [], $error);
    }
}
