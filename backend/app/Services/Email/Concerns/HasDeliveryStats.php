<?php

namespace App\Services\Email\Concerns;

interface HasDeliveryStats
{
    public function getDomainStats(string $domain, array $events, string $duration = '30d', string $resolution = 'day', array $config = []): array;

    public function getTrackingSettings(string $domain, array $config = []): array;

    public function updateTrackingSetting(string $domain, string $type, bool $active, array $config = []): array;
}
