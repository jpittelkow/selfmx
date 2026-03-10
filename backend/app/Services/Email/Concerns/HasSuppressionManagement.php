<?php

namespace App\Services\Email\Concerns;

interface HasSuppressionManagement
{
    public function listBounces(string $domain, int $limit = 25, ?string $page = null, array $config = []): array;

    public function listComplaints(string $domain, int $limit = 25, ?string $page = null, array $config = []): array;

    public function listUnsubscribes(string $domain, int $limit = 25, ?string $page = null, array $config = []): array;

    public function deleteBounce(string $domain, string $address, array $config = []): bool;

    public function deleteComplaint(string $domain, string $address, array $config = []): bool;

    public function deleteUnsubscribe(string $domain, string $address, ?string $tag = null, array $config = []): bool;

    public function checkSuppression(string $domain, string $address, array $config = []): array;

    public function importBounces(string $domain, array $entries, array $config = []): array;

    public function importComplaints(string $domain, array $entries, array $config = []): array;

    public function importUnsubscribes(string $domain, array $entries, array $config = []): array;
}
