<?php

namespace App\Jobs;

use App\Models\Email;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\Email\EmailAIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEmailAIJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int $userId,
        public readonly int $emailId,
        public readonly ?int $threadId,
        public readonly string $feature,
    ) {}

    /**
     * Exponential backoff delays between retries (seconds).
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(EmailAIService $service): void
    {
        $user = User::find($this->userId);
        if (!$user || !$service->isAvailable($user)) {
            return;
        }

        $features = $service->getEnabledFeatures($user);
        $featureKey = match ($this->feature) {
            'auto_labeling' => 'auto_labeling',
            'priority_inbox' => 'priority_inbox',
            'summarization' => 'summarization',
            'smart_replies' => 'smart_replies',
            default => null,
        };

        if (!$featureKey || !($features[$featureKey] ?? false)) {
            return;
        }

        $email = Email::find($this->emailId);
        if (!$email) {
            return;
        }

        try {
            match ($this->feature) {
                'summarization' => $this->processSummary($service, $user),
                'auto_labeling' => $service->suggestLabels($user, $email),
                'priority_inbox' => $service->scorePriority($user, $email),
                'smart_replies' => $service->generateReplies($user, $email),
                default => null,
            };
        } catch (\Exception $e) {
            Log::error("Email AI job failed: {$this->feature}", [
                'user_id' => $this->userId,
                'email_id' => $this->emailId,
                'error' => $e->getMessage(),
            ]);

            if ($this->attempts() < $this->tries) {
                throw $e; // Let Laravel retry
            }
        }
    }

    private function processSummary(EmailAIService $service, User $user): void
    {
        if (!$this->threadId) {
            return;
        }

        $thread = EmailThread::find($this->threadId);
        if ($thread) {
            $service->summarizeThread($user, $thread);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("Email AI job permanently failed: {$this->feature}", [
            'user_id' => $this->userId,
            'email_id' => $this->emailId,
            'error' => $e->getMessage(),
        ]);
    }
}
