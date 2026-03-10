<?php

namespace App\Services\Email\Concerns;

interface HasInboundRoutes
{
    public function listRoutes(string $domain, array $config = []): array;

    public function createRoute(string $expression, array $actions, string $description, int $priority, array $config = []): array;

    public function updateRoute(string $routeId, array $params, array $config = []): array;

    public function deleteRoute(string $routeId, array $config = []): array;
}
