<?php

namespace App\Services\Email;

use App\Exceptions\Smtp2GoApiException;
use App\Models\Mailbox;
use App\Services\Email\Concerns\HasEventLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Smtp2GoProvider implements
    EmailProviderInterface,
    ProviderManagementInterface,
    HasEventLog
{
    private const BASE_URL = 'https://api.smtp2go.com/v3';

    public function getName(): string
    {
        return 'smtp2go';
    }

    public function getCapabilities(): array
    {
        return [
            'dkim_rotation'     => false,
            'webhooks'          => false,
            'inbound_routes'    => false,
            'events'            => true,
            'suppressions'      => false,
            'stats'             => false,
            'domain_management' => false,
            'dns_records'       => false,
        ];
    }

    // -------------------------------------------------------------------------
    // EmailProviderInterface
    // -------------------------------------------------------------------------

    public function verifyWebhookSignature(Request $request): bool
    {
        // SMTP2GO does not sign webhook payloads.
        // Security relies on the webhook URL being secret.
        Log::debug('SMTP2GO webhook received — no signature verification available');
        return true;
    }

    public function parseInboundEmail(Request $request): ParsedEmail
    {
        // SMTP2GO forwards inbound emails as multipart POST (similar to Mailgun)
        $from = $request->input('from', $request->input('sender', ''));
        $parsedFrom = $this->parseEmailAddress($from);

        return new ParsedEmail(
            fromAddress: $parsedFrom['address'],
            fromName: $parsedFrom['name'],
            to: $this->parseAddressList($request->input('to', '')),
            cc: $this->parseAddressList($request->input('cc', '')),
            bcc: [],
            subject: $request->input('subject', ''),
            bodyText: $request->input('text', $request->input('body-plain', '')),
            bodyHtml: $request->input('html', $request->input('body-html', '')),
            headers: $this->parseHeaders($request->input('headers', '{}')),
            attachments: [],
            messageId: $request->input('message-id', $request->input('Message-ID', '')),
            inReplyTo: $request->input('in-reply-to', null),
            references: $request->input('references', null),
            spamScore: null,
            providerMessageId: $request->input('message-id', null),
            providerEventId: uniqid('s2g_', true),
            recipientAddress: $request->input('recipient', $this->extractFirstRecipient($request->input('to', ''))),
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
            'api_key' => $apiKey,
            'sender'  => $fromAddress,
            'to'      => $to,
            'subject' => $subject,
            'html_body' => $html,
        ];

        if ($text) {
            $payload['text_body'] = $text;
        }
        if (! empty($cc)) {
            $payload['cc'] = $cc;
        }
        if (! empty($bcc)) {
            $payload['bcc'] = $bcc;
        }
        if (! empty($headers)) {
            $payload['custom_headers'] = array_map(
                fn ($name, $value) => ['header' => $name, 'value' => $value],
                array_keys($headers),
                array_values($headers)
            );
        }

        try {
            $response = Http::post(self::BASE_URL.'/email/send', $payload);

            if ($response->successful()) {
                $data = $response->json('data', []);
                return SendResult::success($data['email_id'] ?? '');
            }

            return SendResult::failure($response->body());
        } catch (\Exception $e) {
            Log::error('SMTP2GO send failed', ['error' => $e->getMessage()]);
            return SendResult::failure($e->getMessage());
        }
    }

    public function parseDeliveryEvent(Request $request): array
    {
        $data = $request->json()->all();
        $event = $data['event'] ?? '';

        $statusMap = [
            'delivered'  => 'delivered',
            'bounced'    => 'bounced',
            'complained' => 'complained',
            'deferred'   => 'failed',
            'rejected'   => 'failed',
        ];

        return [
            'event_type'          => $statusMap[$event] ?? $event,
            'provider_message_id' => $data['email_id'] ?? null,
            'timestamp'           => $data['timestamp'] ?? null,
            'recipient'           => $data['recipient'] ?? null,
            'error_message'       => $data['reason'] ?? null,
        ];
    }

    public function addDomain(string $domain, array $config = []): DomainResult
    {
        $apiKey = $config['api_key'] ?? '';

        try {
            $response = Http::post(self::BASE_URL.'/domain/add', [
                'api_key' => $apiKey,
                'domain'  => $domain,
            ]);

            if ($response->successful()) {
                $data = $response->json('data', []);
                $dnsRecords = [];

                foreach ($data['dns_records'] ?? [] as $record) {
                    $dnsRecords[] = [
                        'type'  => $record['type'] ?? '',
                        'name'  => $record['host'] ?? $record['name'] ?? '',
                        'value' => $record['data'] ?? $record['value'] ?? '',
                    ];
                }

                return DomainResult::success($domain, $dnsRecords);
            }

            return DomainResult::failure($response->body());
        } catch (\Exception $e) {
            Log::error('SMTP2GO add domain failed', ['error' => $e->getMessage()]);
            return DomainResult::failure($e->getMessage());
        }
    }

    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult
    {
        $apiKey = $config['api_key'] ?? '';

        try {
            $response = Http::post(self::BASE_URL.'/domain/verify', [
                'api_key' => $apiKey,
                'domain'  => $domain,
            ]);

            if ($response->successful()) {
                $data = $response->json('data', []);
                $isVerified = ($data['verified'] ?? false) === true;
                return new DomainVerificationResult($isVerified);
            }

            return new DomainVerificationResult(false, [], $response->body());
        } catch (\Exception $e) {
            Log::error('SMTP2GO verify domain failed', ['error' => $e->getMessage()]);
            return new DomainVerificationResult(false, [], $e->getMessage());
        }
    }

    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool
    {
        // SMTP2GO webhook configuration is done through the dashboard, not API
        Log::info('SMTP2GO webhook configuration must be done via the SMTP2GO dashboard', [
            'domain'      => $domain,
            'webhook_url' => $webhookUrl,
        ]);
        return false;
    }

    // -------------------------------------------------------------------------
    // Management API
    // -------------------------------------------------------------------------

    public function managementRequest(string $method, string $path, array $payload = [], array $config = []): array
    {
        $apiKey = $config['api_key'] ?? '';
        $payload = array_merge(['api_key' => $apiKey], $payload);

        try {
            $url = self::BASE_URL."/{$path}";

            // SMTP2GO API uses POST for all endpoints with api_key in body
            $response = Http::post($url, $payload);

            return [
                'status' => $response->status(),
                'body'   => $response->json() ?? [],
                'ok'     => $response->successful(),
            ];
        } catch (\Exception $e) {
            Log::error('SMTP2GO management request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return ['status' => 0, 'body' => ['message' => $e->getMessage()], 'ok' => false];
        }
    }

    public function managementRequestOrFail(string $method, string $path, array $payload = [], array $config = []): array
    {
        $result = $this->managementRequest($method, $path, $payload, $config);

        if (! $result['ok']) {
            $message = $result['body']['data']['error'] ?? $result['body']['message'] ?? ('SMTP2GO API error: HTTP '.$result['status']);
            throw new Smtp2GoApiException($message, $result['status'], $result['body']);
        }

        return $result;
    }

    // -- Health --

    public function checkApiHealth(array $config = []): bool
    {
        $result = $this->managementRequest('post', 'stats/email_summary', ['date_from' => now()->subDay()->toDateString()], $config);
        return $result['ok'];
    }

    // -- Event Log --

    public function getEvents(string $domain, array $filters = [], array $config = []): array
    {
        $params = array_filter([
            'limit'    => $filters['limit'] ?? 25,
            'offset'   => $filters['page'] ?? 0,
            'sender'   => "*@{$domain}",
        ]);

        if (! empty($filters['recipient'])) {
            $params['recipients'] = $filters['recipient'];
        }

        $result = $this->managementRequestOrFail('post', 'email/search', $params, $config);

        return [
            'items'    => $result['body']['data']['emails'] ?? [],
            'nextPage' => null, // SMTP2GO uses offset-based pagination
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function parseAddressList(string $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        $addresses = [];
        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $raw);

        foreach ($parts as $part) {
            $parsed = $this->parseEmailAddress(trim($part));
            if (! empty($parsed['address'])) {
                $addresses[] = $parsed;
            }
        }

        return $addresses;
    }

    private function parseHeaders(string $headersJson): array
    {
        $headers = json_decode($headersJson, true);
        return is_array($headers) ? $headers : [];
    }

    private function extractFirstRecipient(string $to): string
    {
        $recipients = $this->parseAddressList($to);
        return $recipients[0]['address'] ?? '';
    }
}
