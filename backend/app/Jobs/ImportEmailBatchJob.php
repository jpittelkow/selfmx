<?php

namespace App\Jobs;

use App\Models\Mailbox;
use App\Models\User;
use App\Services\Email\EmailImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ImportEmailBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes max

    public function __construct(
        private string $filePath,
        private string $format,
        private int $mailboxId,
        private int $userId,
        private string $jobId,
    ) {}

    public function handle(EmailImportService $importService): void
    {
        $mailbox = Mailbox::find($this->mailboxId);
        $user = User::find($this->userId);

        if (!$mailbox || !$user) {
            $this->updateStatus('failed', result: ['errors' => ['Mailbox or user not found']]);
            return;
        }

        $this->updateStatus('processing');

        try {
            $result = match ($this->format) {
                'mbox' => $importService->importMbox($this->filePath, $mailbox, $user),
                'eml' => $importService->importEml($this->filePath, $mailbox, $user),
                default => throw new \InvalidArgumentException("Unknown format: {$this->format}"),
            };

            $this->updateStatus('completed', $result->toArray());

            // Broadcast to user that import is complete
            if (class_exists(\App\Events\EmailReceived::class)) {
                // Use a generic notification via the mail channel
                Log::info('Email import completed', [
                    'job_id' => $this->jobId,
                    'imported' => $result->imported,
                    'skipped' => $result->skipped,
                    'failed' => $result->failed,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Email import job failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);
            $this->updateStatus('failed', ['errors' => [$e->getMessage()]]);
        } finally {
            // Clean up temporary file
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
            }
        }
    }

    private function updateStatus(string $status, ?array $result = null): void
    {
        $data = ['status' => $status, 'updated_at' => now()->toIso8601String()];
        if ($result !== null) {
            $data['result'] = $result;
        }
        Cache::put("email_import:{$this->jobId}", $data, now()->addHours(1));
    }
}
