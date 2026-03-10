<?php

namespace App\Services\Email;

use App\Exceptions\MailerSendApiException;
use App\Models\Mailbox;
use App\Services\Email\Concerns\HasEventLog;
use App\Services\Email\Concerns\HasInboundRoutes;
use App\Services\Email\Concerns\HasSuppressionManagement;
use App\Services\Email\Concerns\HasWebhookManagement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MailerSendProvider implements
    EmailProviderInterface,
    ProviderManagementInterface,
    HasWebhookManagement,
    HasEventLog,
    HasSuppressionManagement,
    HasInboundRoutes
{
    private const BASE_URL = 'https://api.mailersend.com/v1';

    public function getName(): string
    {
        return 'mailersend';
    }

    public function getCapabilities(): array
    {
        return [
            'dkim_rotation'     => false,
            'webhooks'          => true,
            'inbound_routes'    => true,
            'events'            => true,
            'suppressions'      => true,
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
        $signingSecret = $this->getSigningSecret();
        if (empty($signingSecret)) {
            Log::warning('MailerSend webhook signing secret not configured — accepting without verification');
            return true;
        }

        $signature = $request->header('Signature', '');
        if (empty($signature)) {
            return false;
        }

        $body = $request->getContent();
        $computed = hash_hmac('sha256', $body, $signingSecret);

        return hash_equals($computed, $signature);
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

        $headers = [];
        foreach ($data['headers'] ?? [] as $header) {
            $headers[$header['name'] ?? ''] = $header['value'] ?? '';
        }

        return new ParsedEmail(
            fromAddress: $parsedFrom['address'],
            fromName: $parsedFrom['name'],
            to: $toAddresses,
            cc: $ccAddresses,
            bcc: [],
            subject: $data['subject'] ?? '',
            bodyText: $data['text'] ?? '',
            bodyHtml: $data['html'] ?? '',
            headers: $headers,
            attachments: $this->parseAttachments($data['attachments'] ?? []),
            messageId: $headers['Message-ID'] ?? $headers['message-id'] ?? '',
            inReplyTo: $headers['In-Reply-To'] ?? $headers['in-reply-to'] ?? null,
            references: $headers['References'] ?? $headers['references'] ?? null,
            spamScore: null,
            providerMessageId: $headers['Message-ID'] ?? null,
            providerEventId: $data['id'] ?? uniqid('ms_', true),
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

        $fromAddress = $mailbox->full_address;
        $fromName = $mailbox->display_name;

        $payload = [
            'from' => array_filter([
                'email' => $fromAddress,
                'name'  => $fromName,
            ]),
            'to' => array_map(fn ($addr) => [
                'email' => is_array($addr) ? $addr['address'] : $addr,
            ], $to),
            'subject' => $subject,
            'html'    => $html,
        ];

        if ($text) {
            $payload['text'] = $text;
        }
        if (! empty($cc)) {
            $payload['cc'] = array_map(fn ($addr) => [
                'email' => is_array($addr) ? $addr['address'] : $addr,
            ], $cc);
        }
        if (! empty($bcc)) {
            $payload['bcc'] = array_map(fn ($addr) => [
                'email' => is_array($addr) ? $addr['address'] : $addr,
            ], $bcc);
        }

        try {
            $response = Http::withToken($apiKey)
                ->post(self::BASE_URL.'/email', $payload);

            if ($response->successful()) {
                return SendResult::success($response->json('x_message_id', $response->header('x-message-id', '')));
            }

            return SendResult::failure($response->body());
        } catch (\Exception $e) {
            Log::error('MailerSend send failed', ['error' => $e->getMessage()]);
            return SendResult::failure($e->getMessage());
        }
    }

    public function parseDeliveryEvent(Request $request): array
    {
        $data = $request->json()->all();
        $type = $data['type'] ?? '';

        $statusMap = [
            'activity.sent'         => 'queued',
            'activity.delivered'    => 'delivered',
            'activity.soft_bounced' => 'failed',
            'activity.hard_bounced' => 'bounced',
            'activity.spam_complaint' => 'complained',
            'activity.opened'       => 'delivered',
            'activity.clicked'      => 'delivered',
        ];

        $emailData = $data['data'] ?? [];

        return [
            'event_type'          => $statusMap[$type] ?? $type,
            'provider_message_id' => $emailData['message_id'] ?? null,
            'timestamp'           => $emailData['timestamp'] ?? $data['created_at'] ?? null,
            'recipient'           => $emailData['email']['recipient']['email'] ?? null,
            'error_message'       => $emailData['morph']['reason'] ?? null,
        ];
    }

    public function addDomain(string $domain, array $config = []): DomainResult
    {
        $apiKey = $config['api_key'] ?? '';

        try {
            $response = Http::withToken($apiKey)
                ->post(self::BASE_URL.'/domains', ['name' => $domain]);

            if ($response->successful()) {
                $data = $response->json('data', []);
                $dnsRecords = $this->fetchDnsRecords($data['id'] ?? '', $config);
                return DomainResult::success($data['id'] ?? $domain, $dnsRecords);
            }

            return DomainResult::failure($response->body());
        } catch (\Exception $e) {
            Log::error('MailerSend add domain failed', ['error' => $e->getMessage()]);
            return DomainResult::failure($e->getMessage());
        }
    }

    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult
    {
        $apiKey = $config['api_key'] ?? '';

        try {
            $response = Http::withToken($apiKey)
                ->get(self::BASE_URL.'/domains');

            if ($response->successful()) {
                $domains = $response->json('data', []);
                foreach ($domains as $d) {
                    if (strtolower($d['name'] ?? '') === strtolower($domain)) {
                        $isVerified = ($d['is_verified'] ?? false) === true;
                        $dnsRecords = $this->fetchDnsRecords($d['id'] ?? '', $config);
                        return new DomainVerificationResult($isVerified, $dnsRecords);
                    }
                }
                return new DomainVerificationResult(false, [], 'Domain not found in MailerSend');
            }

            return new DomainVerificationResult(false, [], $response->body());
        } catch (\Exception $e) {
            Log::error('MailerSend verify domain failed', ['error' => $e->getMessage()]);
            return new DomainVerificationResult(false, [], $e->getMessage());
        }
    }

    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool
    {
        $apiKey = $config['api_key'] ?? '';

        try {
            // Find domain ID first
            $domainId = $this->findDomainId($domain, $config);
            if (! $domainId) {
                return false;
            }

            $response = Http::withToken($apiKey)
                ->post(self::BASE_URL.'/inbound', [
                    'domain_id'  => $domainId,
                    'name'       => "Inbound for {$domain}",
                    'domain_enabled' => true,
                    'match_filter' => ['type' => 'match_all'],
                    'forwards'   => [
                        ['type' => 'webhook', 'value' => $webhookUrl],
                    ],
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('MailerSend configure webhook failed', ['error' => $e->getMessage()]);
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
                'delete' => $request->delete($url, $payload),
                default  => $request->get($url, $payload),
            };

            return [
                'status' => $response->status(),
                'body'   => $response->json() ?? [],
                'ok'     => $response->successful(),
            ];
        } catch (\Exception $e) {
            Log::error('MailerSend management request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return ['status' => 0, 'body' => ['message' => $e->getMessage()], 'ok' => false];
        }
    }

    public function managementRequestOrFail(string $method, string $path, array $payload = [], array $config = []): array
    {
        $result = $this->managementRequest($method, $path, $payload, $config);

        if (! $result['ok']) {
            $message = $result['body']['message'] ?? ('MailerSend API error: HTTP '.$result['status']);
            throw new MailerSendApiException($message, $result['status'], $result['body']);
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
        $domainId = $this->findDomainId($domain, $config);
        if (! $domainId) {
            return [];
        }

        $result = $this->managementRequestOrFail('get', "webhooks?domain_id={$domainId}", [], $config);
        return $result['body']['data'] ?? [];
    }

    public function createWebhook(string $domain, string $event, string $url, array $config = []): array
    {
        $domainId = $this->findDomainId($domain, $config);
        if (! $domainId) {
            throw new MailerSendApiException("Domain '{$domain}' not found in MailerSend", 404, []);
        }

        $msEvent = $this->mapEventName($event);

        $result = $this->managementRequestOrFail('post', 'webhooks', [
            'url'       => $url,
            'name'      => "selfmx {$event}",
            'events'    => [$msEvent],
            'domain_id' => $domainId,
            'enabled'   => true,
        ], $config);

        return $result['body'];
    }

    public function updateWebhook(string $domain, string $webhookId, string $url, array $config = []): array
    {
        $result = $this->managementRequestOrFail('put', "webhooks/{$webhookId}", [
            'url'     => $url,
            'enabled' => true,
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
        $urlValidator = app(\App\Services\UrlValidationService::class);
        $resolved = $urlValidator->validateAndResolve($url);
        if ($resolved === null) {
            return ['success' => false, 'status_code' => null, 'message' => 'Webhook URL must not resolve to a private or reserved IP address'];
        }

        try {
            $response = Http::timeout(10)
                ->withOptions($urlValidator->pinnedOptions($resolved))
                ->post($url, [
                    'type' => "activity.{$eventType}",
                    'created_at' => now()->toIso8601String(),
                    'data' => [
                        'message_id' => 'test_'.uniqid(),
                        'email' => ['recipient' => ['email' => 'test@example.com']],
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
        $domainId = $this->findDomainId($domain, $config);
        if (! $domainId) {
            return ['items' => [], 'nextPage' => null];
        }

        $params = array_filter([
            'limit' => $filters['limit'] ?? 25,
            'page'  => $filters['page'] ?? null,
            'event' => $filters['event'] ?? null,
        ]);

        $result = $this->managementRequestOrFail('get', "activity/{$domainId}", $params, $config);

        return [
            'items'    => $result['body']['data'] ?? [],
            'nextPage' => $result['body']['links']['next'] ?? null,
        ];
    }

    // -- Suppressions --

    public function listBounces(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $domainId = $this->findDomainId($domain, $config);
        $params = array_filter(['limit' => $limit, 'page' => $page, 'domain_id' => $domainId]);
        $result = $this->managementRequestOrFail('get', 'suppressions/hard-bounces', $params, $config);
        return [
            'items'    => $result['body']['data'] ?? [],
            'nextPage' => $result['body']['links']['next'] ?? null,
        ];
    }

    public function listComplaints(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $domainId = $this->findDomainId($domain, $config);
        $params = array_filter(['limit' => $limit, 'page' => $page, 'domain_id' => $domainId]);
        $result = $this->managementRequestOrFail('get', 'suppressions/spam-complaints', $params, $config);
        return [
            'items'    => $result['body']['data'] ?? [],
            'nextPage' => $result['body']['links']['next'] ?? null,
        ];
    }

    public function listUnsubscribes(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $domainId = $this->findDomainId($domain, $config);
        $params = array_filter(['limit' => $limit, 'page' => $page, 'domain_id' => $domainId]);
        $result = $this->managementRequestOrFail('get', 'suppressions/unsubscribes', $params, $config);
        return [
            'items'    => $result['body']['data'] ?? [],
            'nextPage' => $result['body']['links']['next'] ?? null,
        ];
    }

    public function deleteBounce(string $domain, string $address, array $config = []): bool
    {
        $result = $this->managementRequest('delete', 'suppressions/hard-bounces', ['ids' => [$address]], $config);
        return $result['ok'];
    }

    public function deleteComplaint(string $domain, string $address, array $config = []): bool
    {
        $result = $this->managementRequest('delete', 'suppressions/spam-complaints', ['ids' => [$address]], $config);
        return $result['ok'];
    }

    public function deleteUnsubscribe(string $domain, string $address, ?string $tag = null, array $config = []): bool
    {
        $result = $this->managementRequest('delete', 'suppressions/unsubscribes', ['ids' => [$address]], $config);
        return $result['ok'];
    }

    public function checkSuppression(string $domain, string $address, array $config = []): array
    {
        // Check hard bounces
        $bounceResult = $this->managementRequest('get', 'suppressions/hard-bounces', ['recipient' => $address], $config);
        if ($bounceResult['ok'] && ! empty($bounceResult['body']['data'] ?? [])) {
            return ['suppressed' => true, 'reason' => 'bounce', 'detail' => null];
        }

        // Check spam complaints
        $complaintResult = $this->managementRequest('get', 'suppressions/spam-complaints', ['recipient' => $address], $config);
        if ($complaintResult['ok'] && ! empty($complaintResult['body']['data'] ?? [])) {
            return ['suppressed' => true, 'reason' => 'complaint', 'detail' => null];
        }

        return ['suppressed' => false, 'reason' => null, 'detail' => null];
    }

    public function importBounces(string $domain, array $entries, array $config = []): array
    {
        $domainId = $this->findDomainId($domain, $config);
        $recipients = array_map(fn ($e) => ['recipient' => $e['address'], 'domain_id' => $domainId], $entries);
        $result = $this->managementRequestOrFail('post', 'suppressions/hard-bounces', ['recipients' => $recipients], $config);
        return $result['body'];
    }

    public function importComplaints(string $domain, array $entries, array $config = []): array
    {
        $domainId = $this->findDomainId($domain, $config);
        $recipients = array_map(fn ($e) => ['recipient' => $e['address'], 'domain_id' => $domainId], $entries);
        $result = $this->managementRequestOrFail('post', 'suppressions/spam-complaints', ['recipients' => $recipients], $config);
        return $result['body'];
    }

    public function importUnsubscribes(string $domain, array $entries, array $config = []): array
    {
        $domainId = $this->findDomainId($domain, $config);
        $recipients = array_map(fn ($e) => ['recipient' => $e['address'], 'domain_id' => $domainId], $entries);
        $result = $this->managementRequestOrFail('post', 'suppressions/unsubscribes', ['recipients' => $recipients], $config);
        return $result['body'];
    }

    // -- Inbound Routes --

    public function listRoutes(string $domain, array $config = []): array
    {
        $domainId = $this->findDomainId($domain, $config);
        if (! $domainId) {
            return [];
        }

        $result = $this->managementRequestOrFail('get', "inbound?domain_id={$domainId}", [], $config);
        return $result['body']['data'] ?? [];
    }

    public function createRoute(string $expression, array $actions, string $description, int $priority = 0, array $config = []): array
    {
        // MailerSend inbound routes use a different model — domain_id based with forwards
        // The expression parameter is treated as the domain name for domain_id resolution
        $domainId = $this->findDomainId($expression, $config);
        $forwards = array_map(fn ($action) => ['type' => 'webhook', 'value' => $action], $actions);

        $payload = [
            'name'           => $description ?: $expression,
            'domain_enabled' => true,
            'match_filter'   => ['type' => 'match_all'],
            'forwards'       => $forwards,
            'priority'       => $priority,
        ];

        if ($domainId) {
            $payload['domain_id'] = $domainId;
        }

        $result = $this->managementRequestOrFail('post', 'inbound', $payload, $config);

        return $result['body'];
    }

    public function updateRoute(string $routeId, array $params, array $config = []): array
    {
        $result = $this->managementRequestOrFail('put', "inbound/{$routeId}", $params, $config);
        return $result['body'];
    }

    public function deleteRoute(string $routeId, array $config = []): array
    {
        $result = $this->managementRequestOrFail('delete', "inbound/{$routeId}", [], $config);
        return $result['body'];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @var array<string, string> Memoized domain name → ID map per request */
    private array $domainIdCache = [];

    private function findDomainId(string $domain, array $config): ?string
    {
        $key = strtolower($domain);
        if (isset($this->domainIdCache[$key])) {
            return $this->domainIdCache[$key];
        }

        $result = $this->managementRequest('get', 'domains', [], $config);
        if (! $result['ok']) {
            return null;
        }

        // Cache all domains from the response
        foreach ($result['body']['data'] ?? [] as $d) {
            $name = strtolower($d['name'] ?? '');
            if (! empty($name) && isset($d['id'])) {
                $this->domainIdCache[$name] = $d['id'];
            }
        }

        return $this->domainIdCache[$key] ?? null;
    }

    private function fetchDnsRecords(string $domainId, array $config): array
    {
        if (empty($domainId)) {
            return [];
        }

        $result = $this->managementRequest('get', "domains/{$domainId}/dns-records", [], $config);
        if (! $result['ok']) {
            return [];
        }

        $records = [];
        foreach ($result['body']['data'] ?? [] as $record) {
            $records[] = [
                'type'    => $record['type'] ?? '',
                'name'    => $record['hostname'] ?? $record['name'] ?? '',
                'value'   => $record['value'] ?? '',
                'valid'   => ($record['status'] ?? '') === 'valid' ? 'valid' : 'unknown',
                'purpose' => $record['purpose'] ?? 'sending',
            ];
        }

        return $records;
    }

    private function mapEventName(string $event): string
    {
        return match ($event) {
            'delivered'      => 'activity.delivered',
            'permanent_fail' => 'activity.hard_bounced',
            'complained'     => 'activity.spam_complaint',
            'stored'         => 'activity.received',
            'opened'         => 'activity.opened',
            'clicked'        => 'activity.clicked',
            default          => "activity.{$event}",
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
            'mimeType' => $att['content_type'] ?? $att['type'] ?? 'application/octet-stream',
            'size'     => $att['size'] ?? strlen(base64_decode($att['content'] ?? '')),
            'content'  => base64_decode($att['content'] ?? ''),
        ], $attachments);
    }

    private function getSigningSecret(): string
    {
        return app(\App\Services\SettingService::class)->get('mailersend', 'webhook_signing_secret', '');
    }
}
