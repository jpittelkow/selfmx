<?php

namespace App\Services\Email\Concerns;

interface HasEventLog
{
    public function getEvents(string $domain, array $filters = [], array $config = []): array;
}
