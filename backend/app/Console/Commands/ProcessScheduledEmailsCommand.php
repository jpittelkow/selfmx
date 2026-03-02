<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledEmailJob;
use App\Models\Email;
use Illuminate\Console\Command;

class ProcessScheduledEmailsCommand extends Command
{
    protected $signature = 'email:process-scheduled';
    protected $description = 'Process and send scheduled emails that are due';

    public function handle(): int
    {
        $dueEmails = Email::where('is_draft', true)
            ->whereNotNull('send_at')
            ->where('send_at', '<=', now())
            ->get();

        if ($dueEmails->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($dueEmails as $email) {
            SendScheduledEmailJob::dispatch($email->id);
            $this->info("Dispatched scheduled send for email #{$email->id}");
        }

        $this->info("Processed {$dueEmails->count()} scheduled email(s)");

        return self::SUCCESS;
    }
}
