<?php

namespace App\Jobs;

use App\Models\Email;
use App\Services\AuditService;
use App\Services\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendScheduledEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        private int $emailId,
    ) {}

    public function handle(EmailService $emailService, AuditService $auditService): void
    {
        $email = Email::with(['user', 'mailbox.emailDomain', 'recipients', 'attachments'])->find($this->emailId);

        if (!$email) {
            Log::warning('Scheduled email not found', ['email_id' => $this->emailId]);
            return;
        }

        // Only send if still a draft with send_at set
        if (!$email->is_draft || !$email->send_at) {
            Log::info('Scheduled email already sent or cancelled', ['email_id' => $this->emailId]);
            return;
        }

        try {
            // Build the send data from the draft — recipients must be plain email strings
            $data = [
                'mailbox_id' => $email->mailbox_id,
                'to' => $email->recipients->where('type', 'to')->pluck('address')->values()->toArray(),
                'cc' => $email->recipients->where('type', 'cc')->pluck('address')->values()->toArray(),
                'bcc' => $email->recipients->where('type', 'bcc')->pluck('address')->values()->toArray(),
                'subject' => $email->subject ?? '',
                'body_html' => $email->body_html ?? '',
                'body_text' => $email->body_text,
                'in_reply_to' => $email->in_reply_to,
                'references' => $email->references,
                'draft_id' => $email->id,
            ];

            // sendEmail will convert the draft in-place when draft_id is provided,
            // so we don't need to clear is_draft separately
            $sentEmail = $emailService->sendEmail($email->user, $data);

            $auditService->log('email.scheduled_sent', $sentEmail, null, [
                'original_draft_id' => $this->emailId,
            ]);

            Log::info('Scheduled email sent successfully', [
                'email_id' => $sentEmail->id,
                'original_draft_id' => $this->emailId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send scheduled email', [
                'email_id' => $this->emailId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
