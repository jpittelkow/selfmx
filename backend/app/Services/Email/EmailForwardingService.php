<?php

namespace App\Services\Email;

use App\Models\Email;
use App\Models\Mailbox;
use App\Models\MailboxForward;
use Illuminate\Support\Facades\Log;

class EmailForwardingService
{
    public function __construct(private DomainService $domainService) {}

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
     * Does not create a local Email record.
     */
    public function forwardParsed(ParsedEmail $parsed, Mailbox $mailbox, MailboxForward $forward): void
    {
        $this->sendViaProvider($mailbox, [
            'to' => [['address' => $forward->forward_to]],
            'subject' => $parsed->subject ?? '(no subject)',
            'html' => $parsed->bodyHtml ?? $parsed->bodyText ?? '',
            'text' => $parsed->bodyText,
            'headers' => [
                'X-Forwarded-To' => $forward->forward_to,
                'X-Original-From' => $parsed->fromAddress,
            ],
        ]);
    }

    /**
     * Forward from an existing Email record.
     * Used for keep-copy mode (keep_local_copy = true).
     */
    public function forwardEmail(Email $email, Mailbox $mailbox, MailboxForward $forward): void
    {
        $this->sendViaProvider($mailbox, [
            'to' => [['address' => $forward->forward_to]],
            'subject' => $email->subject ?? '(no subject)',
            'html' => $email->body_html ?? $email->body_text ?? '',
            'text' => $email->body_text,
            'headers' => [
                'X-Forwarded-To' => $forward->forward_to,
                'X-Original-From' => $email->from_address,
            ],
        ]);
    }

    /**
     * Send forwarded email directly via the provider, bypassing the full send pipeline.
     */
    private function sendViaProvider(Mailbox $mailbox, array $data): void
    {
        try {
            $mailbox->loadMissing('emailDomain');
            if (!$mailbox->emailDomain) {
                Log::warning('Cannot forward: mailbox domain is not configured', ['mailbox_id' => $mailbox->id]);
                return;
            }
            $provider = $this->domainService->resolveProvider($mailbox->emailDomain->provider);

            $result = $provider->sendEmail(
                $mailbox,
                $data['to'],
                $data['subject'],
                $data['html'],
                $data['text'] ?? null,
                [],
                [],
                [],
                $data['headers'] ?? []
            );

            if ($result->success) {
                Log::info('Email forwarded successfully', [
                    'mailbox_id' => $mailbox->id,
                    'forward_to' => $data['to'][0]['address'],
                    'provider_message_id' => $result->providerMessageId,
                ]);
            } else {
                Log::error('Forward send failed', [
                    'mailbox_id' => $mailbox->id,
                    'forward_to' => $data['to'][0]['address'],
                    'error' => $result->error,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception during forwarding', [
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
