<?php

namespace App\Services\Email;

use App\Models\Mailbox;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendGridProvider implements EmailProviderInterface
{
    public function __construct(
        private SettingService $settingService,
    ) {}

    public function getName(): string
    {
        return 'sendgrid';
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $verificationKey = $this->settingService->get('sendgrid', 'webhook_verification_key');
        if (empty($verificationKey)) {
            // If no verification key is configured, accept all webhooks (log a warning)
            Log::warning('SendGrid webhook verification key not configured');
            return true;
        }

        $signature = $request->header('X-Twilio-Email-Event-Webhook-Signature');
        $timestamp = $request->header('X-Twilio-Email-Event-Webhook-Timestamp');

        if (empty($signature) || empty($timestamp)) {
            return false;
        }

        try {
            $payload = $timestamp . $request->getContent();
            $decodedKey = base64_decode($verificationKey);
            $decodedSignature = base64_decode($signature);

            $pubKey = openssl_pkey_get_public($decodedKey);
            if (!$pubKey) {
                return false;
            }

            return openssl_verify($payload, $decodedSignature, $pubKey, OPENSSL_ALGO_SHA256) === 1;
        } catch (\Exception $e) {
            Log::warning('SendGrid webhook verification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function parseInboundEmail(Request $request): ParsedEmail
    {
        // SendGrid Inbound Parse sends multipart/form-data
        $from = $request->input('from', '');
        $parsedFrom = $this->parseEmailAddress($from);

        $envelope = json_decode($request->input('envelope', '{}'), true);

        return new ParsedEmail(
            fromAddress: $parsedFrom['address'],
            fromName: $parsedFrom['name'],
            to: $this->parseAddressList($request->input('to', '')),
            cc: $this->parseAddressList($request->input('cc', '')),
            bcc: [],
            subject: $request->input('subject', ''),
            bodyText: $request->input('text', ''),
            bodyHtml: $request->input('html', ''),
            headers: $this->parseRawHeaders($request->input('headers', '')),
            attachments: $this->parseAttachments($request),
            messageId: $this->extractHeader($request->input('headers', ''), 'Message-ID'),
            inReplyTo: $this->extractHeader($request->input('headers', ''), 'In-Reply-To'),
            references: $this->extractHeader($request->input('headers', ''), 'References'),
            spamScore: $this->parseSpamScore($request),
            providerMessageId: null,
            providerEventId: uniqid('sg_', true),
            recipientAddress: $envelope['to'][0] ?? $request->input('to', ''),
        );
    }

    public function sendEmail(
        Mailbox $mailbox,
        array $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $attachments = [],
        array $cc = [],
        array $bcc = [],
        array $headers = [],
    ): SendResult {
        $domain = $mailbox->emailDomain;
        $config = $domain->provider_config ?? [];
        $apiKey = $config['api_key'] ?? $this->settingService->get('sendgrid', 'api_key');

        if (empty($apiKey)) {
            return SendResult::failure('SendGrid API key not configured');
        }

        $fromAddress = "{$mailbox->address}@{$domain->name}";

        $personalizations = [
            'to' => array_map(fn ($addr) => ['email' => is_array($addr) ? $addr['address'] : $addr], $to),
        ];
        if (!empty($cc)) {
            $personalizations['cc'] = array_map(fn ($addr) => ['email' => is_array($addr) ? $addr['address'] : $addr], $cc);
        }
        if (!empty($bcc)) {
            $personalizations['bcc'] = array_map(fn ($addr) => ['email' => is_array($addr) ? $addr['address'] : $addr], $bcc);
        }

        $payload = [
            'personalizations' => [$personalizations],
            'from' => [
                'email' => $fromAddress,
                'name' => $mailbox->display_name ?? $fromAddress,
            ],
            'subject' => $subject,
            'content' => [
                ['type' => 'text/html', 'value' => $html],
            ],
        ];

        if ($text) {
            array_unshift($payload['content'], ['type' => 'text/plain', 'value' => $text]);
        }

        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.sendgrid.com/v3/mail/send', $payload);

            if ($response->successful() || $response->status() === 202) {
                $messageId = $response->header('X-Message-Id') ?? '';
                return SendResult::success($messageId);
            }

            return SendResult::failure('SendGrid API error: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('SendGrid send failed', ['error' => $e->getMessage()]);
            return SendResult::failure($e->getMessage());
        }
    }

    public function parseDeliveryEvent(Request $request): array
    {
        $events = json_decode($request->getContent(), true) ?? [];
        $event = is_array($events) && isset($events[0]) ? $events[0] : $events;

        $eventType = $event['event'] ?? '';

        $status = match ($eventType) {
            'delivered' => 'delivered',
            'bounce' => 'bounced',
            'dropped' => 'failed',
            'deferred' => 'deferred',
            'spamreport' => 'complained',
            default => 'unknown',
        };

        return [
            'status' => $status,
            'provider_message_id' => $event['sg_message_id'] ?? null,
            'timestamp' => isset($event['timestamp']) ? date('c', $event['timestamp']) : now()->toIso8601String(),
            'details' => $event,
        ];
    }

    public function addDomain(string $domain, array $config = []): DomainResult
    {
        $apiKey = $config['api_key'] ?? $this->settingService->get('sendgrid', 'api_key');

        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.sendgrid.com/v3/whitelabel/domains', [
                    'domain' => $domain,
                    'automatic_security' => true,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $dnsRecords = [];
                foreach ($data['dns'] ?? [] as $name => $record) {
                    $dnsRecords[] = [
                        'type' => $record['type'] ?? 'CNAME',
                        'name' => $record['host'] ?? $name,
                        'value' => $record['data'] ?? '',
                    ];
                }
                return DomainResult::success((string) ($data['id'] ?? $domain), $dnsRecords);
            }

            return DomainResult::failure('SendGrid API error: ' . $response->body());
        } catch (\Exception $e) {
            return DomainResult::failure($e->getMessage());
        }
    }

    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult
    {
        $apiKey = $config['api_key'] ?? $this->settingService->get('sendgrid', 'api_key');

        try {
            // List authenticated domains and find this one
            $response = Http::withToken($apiKey)
                ->get('https://api.sendgrid.com/v3/whitelabel/domains');

            if ($response->successful()) {
                foreach ($response->json() as $d) {
                    if (($d['domain'] ?? '') === $domain) {
                        return new DomainVerificationResult($d['valid'] ?? false);
                    }
                }
                return new DomainVerificationResult(false, [], 'Domain not found in SendGrid');
            }

            return new DomainVerificationResult(false, [], 'SendGrid API error');
        } catch (\Exception $e) {
            return new DomainVerificationResult(false, [], $e->getMessage());
        }
    }

    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool
    {
        $apiKey = $config['api_key'] ?? $this->settingService->get('sendgrid', 'api_key');

        try {
            // Configure Inbound Parse webhook
            $response = Http::withToken($apiKey)
                ->post('https://api.sendgrid.com/v3/user/webhooks/parse/settings', [
                    'hostname' => $domain,
                    'url' => $webhookUrl,
                    'spam_check' => true,
                    'send_raw' => false,
                ]);

            return $response->successful() || $response->status() === 201;
        } catch (\Exception $e) {
            Log::error('Failed to configure SendGrid webhook', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function parseEmailAddress(string $raw): array
    {
        if (preg_match('/^"?([^"<]*)"?\s*<([^>]+)>/', $raw, $matches)) {
            return ['name' => trim($matches[1]), 'address' => trim($matches[2])];
        }
        return ['name' => null, 'address' => trim($raw)];
    }

    private function parseAddressList(string $raw): array
    {
        if (empty($raw)) return [];
        $addresses = [];
        foreach (preg_split('/,\s*/', $raw) as $part) {
            $parsed = $this->parseEmailAddress($part);
            $addresses[] = ['address' => $parsed['address'], 'name' => $parsed['name']];
        }
        return $addresses;
    }

    private function parseRawHeaders(string $headers): array
    {
        $result = [];
        foreach (explode("\n", $headers) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $result[trim($key)] = trim($value);
            }
        }
        return $result;
    }

    private function extractHeader(string $headers, string $name): ?string
    {
        $parsed = $this->parseRawHeaders($headers);
        return $parsed[$name] ?? null;
    }

    private function parseSpamScore(Request $request): ?float
    {
        $score = $request->input('spam_score');
        return $score !== null ? (float) $score : null;
    }

    private function parseAttachments(Request $request): array
    {
        $attachments = [];
        $info = json_decode($request->input('attachment-info', '{}'), true);

        foreach ($info as $key => $meta) {
            $file = $request->file($key);
            if ($file) {
                $attachments[] = [
                    'filename' => $meta['filename'] ?? $file->getClientOriginalName(),
                    'content_type' => $meta['type'] ?? $file->getMimeType(),
                    'size' => $file->getSize(),
                    'file' => $file,
                ];
            }
        }

        return $attachments;
    }
}
