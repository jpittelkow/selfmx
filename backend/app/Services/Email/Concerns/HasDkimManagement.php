<?php

namespace App\Services\Email\Concerns;

interface HasDkimManagement
{
    public function getDkimKey(string $domain, array $config = []): array;

    public function rotateDkimKey(string $domain, array $config = []): array;
}
