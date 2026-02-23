<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\NotificationOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNotificationChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    /** Channels that should not retry (pure DB writes). Email is always sent sync by the orchestrator. */
    private const NO_RETRY_CHANNELS = ['database'];

    public function __construct(
        public readonly int $userId,
        public readonly string $channel,
        public readonly string $type,
        public readonly string $title,
        public readonly string $message,
        public readonly array $data = [],
    ) {
        if (in_array($channel, self::NO_RETRY_CHANNELS, true)) {
            $this->tries = 1;
        }
    }

    /**
     * Exponential backoff delays between retries (seconds).
     */
    public function backoff(): array
    {
        return [1, 5, 25];
    }

    public function handle(NotificationOrchestrator $orchestrator): void
    {
        $user = User::find($this->userId);
        if (!$user) {
            $this->writeDelivery(NotificationDelivery::STATUS_SKIPPED, 'User not found');
            return;
        }

        $channelInstance = $orchestrator->resolveChannel($this->channel);
        if (!$channelInstance) {
            $this->writeDelivery(NotificationDelivery::STATUS_SKIPPED, 'Channel not available');
            return;
        }

        try {
            $channelInstance->send($user, $this->type, $this->title, $this->message, $this->data);
            $this->writeDelivery(NotificationDelivery::STATUS_SUCCESS, null);
        } catch (\RuntimeException $e) {
            if ($this->attempts() < $this->tries) {
                throw $e; // Laravel retries with backoff
            }
            // Final attempt — record failure, don't rethrow
            $this->writeDelivery(NotificationDelivery::STATUS_FAILED, $e->getMessage());
            Log::error("Notification channel {$this->channel} failed after {$this->tries} attempts", [
                'user_id' => $this->userId,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Called by Laravel when all retries are exhausted from an unexpected throwable.
     */
    public function failed(\Throwable $e): void
    {
        $this->writeDelivery(NotificationDelivery::STATUS_FAILED, $e->getMessage());
    }

    private function writeDelivery(string $status, ?string $error): void
    {
        NotificationDelivery::create([
            'user_id' => $this->userId,
            'notification_type' => $this->type,
            'channel' => $this->channel,
            'status' => $status,
            'error' => $error,
            'attempt' => $this->attempts(),
            'attempted_at' => now(),
        ]);
    }
}
