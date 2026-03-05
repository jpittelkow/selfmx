<?php

namespace App\Services\Email;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\EmailDomain;
use App\Models\EmailRecipient;
use App\Models\EmailThread;
use App\Models\EmailWebhookLog;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\AuditService;
use App\Services\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmailService
{
    public function __construct(
        private SpamFilterService $spamFilterService,
        private AuditService $auditService,
        private StorageService $storageService,
        private DomainService $domainService,
    ) {}

    /**
     * Send an outbound email via the provider.
     */
    public function sendEmail(User $user, array $data): Email
    {
        $accessService = app(MailboxAccessService::class);

        $mailbox = Mailbox::where('id', $data['mailbox_id'])
            ->where('is_active', true)
            ->with('emailDomain')
            ->firstOrFail();

        if (!$accessService->canSend($user, $mailbox->id)) {
            abort(403, 'You do not have permission to send from this mailbox.');
        }

        $domain = $mailbox->emailDomain;
        $messageId = $this->generateMessageId($domain->name);
        $subject = $data['subject'] ?? '';
        $normalizedSubject = $this->normalizeSubject($subject);

        // Resolve thread — scope by mailbox access, not user_id
        $mailboxIds = $accessService->getAccessibleMailboxIds($user);
        $thread = null;
        if (!empty($data['thread_id'])) {
            $thread = EmailThread::where('id', $data['thread_id'])
                ->whereHas('emails', fn ($q) => $q->whereIn('mailbox_id', $mailboxIds))
                ->first();
        }

        if (!$thread && !empty($data['in_reply_to'])) {
            $referencedEmail = Email::whereIn('mailbox_id', $mailboxIds)
                ->where('message_id', $data['in_reply_to'])
                ->first();
            if ($referencedEmail?->thread_id) {
                $thread = EmailThread::find($referencedEmail->thread_id);
            }
        }

        if (!$thread) {
            $thread = EmailThread::create([
                'user_id' => $mailbox->user_id,
                'subject' => $normalizedSubject,
                'last_message_at' => now(),
                'message_count' => 0,
            ]);
        }

        // Handle draft conversion or create new email
        $draftId = $data['draft_id'] ?? null;
        $email = null;

        $email = DB::transaction(function () use ($user, $mailbox, $messageId, $data, $thread, $subject, $draftId, $mailboxIds) {
            if ($draftId) {
                $email = Email::where('id', $draftId)
                    ->whereIn('mailbox_id', $mailboxIds)
                    ->where('is_draft', true)
                    ->firstOrFail();

                // Delete old recipients to replace with current ones
                $email->recipients()->delete();

                $email->update([
                    'mailbox_id' => $mailbox->id,
                    'message_id' => $messageId,
                    'thread_id' => $thread->id,
                    'direction' => 'outbound',
                    'from_address' => $mailbox->full_address,
                    'from_name' => $mailbox->display_name,
                    'subject' => $subject,
                    'body_html' => $data['body_html'] ?? $email->body_html,
                    'body_text' => $data['body_text'] ?? $email->body_text,
                    'in_reply_to' => $data['in_reply_to'] ?? null,
                    'references' => $data['references'] ?? null,
                    'is_draft' => false,
                    'is_read' => true,
                    'delivery_status' => 'sending',
                    'sent_at' => now(),
                ]);
            } else {
                $email = Email::create([
                    'user_id' => $user->id,
                    'mailbox_id' => $mailbox->id,
                    'message_id' => $messageId,
                    'thread_id' => $thread->id,
                    'direction' => 'outbound',
                    'from_address' => $mailbox->full_address,
                    'from_name' => $mailbox->display_name,
                    'subject' => $subject,
                    'body_html' => $data['body_html'] ?? '',
                    'body_text' => $data['body_text'] ?? null,
                    'in_reply_to' => $data['in_reply_to'] ?? null,
                    'references' => $data['references'] ?? null,
                    'is_read' => true,
                    'is_draft' => false,
                    'delivery_status' => 'sending',
                    'sent_at' => now(),
                ]);
            }

            // Create recipients
            $this->createOutboundRecipients($email, $data);

            // Store new attachments if provided
            if (!empty($data['attachments'])) {
                $this->storeUploadedAttachments($email, $data['attachments']);
            }

            // Update thread counters
            $thread->update([
                'last_message_at' => now(),
                'message_count' => $thread->emails()->count(),
            ]);

            return $email;
        });

        // Call provider outside transaction
        $provider = $this->domainService->resolveProvider($domain->provider);

        $headers = [];
        if (!empty($data['in_reply_to'])) {
            $headers['In-Reply-To'] = $data['in_reply_to'];
        }
        if (!empty($data['references'])) {
            $headers['References'] = $data['references'];
        }
        $headers['Message-Id'] = $messageId;

        // Build attachment list for provider
        $providerAttachments = [];
        $tempFiles = [];
        $email->load('attachments');
        $disk = $this->storageService->getDisk();
        foreach ($email->attachments as $attachment) {
            if ($disk->exists($attachment->storage_path)) {
                $tempPath = tempnam(sys_get_temp_dir(), 'email_att_');
                file_put_contents($tempPath, $disk->get($attachment->storage_path));
                $providerAttachments[] = [
                    'path' => $tempPath,
                    'filename' => $attachment->filename,
                ];
                $tempFiles[] = $tempPath;
            }
        }

        try {
            $result = $provider->sendEmail(
                $mailbox,
                $data['to'],
                $subject,
                $data['body_html'] ?? $email->body_html,
                $data['body_text'] ?? $email->body_text,
                $providerAttachments,
                $data['cc'] ?? [],
                $data['bcc'] ?? [],
                $headers,
            );

            if ($result->success) {
                $email->update([
                    'provider_message_id' => $result->providerMessageId,
                    'delivery_status' => 'queued',
                ]);
            } else {
                $email->update(['delivery_status' => 'failed']);
                Log::error('Email send failed', ['email_id' => $email->id, 'error' => $result->error]);
            }
        } finally {
            foreach ($tempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        }

        $this->auditService->log('email.sent', $email, [], [
            'to' => $data['to'],
            'subject' => $subject,
        ]);

        // Extract contacts from sent email
        app(ContactService::class)->extractFromEmail($email);

        return $email->fresh(['recipients', 'attachments', 'labels']);
    }

    /**
     * Save an email as a draft.
     */
    public function saveDraft(User $user, array $data): Email
    {
        $accessService = app(MailboxAccessService::class);

        $mailbox = null;
        if (!empty($data['mailbox_id'])) {
            $mailbox = Mailbox::where('id', $data['mailbox_id'])
                ->with('emailDomain')
                ->first();

            if ($mailbox && !$accessService->canSend($user, $mailbox->id)) {
                abort(403, 'You do not have permission to send from this mailbox.');
            }
        }

        // Resolve thread if reply context provided
        $threadId = $data['thread_id'] ?? null;

        return DB::transaction(function () use ($user, $mailbox, $data, $threadId) {
            $email = Email::create([
                'user_id' => $user->id,
                'mailbox_id' => $mailbox?->id,
                'message_id' => $this->generateMessageId($mailbox?->emailDomain?->name),
                'thread_id' => $threadId,
                'direction' => 'outbound',
                'from_address' => $mailbox?->full_address,
                'from_name' => $mailbox?->display_name,
                'subject' => $data['subject'] ?? '',
                'body_html' => $data['body_html'] ?? '',
                'body_text' => $data['body_text'] ?? null,
                'in_reply_to' => $data['in_reply_to'] ?? null,
                'references' => $data['references'] ?? null,
                'is_read' => true,
                'is_draft' => true,
            ]);

            $this->createOutboundRecipients($email, $data);

            if (!empty($data['attachments'])) {
                $this->storeUploadedAttachments($email, $data['attachments']);
            }

            return $email->fresh(['recipients', 'attachments']);
        });
    }

    /**
     * Update an existing draft.
     */
    public function updateDraft(Email $draft, array $data): Email
    {
        return DB::transaction(function () use ($draft, $data) {
            $updateFields = [];
            if (array_key_exists('mailbox_id', $data)) {
                $updateFields['mailbox_id'] = $data['mailbox_id'];
                $mailbox = Mailbox::find($data['mailbox_id']);
                if ($mailbox) {
                    $mailbox->load('emailDomain');
                    $updateFields['from_address'] = $mailbox->full_address;
                    $updateFields['from_name'] = $mailbox->display_name;
                }
            }
            if (array_key_exists('subject', $data)) {
                $updateFields['subject'] = $data['subject'];
            }
            if (array_key_exists('body_html', $data)) {
                $updateFields['body_html'] = $data['body_html'];
            }
            if (array_key_exists('body_text', $data)) {
                $updateFields['body_text'] = $data['body_text'];
            }
            if (array_key_exists('in_reply_to', $data)) {
                $updateFields['in_reply_to'] = $data['in_reply_to'];
            }
            if (array_key_exists('references', $data)) {
                $updateFields['references'] = $data['references'];
            }
            if (array_key_exists('thread_id', $data)) {
                $updateFields['thread_id'] = $data['thread_id'];
            }

            if (!empty($updateFields)) {
                $draft->update($updateFields);
            }

            // Replace recipients
            if (array_key_exists('to', $data) || array_key_exists('cc', $data) || array_key_exists('bcc', $data)) {
                $draft->recipients()->delete();
                $this->createOutboundRecipients($draft, $data);
            }

            // Handle new attachments
            if (!empty($data['attachments'])) {
                $this->storeUploadedAttachments($draft, $data['attachments']);
            }

            // Remove attachments not in keep list
            if (array_key_exists('attachment_ids_to_keep', $data)) {
                $keepIds = $data['attachment_ids_to_keep'];
                $toRemove = $draft->attachments()->whereNotIn('id', $keepIds)->get();
                foreach ($toRemove as $att) {
                    try {
                        $this->storageService->deleteFile($att->storage_path);
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete draft attachment', ['path' => $att->storage_path]);
                    }
                    $att->delete();
                }
            }

            return $draft->fresh(['recipients', 'attachments']);
        });
    }

    /**
     * Build pre-fill data for reply/reply-all/forward.
     */
    public function buildReplyData(Email $email, string $type, User $user): array
    {
        $to = [];
        $cc = [];
        $subject = $email->subject ?? '';

        // Get user's accessible mailbox addresses to exclude from recipients
        $accessService = app(MailboxAccessService::class);
        $mailboxIds = $accessService->getAccessibleMailboxIds($user);
        $userMailboxAddresses = Mailbox::whereIn('id', $mailboxIds)
            ->with('emailDomain')
            ->get()
            ->map(fn ($m) => strtolower($m->full_address))
            ->toArray();

        if ($type === 'reply') {
            $to = [$email->from_address];
            if (!preg_match('/^Re:\s/i', $subject)) {
                $subject = "Re: {$subject}";
            }
        } elseif ($type === 'reply_all') {
            // Start with from_address unless it's the user's own
            if (!in_array(strtolower($email->from_address), $userMailboxAddresses)) {
                $to = [$email->from_address];
            }

            // Add all To recipients except the user's own addresses
            $toRecipients = $email->recipients->where('type', 'to');
            foreach ($toRecipients as $r) {
                if (!in_array(strtolower($r->address), $userMailboxAddresses)) {
                    $to[] = $r->address;
                }
            }
            $to = array_values(array_unique($to));

            // CC recipients except user's addresses
            $ccRecipients = $email->recipients->where('type', 'cc');
            foreach ($ccRecipients as $r) {
                if (!in_array(strtolower($r->address), $userMailboxAddresses)) {
                    $cc[] = $r->address;
                }
            }

            if (!preg_match('/^Re:\s/i', $subject)) {
                $subject = "Re: {$subject}";
            }
        } elseif ($type === 'forward') {
            if (!preg_match('/^Fwd:\s/i', $subject)) {
                $subject = "Fwd: {$subject}";
            }
        }

        // Build quoted message
        $date = $email->sent_at?->format('D, M j, Y \a\t g:i A') ?? '';
        $from = $email->from_name
            ? "{$email->from_name} &lt;{$email->from_address}&gt;"
            : $email->from_address;

        $quotedHtml = "<br><br><div class=\"gmail_quote\"><p>On {$date}, {$from} wrote:</p><blockquote style=\"margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex\">"
            . ($email->body_html ?: nl2br(e($email->body_text ?? '')))
            . "</blockquote></div>";

        $quotedText = "\n\nOn {$date}, {$from} wrote:\n> "
            . str_replace("\n", "\n> ", $email->body_text ?? '');

        // Determine which mailbox was used for this conversation
        $mailboxId = $email->mailbox_id;

        // For forward, include original attachments metadata
        $originalAttachments = [];
        if ($type === 'forward') {
            $originalAttachments = $email->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'size' => $a->size,
                'mime_type' => $a->mime_type,
            ])->toArray();
        }

        // Build references chain
        $references = $email->references
            ? $email->references . ' ' . $email->message_id
            : $email->message_id;

        return [
            'to' => array_values($to),
            'cc' => array_values($cc),
            'subject' => $subject,
            'in_reply_to' => $type !== 'forward' ? $email->message_id : null,
            'references' => $type !== 'forward' ? $references : null,
            'thread_id' => $type !== 'forward' ? $email->thread_id : null,
            'quoted_html' => $quotedHtml,
            'quoted_text' => $quotedText,
            'mailbox_id' => $mailboxId,
            'original_attachments' => $originalAttachments,
        ];
    }

    /**
     * Process an inbound email from a webhook.
     */
    public function processInboundEmail(ParsedEmail $parsed, string $provider): ?Email
    {
        // Check idempotency
        if ($parsed->providerEventId) {
            $existing = EmailWebhookLog::where('provider_event_id', $parsed->providerEventId)->first();
            if ($existing) {
                Log::info('Duplicate webhook event ignored', ['event_id' => $parsed->providerEventId]);
                return null;
            }
        }

        // Find the matching mailbox
        $mailbox = $this->resolveMailbox($parsed->recipientAddress);
        if (!$mailbox) {
            $this->logWebhook($provider, $parsed->providerEventId, 'inbound', $parsed, 'failed', 'No matching mailbox found');
            Log::warning('No mailbox found for recipient', ['recipient' => $parsed->recipientAddress]);
            return null;
        }

        // Deduplicate by message_id within the same mailbox
        if (!empty($parsed->messageId)) {
            $existingEmail = Email::where('mailbox_id', $mailbox->id)
                ->where('message_id', $parsed->messageId)
                ->first();
            if ($existingEmail) {
                Log::info('Duplicate email ignored', [
                    'message_id' => $parsed->messageId,
                    'mailbox_id' => $mailbox->id,
                ]);
                $this->logWebhook($provider, $parsed->providerEventId, 'inbound', $parsed, 'duplicate');
                return null;
            }
        }

        // Check for mailbox-level forwarding (pass-through mode skips local storage)
        $forwardingService = app(EmailForwardingService::class);
        $activeForward = $forwardingService->getActiveForward($mailbox);

        if ($activeForward && !$activeForward->keep_local_copy) {
            $forwardingService->forwardParsed($parsed, $mailbox, $activeForward);
            $this->logWebhook($provider, $parsed->providerEventId, 'inbound', $parsed, 'forwarded');
            return null;
        }

        try {
            $email = DB::transaction(function () use ($parsed, $mailbox, $provider) {
                // Check spam (with user-specific allow/block lists)
                $isSpam = $this->spamFilterService->isSpam($parsed, $mailbox->user_id);

                // Resolve or create thread
                $thread = $this->resolveThread($parsed, $mailbox);

                // Create the email record
                $email = Email::create([
                    'user_id' => $mailbox->user_id,
                    'mailbox_id' => $mailbox->id,
                    'message_id' => $parsed->messageId ?: $this->generateMessageId(),
                    'thread_id' => $thread?->id,
                    'provider_message_id' => $parsed->providerMessageId,
                    'direction' => 'inbound',
                    'from_address' => $parsed->fromAddress,
                    'from_name' => $parsed->fromName,
                    'subject' => $parsed->subject,
                    'body_text' => $parsed->bodyText,
                    'body_html' => $parsed->bodyHtml,
                    'headers' => $parsed->headers,
                    'in_reply_to' => $parsed->inReplyTo,
                    'references' => $parsed->references,
                    'is_read' => false,
                    'is_spam' => $isSpam,
                    'spam_score' => $parsed->spamScore,
                    'sent_at' => now(),
                ]);

                // Create recipients
                $this->createRecipients($email, $parsed);

                // Store attachments
                $this->storeAttachments($email, $parsed->attachments);

                // Update thread counters
                if ($thread) {
                    $thread->update([
                        'last_message_at' => $email->sent_at,
                        'message_count' => $thread->emails()->count(),
                    ]);
                }

                return $email;
            });

            // Log the webhook
            $this->logWebhook($provider, $parsed->providerEventId, 'inbound', $parsed, 'processed');

            // Forward email if mailbox has keep-copy forwarding configured
            if ($activeForward && $activeForward->keep_local_copy) {
                $forwardingService->forwardEmail($email, $activeForward);
            }

            // Extract contacts from inbound email
            app(ContactService::class)->extractFromEmail($email);

            // Dispatch AI processing if user has it enabled and email is not spam
            if (!$email->is_spam) {
                app(EmailAIService::class)->dispatchProcessing($email);
            }

            // Evaluate email rules (auto-label, auto-archive, etc.)
            app(EmailRuleService::class)->evaluateRules($email, $mailbox->user_id);

            // Broadcast real-time push to the mailbox owner
            broadcast(new \App\Events\EmailReceived($mailbox->user_id, $email->fresh()))->toOthers();

            return $email;
        } catch (QueryException $e) {
            // Unique constraint violation — race condition where two webhooks for the same
            // email arrived simultaneously and both passed the app-level dedup check
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed') || str_contains($e->getMessage(), 'Duplicate entry') || $e->getCode() === '23000') {
                Log::info('Duplicate email caught by unique constraint', [
                    'message_id' => $parsed->messageId,
                    'mailbox_id' => $mailbox->id,
                ]);
                $this->logWebhook($provider, $parsed->providerEventId, 'inbound', $parsed, 'duplicate');
                return null;
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to process inbound email', [
                'error' => $e->getMessage(),
                'recipient' => $parsed->recipientAddress,
            ]);

            $this->logWebhook($provider, $parsed->providerEventId, 'inbound', $parsed, 'failed', $e->getMessage());

            throw $e;
        }
    }

    /**
     * Find the mailbox for a given email address, falling back to catchall.
     */
    public function resolveMailbox(string $emailAddress): ?Mailbox
    {
        $parts = explode('@', strtolower($emailAddress));
        if (count($parts) !== 2) {
            return null;
        }

        [$localPart, $domainName] = $parts;

        // Find the domain
        $domain = EmailDomain::where('name', $domainName)
            ->where('is_active', true)
            ->first();

        if (!$domain) {
            return null;
        }

        // Try exact match first
        $mailbox = Mailbox::where('email_domain_id', $domain->id)
            ->where('address', $localPart)
            ->where('is_active', true)
            ->first();

        if ($mailbox) {
            return $mailbox;
        }

        // Fall back to catchall
        if ($domain->catchall_mailbox_id) {
            return Mailbox::where('id', $domain->catchall_mailbox_id)
                ->where('is_active', true)
                ->first();
        }

        // Try wildcard mailbox
        return Mailbox::where('email_domain_id', $domain->id)
            ->where('address', '*')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Resolve or create a thread for an email based on headers.
     * Scopes lookups by mailbox (and same-domain mailboxes) for shared mailbox support.
     */
    public function resolveThread(ParsedEmail $parsed, Mailbox $mailbox): ?EmailThread
    {
        // Get all mailbox IDs on the same domain for broader matching
        $domainMailboxIds = Mailbox::where('email_domain_id', $mailbox->email_domain_id)
            ->pluck('id')
            ->toArray();

        // Try to find thread via In-Reply-To header
        if ($parsed->inReplyTo) {
            // Try same mailbox first, then same domain
            $referencedEmail = Email::where('mailbox_id', $mailbox->id)
                ->where('message_id', $parsed->inReplyTo)
                ->first();

            if (!$referencedEmail) {
                $referencedEmail = Email::whereIn('mailbox_id', $domainMailboxIds)
                    ->where('message_id', $parsed->inReplyTo)
                    ->first();
            }

            if ($referencedEmail?->thread_id) {
                return EmailThread::find($referencedEmail->thread_id);
            }
        }

        // Try via References header
        if ($parsed->references) {
            $referenceIds = preg_split('/\s+/', $parsed->references);
            $referencedEmail = Email::whereIn('mailbox_id', $domainMailboxIds)
                ->whereIn('message_id', $referenceIds)
                ->whereNotNull('thread_id')
                ->first();

            if ($referencedEmail?->thread_id) {
                return EmailThread::find($referencedEmail->thread_id);
            }
        }

        // Try subject-based matching as fallback (strip Re:/Fwd: prefixes)
        $normalizedSubject = $this->normalizeSubject($parsed->subject);
        if ($normalizedSubject) {
            $existingThread = EmailThread::whereHas('emails', function ($q) use ($domainMailboxIds) {
                    $q->whereIn('mailbox_id', $domainMailboxIds);
                })
                ->where('subject', $normalizedSubject)
                ->orderByDesc('last_message_at')
                ->first();

            if ($existingThread) {
                return $existingThread;
            }
        }

        // Create a new thread (user_id = mailbox primary owner)
        return EmailThread::create([
            'user_id' => $mailbox->user_id,
            'subject' => $normalizedSubject,
            'last_message_at' => now(),
            'message_count' => 0,
        ]);
    }

    /**
     * Strip Re:/Fwd:/Fw: prefixes from subject for threading.
     */
    public function normalizeSubject(?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        // Remove common reply/forward prefixes (case insensitive, repeated)
        return trim(preg_replace('/^(\s*(Re|Fwd|Fw)\s*:\s*)+/i', '', $subject));
    }

    public function markAsRead(Email $email): void
    {
        $email->update(['is_read' => true]);
    }

    public function markAsUnread(Email $email): void
    {
        $email->update(['is_read' => false]);
    }

    public function toggleStar(Email $email): void
    {
        $email->update(['is_starred' => !$email->is_starred]);
    }

    public function moveToTrash(Email $email): void
    {
        $email->update(['is_trashed' => true]);
    }

    public function restoreFromTrash(Email $email): void
    {
        $email->update(['is_trashed' => false]);
    }

    /**
     * Permanently delete an email and its attachments.
     */
    public function deleteForever(Email $email): void
    {
        foreach ($email->attachments as $attachment) {
            try {
                $this->storageService->deleteFile($attachment->storage_path);
            } catch (\Exception $e) {
                Log::warning('Failed to delete attachment file', [
                    'path' => $attachment->storage_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $email->delete();
    }

    public function toggleSpam(Email $email): void
    {
        $email->update(['is_spam' => !$email->is_spam]);
    }

    /**
     * Create recipient records for an inbound email.
     */
    private function createRecipients(Email $email, ParsedEmail $parsed): void
    {
        foreach ($parsed->to as $to) {
            EmailRecipient::create([
                'email_id' => $email->id,
                'type' => 'to',
                'address' => $to['address'],
                'name' => $to['name'] ?? null,
            ]);
        }

        foreach ($parsed->cc as $cc) {
            EmailRecipient::create([
                'email_id' => $email->id,
                'type' => 'cc',
                'address' => $cc['address'],
                'name' => $cc['name'] ?? null,
            ]);
        }

        foreach ($parsed->bcc as $bcc) {
            EmailRecipient::create([
                'email_id' => $email->id,
                'type' => 'bcc',
                'address' => $bcc['address'],
                'name' => $bcc['name'] ?? null,
            ]);
        }
    }

    /**
     * Create recipient records for an outbound email from array data.
     */
    private function createOutboundRecipients(Email $email, array $data): void
    {
        foreach ($data['to'] ?? [] as $address) {
            EmailRecipient::create([
                'email_id' => $email->id,
                'type' => 'to',
                'address' => $address,
            ]);
        }

        foreach ($data['cc'] ?? [] as $address) {
            EmailRecipient::create([
                'email_id' => $email->id,
                'type' => 'cc',
                'address' => $address,
            ]);
        }

        foreach ($data['bcc'] ?? [] as $address) {
            EmailRecipient::create([
                'email_id' => $email->id,
                'type' => 'bcc',
                'address' => $address,
            ]);
        }
    }

    /**
     * Store uploaded file attachments.
     */
    private function storeUploadedAttachments(Email $email, array $files): void
    {
        foreach ($files as $file) {
            if (!($file instanceof UploadedFile)) {
                continue;
            }

            $path = "email-attachments/{$email->user_id}/{$email->id}";
            $result = $this->storageService->uploadFile($file, $path);

            EmailAttachment::create([
                'email_id' => $email->id,
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'storage_path' => $result['path'],
                'is_inline' => false,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Store email attachments from webhook data.
     */
    private function storeAttachments(Email $email, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $file = $attachment['content'];
            $path = "email-attachments/{$email->user_id}/{$email->id}";

            $result = $this->storageService->uploadFile($file, $path);

            EmailAttachment::create([
                'email_id' => $email->id,
                'filename' => $attachment['filename'],
                'mime_type' => $attachment['mimeType'],
                'size' => $attachment['size'],
                'storage_path' => $result['path'],
                'content_id' => $attachment['contentId'] ?? null,
                'is_inline' => $attachment['isInline'] ?? false,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Log a webhook event for idempotency and debugging.
     */
    private function logWebhook(string $provider, ?string $eventId, string $eventType, ParsedEmail $parsed, string $status, ?string $error = null): void
    {
        EmailWebhookLog::create([
            'provider' => $provider,
            'provider_event_id' => $eventId ?: uniqid('evt_', true),
            'event_type' => $eventType,
            'payload' => [
                'from' => $parsed->fromAddress,
                'to' => $parsed->recipientAddress,
                'subject' => $parsed->subject,
                'message_id' => $parsed->messageId,
            ],
            'status' => $status,
            'error_message' => $error,
            'created_at' => now(),
        ]);
    }

    /**
     * Generate a unique Message-ID.
     */
    public function generateMessageId(?string $domain = null): string
    {
        $domain = $domain ?? parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost';
        return '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
    }
}
