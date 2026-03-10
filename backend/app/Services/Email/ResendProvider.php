<?php

namespace App\Services\Email;

use App\Exceptions\ResendApiException;
use App\Models\Mailbox;
use App\Services\Email\Concerns\HasEventLog;
use App\Services\Email\Concerns\HasWebhookManagement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResendProvider implements
    EmailProviderInterface,
    ProviderManagementInterface,
    HasWebhookManagement,
    HasEventLog
{
    private const BASE_URL = 'https://api.resend.com';

    public function getName(): string
    {
        return 'resend';
    }

    public function getCapabilities(): array
    {
        return [
            'dkim_rotation'     => false,
            'webhooks'          => true,
            'inbound_routes'    => false,
            'events'            => true,
            'suppressions'      => false,
            'stats'             => false,
            'domain_management' => true,
            'dns_records'       => true,
        ];
    }

    // -------------------------------------------------------------------------
    // EmailProviderInterface
    // -------------------------------------------------------------------------

    public function verifyWebhookSignature(Request $request): bool
    {
        // Resend uses Svix for webhook signing. Verify HMAC-SHA256 if secret is available.
        // Without a signing secret, we accept the webhook (URL secrecy).
        $signingSecret = $this->getSigningSecret();
        if (empty($signingSecret)) {
            Log::warning('Resend webhook signing secret not configured — accepting without verification');
            return true;
        }

        $svixId = $request->header('svix-id', '');
        $svixTimestamp = $request->header('svix-timestamp', '');
        $svixSignature = $request->header('svix-signature', '');

        if (empty($svixId) || empty($svixTimestamp) || empty($svixSignature)) {
            return false;
        }

        // Reject webhooks with timestamps older than 5 minutes (replay protection)
        if (abs(time() - (int) $svixTimestamp) > 300) {
            return false;
        }

        $body = $request->getContent();
        $signedContent = "{$svixId}.{$svixTimestamp}.{$body}";

        // The secret may be prefixed with "whsec_" — strip it and base64-decode
        $secret = $signingSecret;
        if (str_starts_with($secret, 'whsec_')) {
            $secret = substr($secret, 6);
        }
        $secretBytes = base64_decode($secret);

        $computed = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));
        $expected = "v1,{$computed}";

        // Svix may send multiple signatures separated by spaces
        foreach (explode(' ', $svixSignature) as $sig) {
            if (hash_equals($expected, trim($sig))) {
                return true;
            }
        }

        return false;
    }

    public function parseInboundEmail(Request $request): ParsedEmail
    {
        $data = $request->json()->all();

        $from = $data['from'] ?? '';
        $parsedFrom = $this->parseEmailAddress($from);

        $toAddresses = array_map(
            fn ($addr) => $this->parseEmailAddress($addr),
            (array) ($data['to'] ?? [])
        );
        $ccAddresses = array_map(
            fn ($addr) => $this->parseEmailAddress($addr),
            (array) ($data['cc'] ?? [])
        );
        $bccAddresses = array_map(
            fn ($addr) => $this->parseEmailAddress($addr),
            (array) ($data['bcc'] ?? [])
        );

        return new ParsedEmail(
            fromAddress: $parsedFrom['address'],
            fromName: $parsedFrom['name'],
            to: $toAddresses,
            cc: $ccAddresses,
            bcc: $bccAddresses,
            subject: $data['subject'] ?? '',
            bodyText: $data['text'] ?? '',
            bodyHtml: $data['html'] ?? '',
            headers: $data['headers'] ?? [],
            attachments: $this->parseAttachments($data['attachments'] ?? []),
            messageId: $data['headers']['message-id'] ?? '',
            inReplyTo: $data['headers']['in-reply-to'] ?? null,
            references: $data['headers']['references'] ?? null,
            spamScore: null,
            providerMessageId: $data['headers']['message-id'] ?? null,
            providerEventId: $data['id'] ?? uniqid('resend_', true),
            recipientAddress: $toAddresses[0]['address'] ?? '',
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
        $config = $domain->getEffectiveConfig();
        $apiKey = $config['api_key'] ?? '';

        $fromAddress = $mailbox->display_name
            ? "{$mailbox->display_name} <{$mailbox->full_address}>"
            : $mailbox->full_address;

        $payload = [
            'from' => $fromAddress,
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
        ];

        if ($text) {
            $payload['text'] = $text;
        }
        if (! empty($cc)) {
            $payload['cc'] = $cc;
        }
        if (! empty($bcc)) {
            $payload['bcc'] = $bcc;
        }
        if (! empty($headers)) {
            $payload['headers'] = $headers;
        }

        try {
            $response = Http::withToken($apiKey)
                ->post(self::BASE_URL.'/emails', $payload);

            if ($response->successful()) {
                return SendResult::success($response->json('id', ''));
            }

            return SendResult::failure($response->body());
        } catch (\Exception $e) {
            Log::error('Resend send failed', ['error' => $e->getMessage()]);
            return SendResult::failure($e->getMessage());
        }
    }

    public function parseDeliveryEvent(Request $request): array
    {
        $data = $request->json()->all();
        $type = $data['type'] ?? '';

        $statusMap = [
            'email.sent'              => 'queued',
            'email.delivered'         => 'delivered',
            'email.delivery_delayed'  => 'failed',
            'email.bounced'           => 'bounced',
            'email.complained'        => 'complained',
            'email.opened'            => 'delivered',
            'email.clicked'           => 'delivered',
        ];

        $emailData = $data['data'] ?? [];

        return [
            'event_type'          => $statusMap[$type] ?? $type,
            'provider_message_id' => $emailData['email_id'] ?? null,
            'timestamp'           => $emailData['created_at'] ?? $data['created_at'] ?? null,
            'recipient'           => $emailData['to'][0] ?? null,
            'error_message'       => $emailData['bounce']['message'] ?? null,
        ];
    }

    public function addDomain(string $domain, array $config = []): DomainResult
    {
        $apiKey = $config['api_key'] ?? '';

        try {
            $response = Http::withToken($apiKey)
                ->post(self::BASE_URL.'/domains', ['name' => $domain]);

            if ($response->successful()) {
                $data = $response->json();
                $dnsRecords = $this->extractDnsRecords($data);
                return DomainResult::success($data['id'] ?? $domain, $dnsRecords);
            }

            return DomainResult::failure($response->body());
        } catch (\Exception $e) {
            Log::error('Resend add domain failed', ['error' => $e->getMessage()]);
            return DomainResult::failure($e->getMessage());
        }
    }

    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult
    {
        $apiKey = $config['api_key'] ?? '';

        try {
            // List domains and find matching one
            $response = Http::withToken($apiKey)
                ->get(self::BASE_URL.'/domains');

            if ($response->successful()) {
                $domains = $response->json('data', []);
                foreach ($domains as $d) {
                    if (strtolower($d['name'] ?? '') === strtolower($domain)) {
                        $isVerified = ($d['status'] ?? '') === 'verified';
                        $dnsRecords = $this->extractDnsRecords($d);
                        return new DomainVerificationResult($isVerified, $dnsRecords);
                    }
                }
                return new DomainVerificationResult(false, [], 'Domain not found in Resend');
            }

            return new DomainVerificationResult(false, [], $response->body());
        } catch (\Exception $e) {
            Log::error('Resend verify domain failed', ['error' => $e->getMessage()]);
            return new DomainVerificationResult(false, [], $e->getMessage());
        }
    }

    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool
    {
        // Resend uses webhooks at account level, not per-domain.
        // Auto-create a webhook for email.received events if not already configured.
        $apiKey = $config['api_key'] ?? '';

        try {
            $response = Http::withToken($apiKey)
                ->post(self::BASE_URL.'/webhooks', [
                    'endpoint' => $webhookUrl,
                    'events'   => ['email.received'],
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Resend configure webhook failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Management API
    // -------------------------------------------------------------------------

    public function managementRequest(string $method, string $path, array $payload = [], array $config = []): array
    {
        $apiKey = $config['api_key'] ?? '';

        try {
            $request = Http::withToken($apiKey);
            $url = self::BASE_URL."/{$path}";

            $response = match (strtolower($method)) {
                'post'   => $request->post($url, $payload),
                'put'    => $request->put($url, $payload),
                'patch'  => $request->patch($url, $payload),
                'delete' => $request->delete($url, $payload),
                default  => $request->get($url, $payload),
            };

            return [
                'status' => $response->status(),
                'body'   => $response->json() ?? [],
                'ok'     => $response->successful(),
            ];
        } catch (\Exception $e) {
            Log::error('Resend management request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return ['status' => 0, 'body' => ['message' => $e->getMessage()], 'ok' => false];
        }
    }

    public function managementRequestOrFail(string $method, string $path, array $payload = [], array $config = []): array
    {
        $result = $this->managementRequest($method, $path, $payload, $config);

        if (! $result['ok']) {
            $message = $result['body']['message'] ?? ('Resend API error: HTTP '.$result['status']);
            throw new ResendApiException($message, $result['status'], $result['body']);
        }

        return $result;
    }

    // -- Health --

    public function checkApiHealth(array $config = []): bool
    {
        $result = $this->managementRequest('get', 'domains', [], $config);
        return $result['ok'];
    }

    // -- Webhooks --

    public function listWebhooks(string $domain, array $config = []): array
    {
        $result = $this->managementRequestOrFail('get', 'webhooks', [], $config);
        return $result['body']['data'] ?? [];
    }

    public function createWebhook(string $domain, string $event, string $url, array $config = []): array
    {
        // Resend webhooks are account-level, not per-domain. Map our event names to Resend event types.
        $resendEvent = $this->mapEventName($event);

        $result = $this->managementRequestOrFail('post', 'webhooks', [
            'endpoint' => $url,
            'events'   => [$resendEvent],
        ], $config);

        return $result['body'];
    }

    public function updateWebhook(string $domain, string $webhookId, string $url, array $config = []): array
    {
        $result = $this->managementRequestOrFail('put', "webhooks/{$webhookId}", [
            'endpoint' => $url,
        ], $config);

        return $result['body'];
    }

    public function deleteWebhook(string $domain, string $webhookId, array $config = []): array
    {
        $result = $this->managementRequestOrFail('delete', "webhooks/{$webhookId}", [], $config);
        return $result['body'];
    }

    public function testWebhook(string $domain, string $eventType, string $url, array $config = []): array
    {
        // Resend doesn't have a test webhook endpoint — send a manual POST
        $urlValidator = app(\App\Services\UrlValidationService::class);
        $resolved = $urlValidator->validateAndResolve($url);
        if ($resolved === null) {
            return ['success' => false, 'status_code' => null, 'message' => 'Webhook URL must not resolve to a private or reserved IP address'];
        }

        try {
            $response = Http::timeout(10)
                ->withOptions($urlValidator->pinnedOptions($resolved))
                ->post($url, [
                    'type'       => "email.{$eventType}",
                    'created_at' => now()->toIso8601String(),
                    'data'       => [
                        'email_id' => 'test_'.uniqid(),
                        'to'       => ['test@example.com'],
                        'subject'  => 'Webhook Test',
                    ],
                ]);

            return [
                'success'     => $response->successful(),
                'status_code' => $response->status(),
                'message'     => $response->successful() ? 'Webhook test delivered successfully' : 'Webhook returned HTTP '.$response->status(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'status_code' => null, 'message' => 'Failed to reach webhook URL: '.$e->getMessage()];
        }
    }

    // -- Event Log --

    public function getEvents(string $domain, array $filters = [], array $config = []): array
    {
        // Resend's email list endpoint serves as event log
        $params = array_filter([
            'limit' => $filters['limit'] ?? 25,
        ]);

        $result = $this->managementRequestOrFail('get', 'emails', $params, $config);

        return [
            'items'    => $result['body']['data'] ?? [],
            'nextPage' => null, // Resend doesn't support cursor pagination on emails yet
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function mapEventName(string $event): string
    {
        return match ($event) {
            'delivered'      => 'email.delivered',
            'permanent_fail' => 'email.bounced',
            'complained'     => 'email.complained',
            'stored'         => 'email.received',
            'opened'         => 'email.opened',
            'clicked'        => 'email.clicked',
            default          => "email.{$event}",
        };
    }

    private function parseEmailAddress(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^(.+?)\s*<(.+?)>$/', $raw, $matches)) {
            return [
                'name'    => trim($matches[1], '"\''),
                'address' => trim($matches[2]),
            ];
        }

        return ['name' => null, 'address' => $raw];
    }

    private function parseAttachments(array $attachments): array
    {
        return array_map(fn ($att) => [
            'filename' => $att['filename'] ?? 'attachment',
            'mimeType' => $att['content_type'] ?? 'application/octet-stream',
            'size'     => strlen(base64_decode($att['content'] ?? '')),
            'content'  => base64_decode($att['content'] ?? ''),
        ], $attachments);
    }

    private function extractDnsRecords(array $data): array
    {
        $records = [];
        foreach ($data['records'] ?? [] as $record) {
            $records[] = [
                'type'    => $record['type'] ?? '',
                'name'    => $record['name'] ?? '',
                'value'   => $record['value'] ?? '',
                'valid'   => ($record['status'] ?? '') === 'verified' ? 'valid' : 'unknown',
                'purpose' => $record['record'] ?? 'sending',
            ];
        }

        return $records;
    }

    private function getSigningSecret(): string
    {
        return app(\App\Services\SettingService::class)->get('resend', 'webhook_signing_secret', '');
    }
}
