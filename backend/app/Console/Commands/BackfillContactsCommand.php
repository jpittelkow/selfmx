<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Email\ContactService;
use Illuminate\Console\Command;

class BackfillContactsCommand extends Command
{
    protected $signature = 'contacts:backfill {--user= : Backfill for a specific user ID}';

    protected $description = 'Backfill contacts from existing emails';

    public function handle(ContactService $contactService): int
    {
        $userId = $this->option('user');

        if ($userId) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("User {$userId} not found.");
                return self::FAILURE;
            }

            $count = $contactService->backfillForUser($user->id);
            $this->info("Backfilled contacts from {$count} emails for user {$user->name}.");

            return self::SUCCESS;
        }

        $users = User::all();
        $totalEmails = 0;

        foreach ($users as $user) {
            $count = $contactService->backfillForUser($user->id);
            $totalEmails += $count;
            $this->line("User {$user->name}: processed {$count} emails");
        }

        $this->info("Backfill complete. Processed {$totalEmails} emails across {$users->count()} users.");

        return self::SUCCESS;
    }
}
