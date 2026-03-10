<?php

namespace App\Services\Email\Concerns;

interface HasWebhookManagement
{
    public function listWebhooks(string $domain, array $config = []): array;

    public function createWebhook(string $domain, string $event, string $url, array $config = []): array;

    public function updateWebhook(string $domain, string $webhookId, string $url, array $config = []): array;

    public function deleteWebhook(string $domain, string $webhookId, array $config = []): array;

    public function testWebhook(string $domain, string $webhookId, string $url, array $config = []): array;
}
