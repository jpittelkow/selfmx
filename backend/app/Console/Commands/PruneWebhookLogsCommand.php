<?php

namespace App\Console\Commands;

use App\Models\EmailWebhookLog;
use Illuminate\Console\Command;

class PruneWebhookLogsCommand extends Command
{
    protected $signature = 'email:prune-webhook-logs {--days=30 : Delete logs older than this many days}';

    protected $description = 'Delete old email webhook logs to prevent unbounded table growth';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $count = EmailWebhookLog::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Pruned {$count} webhook log(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
