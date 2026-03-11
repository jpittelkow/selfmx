<?php

namespace App\Services\Email;

use App\Exceptions\PostmarkApiException;
use App\Models\Mailbox;
use App\Services\Email\Concerns\HasDeliveryStats;
use App\Services\Email\Concerns\HasDkimManagement;
use App\Services\Email\Concerns\HasEventLog;
use App\Services\Email\Concerns\HasSuppressionManagement;
use App\Services\Email\Concerns\HasWebhookManagement;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostmarkProvider implements
    EmailProviderInterface,
    ProviderManagementInterface,
    HasDkimManagement,
    HasWebhookManagement,
    HasEventLog,
    HasSuppressionManagement,
    HasDeliveryStats
{
    private const BASE_URL = 'https://api.postmarkapp.com';

    /**
     * In-request cache: domain name => Postmark domain ID (integer).
     *
     * @var array<string, int>
     */
    private array $domainIdCache = [];

    public function __construct(
        private SettingService $settingService,
    ) {}

    public function getName(): string
    {
        return 'postmark';
    }

    // -------------------------------------------------------------------------
    // ProviderManagementInterface
    // -------------------------------------------------------------------------

    public function getCapabilities(): array
    {
        return [
            'dkim_rotation'     => true,
            'webhooks'          => true,
            'inbound_routes'    => false,
            'events'            => true,
            'suppressions'      => true,
            'stats'             => true,
            'domain_management' => false,
            'dns_records'       => false,
        ];
    }

    // -------------------------------------------------------------------------
    // EmailProviderInterface — inbound / delivery
    // -------------------------------------------------------------------------

    public function verifyWebhookSignature(Request $request): bool
    {
        // Postmark does not sign webhook payloads.
        // Security relies on the webhook URL being secret or IP allowlisting.
        return true;
    }

    public function parseInboundEmail(Request $request): ParsedEmail
    {
        $data = $request->json()->all();

        $from = $data['FromFull'] ?? [];
        $toRecipients = array_map(
            fn ($r) => ['address' => $r['Email'] ?? '', 'name' => $r['Name'] ?? null],
            $data['ToFull'] ?? []
        );
        $ccRecipients = array_map(
            fn ($r) => ['address' => $r['Email'] ?? '', 'name' => $r['Name'] ?? null],
            $data['CcFull'] ?? []
        );

        $headers = [];
        foreach ($data['Headers'] ?? [] as $header) {
            $headers[$header['Name']] = $header['Value'];
        }

        $attachments = [];
        foreach ($data['Attachments'] ?? [] as $att) {
            $attachments[] = [
                'filename'     => $att['Name'] ?? 'attachment',
                'content_type' => $att['ContentType'] ?? 'application/octet-stream',
                'content'      => base64_decode($att['Content'] ?? ''),
                'size'         => $att['ContentLength'] ?? strlen(base64_decode($att['Content'] ?? '')),
            ];
        }

        return new ParsedEmail(
            fromAddress:      $from['Email'] ?? $data['From'] ?? '',
            fromName:         $from['Name'] ?? null,
            to:               $toRecipients,
            cc:               $ccRecipients,
            bcc:              [],
            subject:          $data['Subject'] ?? '',
            bodyText:         $data['TextBody'] ?? '',
            bodyHtml:         $data['HtmlBody'] ?? '',
            headers:          $headers,
            attachments:      $attachments,
            messageId:        $data['MessageID'] ?? $headers['Message-ID'] ?? '',
            inReplyTo:        $headers['In-Reply-To'] ?? null,
            references:       $headers['References'] ?? null,
            spamScore:        isset($data['SpamScore']) ? (float) $data['SpamScore'] : null,
            providerMessageId: $data['MessageID'] ?? null,
            providerEventId:  $data['MessageID'] ?? uniqid('pm_', true),
            recipientAddress: $data['OriginalRecipient'] ?? ($toRecipients[0]['address'] ?? ''),
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
        $serverToken = $config['server_token'] ?? $this->settingService->get('postmark', 'server_token');

        if (empty($serverToken)) {
            return SendResult::failure('Postmark server token not configured');
        }

        $fromAddress = "{$mailbox->address}@{$domain->name}";
        $displayName = $mailbox->display_name;
        $from = $displayName ? "\"{$displayName}\" <{$fromAddress}>" : $fromAddress;

        $payload = [
            'From'     => $from,
            'To'       => implode(',', array_map(fn ($addr) => is_array($addr) ? $addr['address'] : $addr, $to)),
            'Subject'  => $subject,
            'HtmlBody' => $html,
        ];

        if ($text) {
            $payload['TextBody'] = $text;
        }
        if (!empty($cc)) {
            $payload['Cc'] = implode(',', array_map(fn ($addr) => is_array($addr) ? $addr['address'] : $addr, $cc));
        }
        if (!empty($bcc)) {
            $payload['Bcc'] = implode(',', array_map(fn ($addr) => is_array($addr) ? $addr['address'] : $addr, $bcc));
        }

        try {
            $response = Http::withHeaders([
                'X-Postmark-Server-Token' => $serverToken,
                'Accept'                  => 'application/json',
            ])->post(self::BASE_URL . '/email', $payload);

            if ($response->successful()) {
                $data = $response->json();
                return SendResult::success($data['MessageID'] ?? '');
            }

            return SendResult::failure('Postmark API error: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Postmark send failed', ['error' => $e->getMessage()]);
            return SendResult::failure($e->getMessage());
        }
    }

    public function parseDeliveryEvent(Request $request): array
    {
        $data = $request->json()->all();
        $recordType = $data['RecordType'] ?? '';

        $status = match ($recordType) {
            'Delivery'     => 'delivered',
            'Bounce'       => 'bounced',
            'SpamComplaint' => 'complained',
            default        => 'unknown',
        };

        return [
            'status'              => $status,
            'provider_message_id' => $data['MessageID'] ?? null,
            'timestamp'           => $data['DeliveredAt'] ?? $data['BouncedAt'] ?? now()->toIso8601String(),
            'details'             => $data,
        ];
    }

    public function addDomain(string $domain, array $config = []): DomainResult
    {
        $accountToken = $config['account_token'] ?? $this->settingService->get('postmark', 'server_token');

        try {
            $response = Http::withHeaders([
                'X-Postmark-Account-Token' => $accountToken,
                'Accept'                   => 'application/json',
            ])->post(self::BASE_URL . '/domains', [
                'Name' => $domain,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $dnsRecords = [];

                if (isset($data['DKIMPendingHost'])) {
                    $dnsRecords[] = [
                        'type'  => 'TXT',
                        'name'  => $data['DKIMPendingHost'],
                        'value' => $data['DKIMPendingTextValue'] ?? '',
                    ];
                }
                if (isset($data['ReturnPathDomain'])) {
                    $dnsRecords[] = [
                        'type'  => 'CNAME',
                        'name'  => $data['ReturnPathDomain'],
                        'value' => $data['ReturnPathDomainCNAMEValue'] ?? 'pm.mtasv.net',
                    ];
                }

                return DomainResult::success((string) ($data['ID'] ?? $domain), $dnsRecords);
            }

            return DomainResult::failure('Postmark API error: ' . $response->body());
        } catch (\Exception $e) {
            return DomainResult::failure($e->getMessage());
        }
    }

    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult
    {
        $accountToken = $config['account_token'] ?? $this->settingService->get('postmark', 'server_token');

        try {
            $response = Http::withHeaders([
                'X-Postmark-Account-Token' => $accountToken,
                'Accept'                   => 'application/json',
            ])->get(self::BASE_URL . '/domains');

            if ($response->successful()) {
                foreach ($response->json()['Domains'] ?? [] as $d) {
                    if (strtolower($d['Name'] ?? '') === strtolower($domain)) {
                        return new DomainVerificationResult(
                            $d['DKIMVerified'] ?? false,
                        );
                    }
                }
                return new DomainVerificationResult(false, [], 'Domain not found in Postmark');
            }

            return new DomainVerificationResult(false, [], 'Postmark API error');
        } catch (\Exception $e) {
            return new DomainVerificationResult(false, [], $e->getMessage());
        }
    }

    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool
    {
        $serverToken = $config['server_token'] ?? $this->settingService->get('postmark', 'server_token');

        try {
            $response = Http::withHeaders([
                'X-Postmark-Server-Token' => $serverToken,
                'Accept'                  => 'application/json',
            ])->put(self::BASE_URL . '/server', [
                'InboundHookUrl' => $webhookUrl,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Failed to configure Postmark webhook', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Management API — core HTTP helper
    // -------------------------------------------------------------------------

    /**
     * Perform an authenticated request against the Postmark management API.
     *
     * @param  string  $method            HTTP verb: get, post, put, delete
     * @param  string  $path              Path relative to BASE_URL, e.g. "/webhooks"
     * @param  array   $payload           Body/query data
     * @param  array   $config            Per-domain config overrides
     * @param  bool    $useAccountToken   When true, sends X-Postmark-Account-Token header
     * @return array{status: int, body: array, ok: bool}
     */
    private function managementRequest(
        string $method,
        string $path,
        array $payload = [],
        array $config = [],
        bool $useAccountToken = false,
    ): array {
        $token = $useAccountToken
            ? ($config['account_token'] ?? $this->settingService->get('postmark', 'account_token', ''))
            : ($config['server_token'] ?? $this->settingService->get('postmark', 'server_token', ''));

        $headerName = $useAccountToken
            ? 'X-Postmark-Account-Token'
            : 'X-Postmark-Server-Token';

        $url = self::BASE_URL . $path;

        try {
            $request = Http::withHeaders([
                $headerName => $token,
                'Accept'    => 'application/json',
            ]);

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
            Log::error('Postmark management request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return ['status' => 0, 'body' => ['Message' => $e->getMessage()], 'ok' => false];
        }
    }

    /**
     * Like managementRequest(), but throws PostmarkApiException on failure.
     *
     * @throws PostmarkApiException
     */
    private function managementRequestOrFail(
        string $method,
        string $path,
        array $payload = [],
        array $config = [],
        bool $useAccountToken = false,
    ): array {
        $result = $this->managementRequest($method, $path, $payload, $config, $useAccountToken);

        if (! $result['ok']) {
            $message = $result['body']['Message'] ?? ('Postmark API error: HTTP ' . $result['status']);
            throw new PostmarkApiException($message, $result['status'], $result['body']);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Health check
    // -------------------------------------------------------------------------

    public function checkApiHealth(array $config = []): bool
    {
        $result = $this->managementRequest('get', '/server', [], $config, useAccountToken: false);
        return $result['ok'];
    }

    // -------------------------------------------------------------------------
    // Domain ID resolution (account-level)
    // -------------------------------------------------------------------------

    /**
     * Resolve a domain name to its Postmark numeric domain ID.
     * Results are cached for the lifetime of this request.
     *
     * @throws PostmarkApiException
     */
    private function getDomainId(string $domain, array $config = []): int
    {
        $key = strtolower($domain);

        if (isset($this->domainIdCache[$key])) {
            return $this->domainIdCache[$key];
        }

        $result = $this->managementRequestOrFail('get', '/domains', [], $config, useAccountToken: true);

        foreach ($result['body']['Domains'] ?? [] as $d) {
            if (strtolower($d['Name'] ?? '') === $key) {
                $id = (int) $d['ID'];
                $this->domainIdCache[$key] = $id;
                return $id;
            }
        }

        throw new PostmarkApiException(
            "Domain '{$domain}' not found in Postmark account",
            404,
            [],
        );
    }

    // -------------------------------------------------------------------------
    // HasDkimManagement
    // -------------------------------------------------------------------------

    public function getDkimKey(string $domain, array $config = []): array
    {
        $domainId = $this->getDomainId($domain, $config);
        $result = $this->managementRequestOrFail('get', "/domains/{$domainId}", [], $config, useAccountToken: true);
        $body = $result['body'];

        return [
            'selector'         => $body['DKIMPendingHost'] ? explode('._domainkey.', $body['DKIMPendingHost'] ?? '')[0] : null,
            'public_key'       => $body['DKIMPendingTextValue'] ?? $body['DKIMTextValue'] ?? null,
            'valid'            => $body['DKIMVerified'] ?? false,
            'update_status'    => $body['DKIMUpdateStatus'] ?? null,
            'pending_host'     => $body['DKIMPendingHost'] ?? null,
            'pending_value'    => $body['DKIMPendingTextValue'] ?? null,
            'domain_info'      => [
                'id'   => $body['ID'] ?? null,
                'name' => $body['Name'] ?? $domain,
            ],
        ];
    }

    public function rotateDkimKey(string $domain, array $config = []): array
    {
        $domainId = $this->getDomainId($domain, $config);
        $result = $this->managementRequestOrFail(
            'post',
            "/domains/{$domainId}/rotateDKIM",
            [],
            $config,
            useAccountToken: true,
        );
        return $result['body'];
    }

    // -------------------------------------------------------------------------
    // HasWebhookManagement
    // -------------------------------------------------------------------------

    public function listWebhooks(string $domain, array $config = []): array
    {
        // The MessageStream param can be passed via filters; default to outbound.
        $stream = $config['message_stream'] ?? 'outbound';
        $result = $this->managementRequestOrFail(
            'get',
            '/webhooks',
            ['MessageStream' => $stream],
            $config,
            useAccountToken: false,
        );
        return $result['body']['Webhooks'] ?? [];
    }

    public function createWebhook(string $domain, string $event, string $url, array $config = []): array
    {
        $stream = $config['message_stream'] ?? 'outbound';

        // Build a Triggers block for the specified event type.
        $triggers = $this->buildWebhookTriggers($event, true);

        $result = $this->managementRequestOrFail(
            'post',
            '/webhooks',
            [
                'Url'           => $url,
                'MessageStream' => $stream,
                'Triggers'      => $triggers,
            ],
            $config,
            useAccountToken: false,
        );
        return $result['body'];
    }

    public function updateWebhook(string $domain, string $webhookId, string $url, array $config = []): array
    {
        $result = $this->managementRequestOrFail(
            'put',
            "/webhooks/{$webhookId}",
            ['Url' => $url],
            $config,
            useAccountToken: false,
        );
        return $result['body'];
    }

    public function deleteWebhook(string $domain, string $webhookId, array $config = []): array
    {
        $result = $this->managementRequestOrFail(
            'delete',
            "/webhooks/{$webhookId}",
            [],
            $config,
            useAccountToken: false,
        );
        return $result['body'];
    }

    /**
     * Send a sample Postmark-style webhook payload to a target URL for testing.
     * Uses SSRF protection via UrlValidationService.
     */
    public function testWebhook(string $domain, string $webhookId, string $targetUrl, array $config = []): array
    {
        $payload = [
            'RecordType' => 'Delivery',
            'MessageID'  => 'test-' . uniqid(),
            'Recipient'  => 'test@example.com',
            'DeliveredAt' => now()->toIso8601String(),
            'Details'    => 'Webhook test from selfmx',
            'Tag'        => '',
            'ServerID'   => 0,
            'Metadata'   => new \stdClass(),
        ];

        $urlValidator = app(\App\Services\UrlValidationService::class);
        $resolved = $urlValidator->validateAndResolve($targetUrl);
        if ($resolved === null) {
            return [
                'success'     => false,
                'status_code' => null,
                'message'     => 'Webhook URL must not resolve to a private or reserved IP address',
            ];
        }

        try {
            $response = Http::timeout(10)
                ->withOptions($urlValidator->pinnedOptions($resolved))
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($targetUrl, $payload);

            return [
                'success'     => $response->successful(),
                'status_code' => $response->status(),
                'message'     => $response->successful()
                    ? 'Webhook test delivered successfully'
                    : 'Webhook returned HTTP ' . $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success'     => false,
                'status_code' => null,
                'message'     => 'Failed to reach webhook URL: ' . $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------------------------
    // HasEventLog
    // -------------------------------------------------------------------------

    /**
     * Search outbound messages via the Postmark Message Search API.
     *
     * Supported $filters keys:
     *   recipient, fromemail, subject, tag, status,
     *   fromdate (ISO 8601), todate (ISO 8601), count (max 500), offset
     */
    public function getEvents(string $domain, array $filters = [], array $config = []): array
    {
        $params = array_filter(array_merge(['count' => 25, 'offset' => 0], $filters));

        $result = $this->managementRequestOrFail(
            'get',
            '/messages/outbound',
            $params,
            $config,
            useAccountToken: false,
        );

        return [
            'items'       => $result['body']['Messages'] ?? [],
            'total_count' => $result['body']['TotalCount'] ?? 0,
            'nextPage'    => null, // Postmark uses offset-based pagination
        ];
    }

    // -------------------------------------------------------------------------
    // HasSuppressionManagement
    // -------------------------------------------------------------------------

    public function listBounces(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $stream = $config['message_stream'] ?? 'outbound';
        $params = ['RecipientsType' => 'Bounced', 'MessageStream' => $stream];

        $result = $this->managementRequestOrFail(
            'get',
            '/suppressions/dump',
            $params,
            $config,
            useAccountToken: false,
        );

        $items = $result['body']['Suppressions'] ?? [];

        // Apply in-memory pagination to match interface contract (limit/offset).
        $offset = (int) ($page ?? 0);
        $paged  = array_slice($items, $offset, $limit);

        return [
            'items'    => $paged,
            'total'    => count($items),
            'nextPage' => (count($items) > $offset + $limit) ? (string) ($offset + $limit) : null,
        ];
    }

    public function listComplaints(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $stream = $config['message_stream'] ?? 'outbound';
        $params = ['RecipientsType' => 'SpamComplaint', 'MessageStream' => $stream];

        $result = $this->managementRequestOrFail(
            'get',
            '/suppressions/dump',
            $params,
            $config,
            useAccountToken: false,
        );

        $items  = $result['body']['Suppressions'] ?? [];
        $offset = (int) ($page ?? 0);
        $paged  = array_slice($items, $offset, $limit);

        return [
            'items'    => $paged,
            'total'    => count($items),
            'nextPage' => (count($items) > $offset + $limit) ? (string) ($offset + $limit) : null,
        ];
    }

    public function listUnsubscribes(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $stream = $config['message_stream'] ?? 'outbound';
        $params = ['RecipientsType' => 'Unsubscribed', 'MessageStream' => $stream];

        $result = $this->managementRequestOrFail(
            'get',
            '/suppressions/dump',
            $params,
            $config,
            useAccountToken: false,
        );

        $items  = $result['body']['Suppressions'] ?? [];
        $offset = (int) ($page ?? 0);
        $paged  = array_slice($items, $offset, $limit);

        return [
            'items'    => $paged,
            'total'    => count($items),
            'nextPage' => (count($items) > $offset + $limit) ? (string) ($offset + $limit) : null,
        ];
    }

    public function deleteBounce(string $domain, string $address, array $config = []): bool
    {
        $stream = $config['message_stream'] ?? 'outbound';
        $result = $this->managementRequest(
            'post',
            '/suppressions/delete',
            [
                'Suppressions' => [
                    ['EmailAddress' => $address, 'Stream' => $stream],
                ],
            ],
            $config,
            useAccountToken: false,
        );
        return $result['ok'];
    }

    public function deleteComplaint(string $domain, string $address, array $config = []): bool
    {
        $stream = $config['message_stream'] ?? 'outbound';
        $result = $this->managementRequest(
            'post',
            '/suppressions/delete',
            [
                'Suppressions' => [
                    ['EmailAddress' => $address, 'Stream' => $stream],
                ],
            ],
            $config,
            useAccountToken: false,
        );
        return $result['ok'];
    }

    public function deleteUnsubscribe(string $domain, string $address, ?string $tag = null, array $config = []): bool
    {
        $stream = $config['message_stream'] ?? 'outbound';
        $result = $this->managementRequest(
            'post',
            '/suppressions/delete',
            [
                'Suppressions' => [
                    ['EmailAddress' => $address, 'Stream' => $stream],
                ],
            ],
            $config,
            useAccountToken: false,
        );
        return $result['ok'];
    }

    /**
     * Check whether an address is suppressed as a bounce or spam complaint.
     *
     * Returns ['suppressed' => bool, 'reason' => 'bounce'|'complaint'|null, 'detail' => string|null]
     */
    public function checkSuppression(string $domain, string $address, array $config = []): array
    {
        $stream = $config['message_stream'] ?? 'outbound';

        foreach (['Bounced' => 'bounce', 'SpamComplaint' => 'complaint'] as $type => $reason) {
            $result = $this->managementRequest(
                'get',
                '/suppressions/dump',
                ['RecipientsType' => $type, 'MessageStream' => $stream],
                $config,
                useAccountToken: false,
            );

            if ($result['ok']) {
                foreach ($result['body']['Suppressions'] ?? [] as $entry) {
                    if (strtolower($entry['EmailAddress'] ?? '') === strtolower($address)) {
                        return [
                            'suppressed' => true,
                            'reason'     => $reason,
                            'detail'     => $entry['SuppressionReason'] ?? null,
                        ];
                    }
                }
            }
        }

        return ['suppressed' => false, 'reason' => null, 'detail' => null];
    }

    /**
     * Bulk-import bounce suppressions.
     *
     * @param  array  $entries  Each entry: ['email' => string] or ['address' => string]
     */
    public function importBounces(string $domain, array $entries, array $config = []): array
    {
        return $this->importSuppressions($entries, 'HardBounce', $config);
    }

    /**
     * Bulk-import spam-complaint suppressions.
     */
    public function importComplaints(string $domain, array $entries, array $config = []): array
    {
        return $this->importSuppressions($entries, 'SpamComplaint', $config);
    }

    /**
     * Bulk-import unsubscribe suppressions.
     */
    public function importUnsubscribes(string $domain, array $entries, array $config = []): array
    {
        return $this->importSuppressions($entries, 'ManualSuppression', $config);
    }

    // -------------------------------------------------------------------------
    // HasDeliveryStats
    // -------------------------------------------------------------------------

    /**
     * Get aggregate outbound stats for the server.
     *
     * Postmark's stats endpoint returns a flat aggregate (not a time-series),
     * so we wrap it into a single-bucket array matching Mailgun's shape:
     *   [['time' => null, 'sent' => int, 'delivered' => int, 'bounced' => int, 'complained' => int]]
     *
     * @param  array   $events    Ignored for Postmark (all stats are returned together).
     * @param  string  $duration  e.g. '30d' — converted to fromdate/todate query params.
     * @param  string  $resolution Ignored for Postmark (no time-series breakdown available).
     */
    public function getDomainStats(
        string $domain,
        array $events,
        string $duration = '30d',
        string $resolution = 'day',
        array $config = [],
    ): array {
        [$fromdate, $todate] = $this->durationToDateRange($duration);

        $params = array_filter([
            'fromdate' => $fromdate,
            'todate'   => $todate,
            'tag'      => $config['tag'] ?? null,
        ]);

        $result = $this->managementRequestOrFail(
            'get',
            '/stats/outbound',
            $params,
            $config,
            useAccountToken: false,
        );

        $body = $result['body'];

        // Normalise into the single-bucket time-series shape used by the UI.
        $stats = [
            [
                'time'      => $fromdate,
                'sent'      => $body['Sent'] ?? 0,
                'delivered' => ($body['Sent'] ?? 0) - ($body['Bounced'] ?? 0),
                'bounced'   => $body['Bounced'] ?? 0,
                'complained' => $body['SpamComplaints'] ?? 0,
                'opened'    => $body['Opens'] ?? 0,
                'clicked'   => $body['Clicks'] ?? 0,
            ],
        ];

        return [
            'stats' => $stats,
            'start' => $fromdate,
            'end'   => $todate,
            'raw'   => $body,
        ];
    }

    /**
     * Return tracking settings from the Postmark server.
     *
     * Normalised shape:
     *   ['open' => ['active' => bool], 'click' => ['active' => bool]]
     */
    public function getTrackingSettings(string $domain, array $config = []): array
    {
        $result = $this->managementRequestOrFail(
            'get',
            '/server',
            [],
            $config,
            useAccountToken: false,
        );

        $body = $result['body'];

        return [
            'open' => [
                'active' => (bool) ($body['TrackOpens'] ?? false),
            ],
            'click' => [
                'active' => ($body['TrackLinks'] ?? 'None') !== 'None',
                'mode'   => $body['TrackLinks'] ?? 'None',
            ],
            'unsubscribe' => [
                'active' => null,
                'note'   => 'Unsubscribe tracking is not supported by Postmark.',
            ],
        ];
    }

    /**
     * Update a tracking setting on the Postmark server.
     *
     * @param  string  $type   'open' or 'click'
     * @param  bool    $active Enable or disable
     */
    public function updateTrackingSetting(string $domain, string $type, bool $active, array $config = []): array
    {
        $payload = match ($type) {
            'open'  => ['TrackOpens' => $active],
            'click' => ['TrackLinks' => $active ? 'HtmlAndText' : 'None'],
            default => throw new \InvalidArgumentException("Unknown tracking type '{$type}'. Expected 'open' or 'click'."),
        };

        $result = $this->managementRequestOrFail(
            'put',
            '/server',
            $payload,
            $config,
            useAccountToken: false,
        );

        return $result['body'];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a Postmark Triggers block for a given event type.
     *
     * Postmark groups triggers under a structured object.  We enable only the
     * relevant trigger category for the requested event.
     */
    private function buildWebhookTriggers(string $event, bool $enabled): array
    {
        $triggers = [
            'Delivery' => ['Enabled' => false],
            'Bounce'   => ['Enabled' => false, 'IncludeContent' => false],
            'SpamComplaint' => ['Enabled' => false, 'IncludeContent' => false],
            'Open'     => ['Enabled' => false, 'PostFirstOpenOnly' => false],
            'Click'    => ['Enabled' => false],
            'Subscription' => ['Enabled' => false],
        ];

        $map = [
            'delivery'      => 'Delivery',
            'bounce'        => 'Bounce',
            'spam_complaint' => 'SpamComplaint',
            'open'          => 'Open',
            'click'         => 'Click',
            'subscription'  => 'Subscription',
        ];

        $pmKey = $map[strtolower($event)] ?? null;
        if ($pmKey && isset($triggers[$pmKey])) {
            $triggers[$pmKey]['Enabled'] = $enabled;
        }

        return $triggers;
    }

    /**
     * Convert a duration string like '30d', '7d', '1d' into [fromdate, todate] ISO 8601 strings.
     *
     * @return array{0: string, 1: string}
     */
    private function durationToDateRange(string $duration): array
    {
        $days = 30;
        if (preg_match('/^(\d+)d$/', $duration, $m)) {
            $days = (int) $m[1];
        }

        $todate   = now()->toDateString();
        $fromdate = now()->subDays($days)->toDateString();

        return [$fromdate, $todate];
    }

    /**
     * Bulk-import suppressions of any type.
     *
     * @param  array   $entries           Each entry may have 'email' or 'address' key.
     * @param  string  $suppressionReason Postmark reason: HardBounce, SpamComplaint, ManualSuppression
     */
    private function importSuppressions(array $entries, string $suppressionReason, array $config): array
    {
        $stream = $config['message_stream'] ?? 'outbound';

        $suppressions = array_map(function ($entry) use ($suppressionReason, $stream) {
            $address = $entry['email'] ?? $entry['address'] ?? $entry['EmailAddress'] ?? '';
            return [
                'EmailAddress'      => $address,
                'SuppressionReason' => $suppressionReason,
                'Stream'            => $stream,
            ];
        }, $entries);

        $result = $this->managementRequestOrFail(
            'post',
            '/suppressions',
            ['Suppressions' => $suppressions],
            $config,
            useAccountToken: false,
        );

        return $result['body'];
    }
}
