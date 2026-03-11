<?php

namespace App\Services\Email;

/**
 * Base contract for provider management capabilities.
 *
 * Providers that support domain-level management operations implement this
 * interface and report which capabilities they support via getCapabilities().
 * Capability-specific methods live in the Concerns sub-interfaces; the
 * controller checks instanceof before calling them.
 */
interface ProviderManagementInterface
{
    /**
     * Return a map of capability name => bool indicating what management
     * operations this provider supports.
     *
     * Known keys: dkim_rotation, domain_listing, webhooks, inbound_routes,
     *             events, suppressions, stats, domain_management, dns_records
     *
     * @return array<string, bool>
     */
    public function getCapabilities(): array;
}
