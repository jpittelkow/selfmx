<?php

namespace App\Services\Email;

use App\Models\Email;
use App\Models\EmailRecipient;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmailImportService
{
    public function __construct(
        private EmailService $emailService,
    ) {}

    /**
     * Import emails from an mbox file.
     */
    public function importMbox(string $filePath, Mailbox $mailbox, User $user): ImportResult
    {
        $imported = 0;
        $skipped = 0;
        $failed = 0;

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return new ImportResult(0, 0, 1, ['Could not open file']);
        }

        $currentMessage = '';
        $errors = [];

        while (($line = fgets($handle)) !== false) {
            // mbox format: messages are separated by lines starting with "From "
            if (str_starts_with($line, 'From ') && $currentMessage !== '') {
                $result = $this->importSingleMessage($currentMessage, $mailbox, $user);
                match ($result) {
                    'imported' => $imported++,
                    'skipped' => $skipped++,
                    default => $failed++,
                };
                if (str_starts_with($result, 'error:')) {
                    $errors[] = substr($result, 6);
                }
                $currentMessage = '';
            } else {
                $currentMessage .= $line;
            }
        }

        // Process last message
        if (trim($currentMessage) !== '') {
            $result = $this->importSingleMessage($currentMessage, $mailbox, $user);
            match ($result) {
                'imported' => $imported++,
                'skipped' => $skipped++,
                default => $failed++,
            };
            if (str_starts_with($result, 'error:')) {
                $errors[] = substr($result, 6);
            }
        }

        fclose($handle);

        return new ImportResult($imported, $skipped, $failed, $errors);
    }

    /**
     * Import a single .eml file.
     */
    public function importEml(string $filePath, Mailbox $mailbox, User $user): ImportResult
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return new ImportResult(0, 0, 1, ['Could not read file']);
        }

        $result = $this->importSingleMessage($content, $mailbox, $user);

        return match ($result) {
            'imported' => new ImportResult(1, 0, 0),
            'skipped' => new ImportResult(0, 1, 0),
            default => new ImportResult(0, 0, 1, [str_starts_with($result, 'error:') ? substr($result, 6) : $result]),
        };
    }

    /**
     * Parse and import a single raw email message.
     *
     * @return string 'imported', 'skipped', or 'error:message'
     */
    private function importSingleMessage(string $rawMessage, Mailbox $mailbox, User $user): string
    {
        try {
            $parsed = $this->parseRawEmail($rawMessage);

            if (empty($parsed['from_address'])) {
                return 'error:No From address found';
            }

            // Deduplicate by message_id
            if (!empty($parsed['message_id'])) {
                $existing = Email::where('mailbox_id', $mailbox->id)
                    ->where('message_id', $parsed['message_id'])
                    ->exists();

                if ($existing) {
                    return 'skipped';
                }
            }

            $domainName = $mailbox->emailDomain?->name ?? $mailbox->domain_name ?? 'imported';
            $messageId = $parsed['message_id'] ?: $this->emailService->generateMessageId($domainName);
            $subject = $parsed['subject'] ?? '(No Subject)';
            $normalizedSubject = $this->emailService->normalizeSubject($subject);
            $sentAt = $parsed['date'] ?? now();

            // Determine direction
            $mailboxAddress = $mailbox->full_address;
            $direction = strtolower($parsed['from_address']) === strtolower($mailboxAddress) ? 'outbound' : 'inbound';

            DB::transaction(function () use ($parsed, $mailbox, $user, $messageId, $subject, $normalizedSubject, $sentAt, $direction) {
                // Resolve thread using headers
                $thread = $this->resolveImportThread($parsed, $mailbox, $normalizedSubject, $user);

                $email = Email::create([
                    'user_id' => $user->id,
                    'mailbox_id' => $mailbox->id,
                    'message_id' => $messageId,
                    'thread_id' => $thread?->id,
                    'direction' => $direction,
                    'from_address' => $parsed['from_address'],
                    'from_name' => $parsed['from_name'],
                    'subject' => $subject,
                    'body_text' => $parsed['body_text'] ?? '',
                    'body_html' => $parsed['body_html'] ?? '',
                    'headers' => $parsed['headers'] ?? [],
                    'in_reply_to' => $parsed['in_reply_to'],
                    'references' => $parsed['references'],
                    'is_read' => true,
                    'is_spam' => false,
                    'sent_at' => $sentAt,
                ]);

                // Create recipients
                foreach (['to', 'cc', 'bcc'] as $type) {
                    foreach ($parsed[$type] ?? [] as $addr) {
                        EmailRecipient::create([
                            'email_id' => $email->id,
                            'type' => $type,
                            'address' => $addr['address'] ?? $addr,
                            'name' => $addr['name'] ?? null,
                        ]);
                    }
                }

                // Update thread counters
                if ($thread) {
                    $thread->update([
                        'last_message_at' => max($thread->last_message_at ?? $sentAt, $sentAt),
                        'message_count' => $thread->emails()->count(),
                    ]);
                }
            });

            return 'imported';
        } catch (\Exception $e) {
            Log::warning('Email import failed for single message', ['error' => $e->getMessage()]);
            return 'error:' . $e->getMessage();
        }
    }

    /**
     * Resolve or create a thread for an imported message.
     */
    private function resolveImportThread(array $parsed, Mailbox $mailbox, ?string $normalizedSubject, User $user): ?EmailThread
    {
        $domainMailboxIds = $mailbox->email_domain_id
            ? Mailbox::where('email_domain_id', $mailbox->email_domain_id)->pluck('id')->toArray()
            : [$mailbox->id];

        // Try In-Reply-To
        if (!empty($parsed['in_reply_to'])) {
            $ref = Email::whereIn('mailbox_id', $domainMailboxIds)
                ->where('message_id', $parsed['in_reply_to'])
                ->first();

            if ($ref?->thread_id) {
                return EmailThread::find($ref->thread_id);
            }
        }

        // Try References
        if (!empty($parsed['references'])) {
            $referenceIds = preg_split('/\s+/', $parsed['references']);
            $ref = Email::whereIn('mailbox_id', $domainMailboxIds)
                ->whereIn('message_id', $referenceIds)
                ->whereNotNull('thread_id')
                ->first();

            if ($ref?->thread_id) {
                return EmailThread::find($ref->thread_id);
            }
        }

        // Try subject match
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

        // Create new thread
        return EmailThread::create([
            'user_id' => $user->id,
            'subject' => $normalizedSubject,
            'last_message_at' => $parsed['date'] ?? now(),
            'message_count' => 0,
        ]);
    }

    /**
     * Parse a raw email message (RFC 822) into structured data.
     */
    private function parseRawEmail(string $raw): array
    {
        // Split headers from body
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerSection = $parts[0] ?? '';
        $bodySection = $parts[1] ?? '';

        // Unfold headers (continuation lines start with whitespace)
        $headerSection = preg_replace('/\r?\n[ \t]+/', ' ', $headerSection);

        $headers = [];
        foreach (explode("\n", $headerSection) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);
            // Keep first occurrence for single-value headers, merge for Received etc.
            if (!isset($headers[$name])) {
                $headers[$name] = $value;
            }
        }

        $from = $this->parseEmailAddressHeader($headers['From'] ?? '');
        $to = $this->parseEmailAddressList($headers['To'] ?? '');
        $cc = $this->parseEmailAddressList($headers['Cc'] ?? '');

        // Parse date
        $dateStr = $headers['Date'] ?? null;
        $date = null;
        if ($dateStr) {
            try {
                $date = new \DateTimeImmutable($dateStr);
            } catch (\Exception) {
                // If date parsing fails, leave null
            }
        }

        // Extract body parts from MIME or plain
        $contentType = $headers['Content-Type'] ?? 'text/plain';
        $bodyText = '';
        $bodyHtml = '';

        if (str_contains(strtolower($contentType), 'multipart/')) {
            $boundary = $this->extractBoundary($contentType);
            if ($boundary) {
                $parts = $this->splitMimeParts($bodySection, $boundary);
                foreach ($parts as $part) {
                    $partData = $this->parseMimePart($part);
                    $partType = strtolower($partData['content_type'] ?? 'text/plain');
                    if (str_contains($partType, 'text/plain') && empty($bodyText)) {
                        $bodyText = $partData['body'];
                    } elseif (str_contains($partType, 'text/html') && empty($bodyHtml)) {
                        $bodyHtml = $partData['body'];
                    } elseif (str_contains($partType, 'multipart/')) {
                        // Handle nested multipart
                        $nestedBoundary = $this->extractBoundary($partData['content_type_raw'] ?? $partType);
                        if ($nestedBoundary) {
                            foreach ($this->splitMimeParts($partData['body'], $nestedBoundary) as $nested) {
                                $nestedData = $this->parseMimePart($nested);
                                $nestedType = strtolower($nestedData['content_type'] ?? '');
                                if (str_contains($nestedType, 'text/plain') && empty($bodyText)) {
                                    $bodyText = $nestedData['body'];
                                } elseif (str_contains($nestedType, 'text/html') && empty($bodyHtml)) {
                                    $bodyHtml = $nestedData['body'];
                                }
                            }
                        }
                    }
                }
            }
        } else {
            if (str_contains(strtolower($contentType), 'text/html')) {
                $bodyHtml = $this->decodeBody($bodySection, $headers['Content-Transfer-Encoding'] ?? null);
            } else {
                $bodyText = $this->decodeBody($bodySection, $headers['Content-Transfer-Encoding'] ?? null);
            }
        }

        return [
            'from_address' => $from['address'],
            'from_name' => $from['name'],
            'to' => $to,
            'cc' => $cc,
            'bcc' => [],
            'subject' => $this->decodeMimeHeader($headers['Subject'] ?? ''),
            'message_id' => trim($headers['Message-ID'] ?? $headers['Message-Id'] ?? '', '<> '),
            'in_reply_to' => isset($headers['In-Reply-To']) ? trim($headers['In-Reply-To'], '<> ') : null,
            'references' => $headers['References'] ?? null,
            'date' => $date,
            'headers' => $headers,
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
        ];
    }

    private function parseEmailAddressHeader(string $raw): array
    {
        $raw = $this->decodeMimeHeader($raw);
        if (preg_match('/^"?([^"<]*)"?\s*<([^>]+)>/', $raw, $matches)) {
            return ['name' => trim($matches[1]), 'address' => trim($matches[2])];
        }
        return ['name' => null, 'address' => trim($raw)];
    }

    private function parseEmailAddressList(string $raw): array
    {
        if (empty($raw)) {
            return [];
        }
        $raw = $this->decodeMimeHeader($raw);
        $addresses = [];
        // Split on commas not inside angle brackets
        foreach (preg_split('/,\s*(?=[^>]*(?:<|$))/', $raw) as $part) {
            $parsed = $this->parseEmailAddressHeader(trim($part));
            if (!empty($parsed['address'])) {
                $addresses[] = $parsed;
            }
        }
        return $addresses;
    }

    private function decodeMimeHeader(string $header): string
    {
        // Decode RFC 2047 encoded words (=?charset?encoding?text?=)
        return preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]+)\?=/', function ($m) {
            $charset = $m[1];
            $encoding = strtoupper($m[2]);
            $text = $m[3];

            if ($encoding === 'B') {
                $decoded = base64_decode($text);
            } else {
                $decoded = quoted_printable_decode(str_replace('_', ' ', $text));
            }

            if (strtoupper($charset) !== 'UTF-8') {
                $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
                if ($converted !== false) {
                    $decoded = $converted;
                }
            }
            return $decoded;
        }, $header) ?? $header;
    }

    private function extractBoundary(string $contentType): ?string
    {
        if (preg_match('/boundary=["\']?([^"\';,\s]+)/i', $contentType, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function splitMimeParts(string $body, string $boundary): array
    {
        $parts = explode('--' . $boundary, $body);
        // Remove preamble (first element) and epilogue (last element if it ends with --)
        array_shift($parts);
        $parts = array_filter($parts, fn ($p) => trim($p) !== '--' && trim($p) !== '');
        return array_values($parts);
    }

    private function parseMimePart(string $part): array
    {
        $sections = preg_split('/\r?\n\r?\n/', $part, 2);
        $headerSection = trim($sections[0] ?? '');
        $body = $sections[1] ?? '';

        // Unfold headers
        $headerSection = preg_replace('/\r?\n[ \t]+/', ' ', $headerSection);

        $headers = [];
        $contentTypeRaw = '';
        foreach (explode("\n", $headerSection) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
            if (strtolower(trim($name)) === 'content-type') {
                $contentTypeRaw = trim($value);
            }
        }

        $contentType = strtok($contentTypeRaw ?: ($headers['Content-Type'] ?? 'text/plain'), ';');
        $encoding = $headers['Content-Transfer-Encoding'] ?? null;

        return [
            'content_type' => trim($contentType),
            'content_type_raw' => $contentTypeRaw,
            'body' => $this->decodeBody(trim($body), $encoding),
            'headers' => $headers,
        ];
    }

    private function decodeBody(string $body, ?string $encoding): string
    {
        $encoding = strtolower(trim($encoding ?? ''));

        return match ($encoding) {
            'base64' => base64_decode($body) ?: $body,
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }
}
