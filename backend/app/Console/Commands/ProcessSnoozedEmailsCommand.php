<?php

namespace App\Console\Commands;

use App\Events\EmailReceived;
use App\Models\EmailUserState;
use Illuminate\Console\Command;

class ProcessSnoozedEmailsCommand extends Command
{
    protected $signature = 'email:process-snoozed';
    protected $description = 'Resurface snoozed emails that are due';

    public function handle(): int
    {
        $dueSnoozes = EmailUserState::with('email')
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->get();

        if ($dueSnoozes->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($dueSnoozes as $state) {
            // Clear snooze and mark as unread to resurface
            $state->update([
                'snoozed_until' => null,
                'is_read' => false,
            ]);

            // Broadcast so frontend knows to refresh
            if ($state->email) {
                broadcast(new EmailReceived($state->user_id, $state->email))->toOthers();
            }

            $this->info("Unsnoozed email #{$state->email_id} for user #{$state->user_id}");
        }

        $this->info("Processed {$dueSnoozes->count()} snoozed email(s)");

        return self::SUCCESS;
    }
}
