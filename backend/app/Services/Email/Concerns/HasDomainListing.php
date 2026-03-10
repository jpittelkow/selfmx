<?php

namespace App\Services\Email\Concerns;

interface HasDomainListing
{
    /**
     * List all domains registered with this provider.
     *
     * @param  array  $config  Provider credentials / config overrides
     * @return array{domains: array<array{name: string, state: string, created_at: ?string}>, total: int}
     */
    public function listProviderDomains(array $config = []): array;
}
