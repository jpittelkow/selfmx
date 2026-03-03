<?php

namespace App\Services\Email;

use App\Models\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MailgunProvider implements EmailProviderInterface
{
    public function getName(): string
    {
        return 'mailgun';
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $signingKey = $this->getSigningKey();
        if (empty($signingKey)) {
            Log::warning('Mailgun webhook signing key not configured');
            return false;
        }

        $timestamp = $request->input('signature.timestamp', $request->input('timestamp', ''));
        $token = $request->input('signature.token', $request->input('token', ''));
        $signature = $request->input('signature.signature', $request->input('signature', ''));

        if (empty($timestamp) || empty($token) || empty($signature)) {
            return false;
        }

        $computed = hash_hmac('sha256', $timestamp . $token, $signingKey);

        return hash_equals($computed, $signature);
    }

    public function parseInboundEmail(Request $request): ParsedEmail
    {
        // Mailgun sends inbound emails as multipart/form-data
        $from = $request->input('from', $request->input('sender', ''));
        $parsedFrom = $this->parseEmailAddress($from);

        return new ParsedEmail(
            fromAddress: $parsedFrom['address'],
            fromName: $parsedFrom['name'],
            to: $this->parseAddressList($request->input('To', $request->input('recipient', ''))),
            cc: $this->parseAddressList($request->input('Cc', '')),
            bcc: [],
            subject: $request->input('subject', $request->input('Subject', '')),
            bodyText: $request->input('body-plain', $request->input('body-plain', '')),
            bodyHtml: $request->input('body-html', $request->input('body-html', '')),
            headers: $this->parseHeaders($request->input('message-headers', '[]')),
            attachments: $this->parseAttachments($request),
            messageId: $request->input('Message-Id', $request->input('message-id', '')),
            inReplyTo: $request->input('In-Reply-To', $request->input('in-reply-to')),
            references: $request->input('References', $request->input('references')),
            spamScore: $this->parseSpamScore($request),
            providerMessageId: $request->input('Message-Id', $request->input('message-id', '')),
            providerEventId: $request->input('token', uniqid('mg_', true)),
            recipientAddress: $request->input('recipient', $this->extractFirstRecipient($request->input('To', ''))),
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
        $apiKey = $config['api_key'] ?? $this->getApiKey();
        $region = $config['region'] ?? $this->getRegion();

        $baseUrl = $region === 'eu'
            ? 'https://api.eu.mailgun.net/v3'
            : 'https://api.mailgun.net/v3';

        $fromAddress = $mailbox->display_name
            ? "{$mailbox->display_name} <{$mailbox->full_address}>"
            : $mailbox->full_address;

        $payload = [
            'from' => $fromAddress,
            'to' => implode(', ', $to),
            'subject' => $subject,
            'html' => $html,
        ];

        if ($text) {
            $payload['text'] = $text;
        }

        if (!empty($cc)) {
            $payload['cc'] = implode(', ', $cc);
        }

        if (!empty($bcc)) {
            $payload['bcc'] = implode(', ', $bcc);
        }

        // Custom headers for threading
        foreach ($headers as $name => $value) {
            if ($value) {
                $payload["h:{$name}"] = $value;
            }
        }

        try {
            $response = Http::withBasicAuth('api', $apiKey)
                ->asMultipart()
                ->post("{$baseUrl}/{$domain->name}/messages", $this->buildMultipartPayload($payload, $attachments));

            if ($response->successful()) {
                return SendResult::success($response->json('id', ''));
            }

            return SendResult::failure($response->body());
        } catch (\Exception $e) {
            Log::error('Mailgun send failed', ['error' => $e->getMessage()]);
            return SendResult::failure($e->getMessage());
        }
    }

    public function parseDeliveryEvent(Request $request): array
    {
        $eventData = $request->input('event-data', []);
        if (!is_array($eventData)) {
            $eventData = [];
        }

        $event = $eventData['event'] ?? '';
        $messageHeaders = $eventData['message']['headers'] ?? [];

        // Map Mailgun event names to our delivery statuses
        $statusMap = [
            'delivered' => 'delivered',
            'accepted' => 'queued',
            'permanent_fail' => 'bounced',
            'temporary_fail' => 'failed',
            'failed' => 'failed',
            'opened' => 'delivered',
            'clicked' => 'delivered',
            'complained' => 'delivered',
            'unsubscribed' => 'delivered',
        ];

        return [
            'event_type' => $statusMap[$event] ?? $event,
            'provider_message_id' => $messageHeaders['message-id'] ?? null,
            'timestamp' => $eventData['timestamp'] ?? null,
            'recipient' => $eventData['recipient'] ?? null,
            'error_message' => $eventData['delivery-status']['message'] ?? ($eventData['reason'] ?? null),
        ];
    }

    public function addDomain(string $domain, array $config = []): DomainResult
    {
        $apiKey = $config['api_key'] ?? $this->getApiKey();
        $region = $config['region'] ?? $this->getRegion();

        $baseUrl = $region === 'eu'
            ? 'https://api.eu.mailgun.net/v3'
            : 'https://api.mailgun.net/v3';

        try {
            $response = Http::withBasicAuth('api', $apiKey)
                ->post("{$baseUrl}/domains", [
                    'name' => $domain,
                ]);

            if ($response->successful() || $response->status() === 200) {
                $data = $response->json();
                $dnsRecords = $this->extractDnsRecords($data);
                return DomainResult::success($domain, $dnsRecords);
            }

            return DomainResult::failure($response->body());
        } catch (\Exception $e) {
            Log::error('Mailgun add domain failed', ['error' => $e->getMessage()]);
            return DomainResult::failure($e->getMessage());
        }
    }

    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult
    {
        $apiKey = $config['api_key'] ?? $this->getApiKey();
        $region = $config['region'] ?? $this->getRegion();

        $baseUrl = $region === 'eu'
            ? 'https://api.eu.mailgun.net/v3'
            : 'https://api.mailgun.net/v3';

        try {
            // Trigger verification check
            Http::withBasicAuth('api', $apiKey)
                ->put("{$baseUrl}/domains/{$domain}/verify");

            // Get current status
            $response = Http::withBasicAuth('api', $apiKey)
                ->get("{$baseUrl}/domains/{$domain}");

            if ($response->successful()) {
                $data = $response->json();
                $domainInfo = $data['domain'] ?? [];
                $isVerified = ($domainInfo['state'] ?? '') === 'active';
                $dnsRecords = $this->extractDnsRecords($data);

                return new DomainVerificationResult($isVerified, $dnsRecords);
            }

            return new DomainVerificationResult(false, [], $response->body());
        } catch (\Exception $e) {
            Log::error('Mailgun verify domain failed', ['error' => $e->getMessage()]);
            return new DomainVerificationResult(false, [], $e->getMessage());
        }
    }

    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool
    {
        $apiKey = $config['api_key'] ?? $this->getApiKey();
        $region = $config['region'] ?? $this->getRegion();

        $baseUrl = $region === 'eu'
            ? 'https://api.eu.mailgun.net/v3'
            : 'https://api.mailgun.net/v3';

        try {
            // Create a route to forward inbound emails to our webhook
            $response = Http::withBasicAuth('api', $apiKey)
                ->post("{$baseUrl}/routes", [
                    'priority' => 0,
                    'description' => "Forward inbound mail for {$domain}",
                    'expression' => "match_recipient('.*@{$domain}')",
                    'action' => [
                        "forward('{$webhookUrl}')",
                        'stop()',
                    ],
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Mailgun configure webhook failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Management API methods (Phase 7)
    // -------------------------------------------------------------------------

    /**
     * Perform an authenticated request against the Mailgun management API.
     *
     * @param  string  $method   HTTP verb (get, post, put, delete)
     * @param  string  $path     Path relative to the versioned base, e.g. "v4/domains"
     * @param  array   $payload  Body data (for POST/PUT)
     * @param  array   $config   Per-domain config overrides (api_key, region)
     * @return array{status: int, body: array, ok: bool}
     */
    public function managementRequest(string $method, string $path, array $payload = [], array $config = []): array
    {
        $apiKey = $config['api_key'] ?? $this->getApiKey();
        $region = $config['region'] ?? $this->getRegion();
        $host   = $region === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';

        try {
            $request = Http::withBasicAuth('api', $apiKey);
            $url = "{$host}/{$path}";

            $response = match (strtolower($method)) {
                'post'   => $request->post($url, $payload),
                'put'    => $request->put($url, $payload),
                'delete' => $request->delete($url, $payload),
                default  => $request->get($url, $payload),
            };

            return [
                'status' => $response->status(),
                'body'   => $response->json() ?? [],
                'ok'     => $response->successful(),
            ];
        } catch (\Exception $e) {
            Log::error('Mailgun management request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return ['status' => 0, 'body' => ['message' => $e->getMessage()], 'ok' => false];
        }
    }

    // -- Health --

    public function checkApiHealth(array $config = []): bool
    {
        $result = $this->managementRequest('get', 'v3/domains', ['limit' => 1], $config);
        return $result['ok'];
    }

    // -- DKIM --

    public function getDkimKey(string $domain, array $config = []): array
    {
        $result = $this->managementRequest('get', "v4/domains/{$domain}/dkim", [], $config);
        return $result['body'];
    }

    public function rotateDkimKey(string $domain, array $config = []): array
    {
        $result = $this->managementRequest('post', "v4/domains/{$domain}/dkim", [], $config);
        return $result['body'];
    }

    // -- Webhooks --

    public function listWebhooks(string $domain, array $config = []): array
    {
        $result = $this->managementRequest('get', "v3/domains/{$domain}/webhooks", [], $config);
        return $result['body']['webhooks'] ?? [];
    }

    public function createWebhook(string $domain, string $event, string $url, array $config = []): array
    {
        $result = $this->managementRequest('post', "v3/domains/{$domain}/webhooks", [
            'id'  => $event,
            'url' => [$url],
        ], $config);
        return $result['body'];
    }

    public function updateWebhook(string $domain, string $webhookId, string $url, array $config = []): array
    {
        $result = $this->managementRequest('put', "v3/domains/{$domain}/webhooks/{$webhookId}", [
            'url' => [$url],
        ], $config);
        return $result['body'];
    }

    public function deleteWebhook(string $domain, string $webhookId, array $config = []): array
    {
        $result = $this->managementRequest('delete', "v3/domains/{$domain}/webhooks/{$webhookId}", [], $config);
        return $result['body'];
    }

    // -- Inbound Routes --

    /**
     * List Mailgun routes that match the given domain in their expression.
     * Mailgun's routes API returns all routes globally; we filter by domain.
     */
    public function listRoutes(string $domain, array $config = []): array
    {
        $result = $this->managementRequest('get', 'v3/routes', ['limit' => 100], $config);
        $routes = $result['body']['items'] ?? [];

        return array_values(array_filter($routes, function ($route) use ($domain) {
            return str_contains($route['expression'] ?? '', $domain);
        }));
    }

    public function createRoute(string $expression, array $actions, string $description, int $priority = 0, array $config = []): array
    {
        $result = $this->managementRequest('post', 'v3/routes', [
            'priority'    => $priority,
            'description' => $description,
            'expression'  => $expression,
            'action'      => $actions,
        ], $config);
        return $result['body'];
    }

    public function updateRoute(string $routeId, array $data, array $config = []): array
    {
        $result = $this->managementRequest('put', "v3/routes/{$routeId}", $data, $config);
        return $result['body'];
    }

    public function deleteRoute(string $routeId, array $config = []): array
    {
        $result = $this->managementRequest('delete', "v3/routes/{$routeId}", [], $config);
        return $result['body'];
    }

    // -- Event Log --

    /**
     * Query the Mailgun Events API for a domain.
     *
     * @param  array  $filters  Supported: event, recipient, begin, end, subject, message-id, limit (max 300), page
     */
    public function getEvents(string $domain, array $filters = [], array $config = []): array
    {
        $params = array_filter(array_merge(['limit' => 25], $filters));
        $result = $this->managementRequest('get', "v3/{$domain}/events", $params, $config);
        return [
            'items'    => $result['body']['items'] ?? [],
            'nextPage' => $result['body']['paging']['next'] ?? null,
        ];
    }

    // -- Suppressions --

    public function listBounces(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $params = array_filter(['limit' => $limit, 'p' => $page]);
        $result = $this->managementRequest('get', "v3/{$domain}/bounces", $params, $config);
        return [
            'items'    => $result['body']['items'] ?? [],
            'nextPage' => $result['body']['paging']['next'] ?? null,
        ];
    }

    public function deleteBounce(string $domain, string $address, array $config = []): bool
    {
        $result = $this->managementRequest('delete', "v3/{$domain}/bounces/{$address}", [], $config);
        return $result['ok'];
    }

    public function listComplaints(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $params = array_filter(['limit' => $limit, 'p' => $page]);
        $result = $this->managementRequest('get', "v3/{$domain}/complaints", $params, $config);
        return [
            'items'    => $result['body']['items'] ?? [],
            'nextPage' => $result['body']['paging']['next'] ?? null,
        ];
    }

    public function deleteComplaint(string $domain, string $address, array $config = []): bool
    {
        $result = $this->managementRequest('delete', "v3/{$domain}/complaints/{$address}", [], $config);
        return $result['ok'];
    }

    public function listUnsubscribes(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $params = array_filter(['limit' => $limit, 'p' => $page]);
        $result = $this->managementRequest('get', "v3/{$domain}/unsubscribes", $params, $config);
        return [
            'items'    => $result['body']['items'] ?? [],
            'nextPage' => $result['body']['paging']['next'] ?? null,
        ];
    }

    public function deleteUnsubscribe(string $domain, string $address, ?string $tag = null, array $config = []): bool
    {
        $params = $tag ? ['tag' => $tag] : [];
        $result = $this->managementRequest('delete', "v3/{$domain}/unsubscribes/{$address}", $params, $config);
        return $result['ok'];
    }

    /**
     * Check whether an address is in bounces or complaints for the domain.
     * Returns ['suppressed' => bool, 'reason' => 'bounce'|'complaint'|null, 'detail' => string|null]
     */
    public function checkSuppression(string $domain, string $address, array $config = []): array
    {
        $bounce = $this->managementRequest('get', "v3/{$domain}/bounces/{$address}", [], $config);
        if ($bounce['ok']) {
            return [
                'suppressed' => true,
                'reason'     => 'bounce',
                'detail'     => $bounce['body']['error'] ?? null,
            ];
        }

        $complaint = $this->managementRequest('get', "v3/{$domain}/complaints/{$address}", [], $config);
        if ($complaint['ok']) {
            return [
                'suppressed' => true,
                'reason'     => 'complaint',
                'detail'     => null,
            ];
        }

        return ['suppressed' => false, 'reason' => null, 'detail' => null];
    }

    // -- Tracking --

    public function getTrackingSettings(string $domain, array $config = []): array
    {
        $result = $this->managementRequest('get', "v3/domains/{$domain}/tracking", [], $config);
        return $result['body']['tracking'] ?? [];
    }

    /**
     * @param  string  $type  'click'|'open'|'unsubscribe'
     */
    public function updateTrackingSetting(string $domain, string $type, bool $active, array $config = []): array
    {
        $result = $this->managementRequest('put', "v3/domains/{$domain}/tracking/{$type}", [
            'active' => $active ? 'yes' : 'no',
        ], $config);
        return $result['body'];
    }

    // -- Stats --

    /**
     * Get aggregate stats for a domain over a given duration.
     *
     * @param  array   $events    e.g. ['accepted', 'delivered', 'failed', 'bounced', 'complained']
     * @param  string  $duration  e.g. '30d', '7d', '1d'
     * @param  string  $resolution 'hour'|'day'|'month'
     */
    public function getDomainStats(string $domain, array $events, string $duration = '30d', string $resolution = 'day', array $config = []): array
    {
        $result = $this->managementRequest('get', "v3/{$domain}/stats/total", [
            'event'      => $events,
            'duration'   => $duration,
            'resolution' => $resolution,
        ], $config);
        return [
            'stats' => $result['body']['stats'] ?? [],
            'start' => $result['body']['start'] ?? null,
            'end'   => $result['body']['end'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Parse a single email address string like "John Doe <john@example.com>" or "john@example.com".
     */
    private function parseEmailAddress(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^(.+?)\s*<(.+?)>$/', $raw, $matches)) {
            return [
                'name' => trim($matches[1], '"\''),
                'address' => trim($matches[2]),
            ];
        }

        return [
            'name' => null,
            'address' => $raw,
        ];
    }

    /**
     * Parse a comma-separated list of email addresses.
     */
    private function parseAddressList(string $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        $addresses = [];
        // Split on commas, but respect quoted strings and angle brackets
        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $raw);

        foreach ($parts as $part) {
            $parsed = $this->parseEmailAddress(trim($part));
            if (!empty($parsed['address'])) {
                $addresses[] = $parsed;
            }
        }

        return $addresses;
    }

    /**
     * Parse Mailgun message headers JSON.
     */
    private function parseHeaders(string $headersJson): array
    {
        $headers = json_decode($headersJson, true);
        if (!is_array($headers)) {
            return [];
        }

        $result = [];
        foreach ($headers as $header) {
            if (is_array($header) && count($header) >= 2) {
                $result[$header[0]] = $header[1];
            }
        }

        return $result;
    }

    /**
     * Parse attachments from Mailgun webhook request.
     */
    private function parseAttachments(Request $request): array
    {
        $attachments = [];
        $count = (int) $request->input('attachment-count', 0);

        for ($i = 1; $i <= $count; $i++) {
            $file = $request->file("attachment-{$i}");
            if ($file) {
                $attachments[] = [
                    'filename' => $file->getClientOriginalName(),
                    'mimeType' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'content' => $file,
                ];
            }
        }

        return $attachments;
    }

    /**
     * Parse spam score from Mailgun headers.
     */
    private function parseSpamScore(Request $request): ?float
    {
        $score = $request->input('X-Mailgun-SScore');
        if ($score !== null) {
            return (float) $score;
        }

        // Also check in stripped headers
        $spamFlag = $request->input('X-Mailgun-Sflag');
        if ($spamFlag === 'Yes') {
            return 10.0; // High score to indicate spam
        }

        return null;
    }

    /**
     * Extract the first recipient from a To header.
     */
    private function extractFirstRecipient(string $to): string
    {
        $recipients = $this->parseAddressList($to);
        return $recipients[0]['address'] ?? '';
    }

    /**
     * Extract DNS records from Mailgun domain response.
     */
    private function extractDnsRecords(array $data): array
    {
        $records = [];

        foreach (['sending_dns_records', 'receiving_dns_records'] as $key) {
            foreach ($data[$key] ?? [] as $record) {
                $records[] = [
                    'type' => $record['record_type'] ?? $record['type'] ?? '',
                    'name' => $record['name'] ?? '',
                    'value' => $record['value'] ?? '',
                    'valid' => $record['valid'] ?? 'unknown',
                    'purpose' => str_contains($key, 'sending') ? 'sending' : 'receiving',
                ];
            }
        }

        return $records;
    }

    /**
     * Build multipart payload for sending with attachments.
     */
    private function buildMultipartPayload(array $fields, array $attachments = []): array
    {
        $multipart = [];

        foreach ($fields as $name => $value) {
            $multipart[] = [
                'name' => $name,
                'contents' => $value,
            ];
        }

        foreach ($attachments as $attachment) {
            $multipart[] = [
                'name' => 'attachment',
                'contents' => fopen($attachment['path'], 'r'),
                'filename' => $attachment['filename'] ?? basename($attachment['path']),
            ];
        }

        return $multipart;
    }

    private function getApiKey(): string
    {
        return app(\App\Services\SettingService::class)->get('mailgun', 'api_key', '');
    }

    private function getSigningKey(): string
    {
        return app(\App\Services\SettingService::class)->get('mailgun', 'webhook_signing_key', '');
    }

    private function getRegion(): string
    {
        return app(\App\Services\SettingService::class)->get('mailgun', 'region', 'us');
    }
}
