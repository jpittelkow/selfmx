<?php

namespace App\Console\Commands;

use App\Services\ApiKeyService;
use Illuminate\Console\Command;

class PruneExpiredApiKeysCommand extends Command
{
    protected $signature = 'api-keys:prune-expired';

    protected $description = 'Soft-delete expired API keys and auto-revoke rotated keys past grace period';

    public function handle(ApiKeyService $apiKeyService): int
    {
        $count = $apiKeyService->pruneExpired();

        $this->info("Pruned {$count} expired/rotated API key(s).");

        return self::SUCCESS;
    }
}
