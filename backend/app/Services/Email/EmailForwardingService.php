<?php

namespace App\Services\Email;

use App\Models\Email;
use App\Models\Mailbox;
use App\Models\MailboxForward;
use Illuminate\Support\Facades\Log;

class EmailForwardingService
{
    /**
     * Get the active forward for a mailbox, if any.
     */
    public function getActiveForward(Mailbox $mailbox): ?MailboxForward
    {
        return MailboxForward::where('mailbox_id', $mailbox->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Forward using raw ParsedEmail data (before Email record exists).
     * Used for pass-through mode (keep_local_copy = false).
     */
    public function forwardParsed(ParsedEmail $parsed, Mailbox $mailbox, MailboxForward $forward): void
    {
        try {
            $emailService = app(EmailService::class);
            $emailService->sendEmail($mailbox->user, [
                'mailbox_id' => $mailbox->id,
                'to' => [['address' => $forward->forward_to]],
                'subject' => $parsed->subject ?? '',
                'body_html' => $parsed->bodyHtml ?? $parsed->bodyText ?? '',
                'body_text' => $parsed->bodyText,
                'headers' => [
                    'X-Forwarded-To' => $forward->forward_to,
                    'X-Original-From' => $parsed->fromAddress,
                ],
            ]);

            Log::info('Email forwarded (pass-through)', [
                'mailbox_id' => $mailbox->id,
                'forward_to' => $forward->forward_to,
                'from' => $parsed->fromAddress,
                'subject' => $parsed->subject,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to forward email (pass-through)', [
                'mailbox_id' => $mailbox->id,
                'forward_to' => $forward->forward_to,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Forward from an existing Email record.
     * Used for keep-copy mode (keep_local_copy = true).
     */
    public function forwardEmail(Email $email, MailboxForward $forward): void
    {
        try {
            $emailService = app(EmailService::class);
            $emailService->sendEmail($email->user, [
                'mailbox_id' => $email->mailbox_id,
                'to' => [['address' => $forward->forward_to]],
                'subject' => $email->subject ?? '',
                'body_html' => $email->body_html ?? $email->body_text ?? '',
                'body_text' => $email->body_text,
                'headers' => [
                    'X-Forwarded-To' => $forward->forward_to,
                    'X-Original-From' => $email->from_address,
                ],
            ]);

            Log::info('Email forwarded (keep-copy)', [
                'email_id' => $email->id,
                'mailbox_id' => $email->mailbox_id,
                'forward_to' => $forward->forward_to,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to forward email (keep-copy)', [
                'email_id' => $email->id,
                'forward_to' => $forward->forward_to,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
