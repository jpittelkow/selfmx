<?php

namespace App\Services\Email;

use App\Exceptions\SesApiException;
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

class SesProvider implements
    EmailProviderInterface,
    ProviderManagementInterface,
    HasDkimManagement,
    HasWebhookManagement,
    HasEventLog,
    HasSuppressionManagement,
    HasDeliveryStats
{
    public function __construct(
        private SettingService $settingService,
    ) {}

    public function getName(): string
    {
        return 'ses';
    }

    // -------------------------------------------------------------------------
    // ProviderManagementInterface
    // -------------------------------------------------------------------------

    public function getCapabilities(): array
    {
        return [
            'dkim_rotation'     => false, // SES DKIM is managed differently — no rotation endpoint
            'webhooks'          => true,  // via SES configuration sets + event destinations
            'inbound_routes'    => false, // not supported
            'events'            => false, // no queryable event log in SES v2
            'suppressions'      => true,  // SES v2 account-level suppression list
            'stats'             => true,  // basic send statistics
            'domain_management' => false,
            'dns_records'       => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Existing EmailProviderInterface methods (unchanged)
    // -------------------------------------------------------------------------

    public function verifyWebhookSignature(Request $request): bool
    {
        // SES sends SNS notifications — verify the SNS message signature
        $message = $request->getContent();
        $data = json_decode($message, true);

        if (!$data || !isset($data['SignatureVersion'])) {
            return false;
        }

        // Verify the signing certificate URL is from SNS
        $certUrl = $data['SigningCertURL'] ?? '';
        if (!str_starts_with($certUrl, 'https://sns.') || !str_contains($certUrl, '.amazonaws.com/')) {
            return false;
        }

        try {
            $cert = Http::get($certUrl)->body();
            $pubKey = openssl_pkey_get_public($cert);
            if (!$pubKey) {
                return false;
            }

            $stringToSign = $this->buildSnsStringToSign($data);
            $signature = base64_decode($data['Signature'] ?? '');

            return openssl_verify($stringToSign, $signature, $pubKey, OPENSSL_ALGO_SHA1) === 1;
        } catch (\Exception $e) {
            Log::warning('SES webhook signature verification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function parseInboundEmail(Request $request): ParsedEmail
    {
        $body = json_decode($request->getContent(), true);
        $messageType = $body['Type'] ?? '';

        // Handle SNS subscription confirmation
        if ($messageType === 'SubscriptionConfirmation') {
            Http::get($body['SubscribeURL']);
            throw new \RuntimeException('SNS subscription confirmed');
        }

        // Parse the actual email from the SNS notification
        $snsMessage = json_decode($body['Message'] ?? '{}', true);
        $mail = $snsMessage['mail'] ?? [];
        $content = $snsMessage['content'] ?? '';

        $headers = [];
        foreach ($mail['headers'] ?? [] as $header) {
            $headers[$header['name']] = $header['value'];
        }

        $from = $mail['source'] ?? '';
        $fromName = $headers['From'] ?? $from;
        $parsedFrom = $this->parseEmailAddress($fromName);

        return new ParsedEmail(
            fromAddress: $parsedFrom['address'] ?: $from,
            fromName: $parsedFrom['name'],
            to: array_map(fn ($addr) => ['address' => $addr, 'name' => null], $mail['destination'] ?? []),
            cc: $this->parseAddressHeader($headers['Cc'] ?? ''),
            bcc: [],
            subject: $headers['Subject'] ?? $mail['commonHeaders']['subject'] ?? '',
            bodyText: $this->extractBodyPart($content, 'text/plain'),
            bodyHtml: $this->extractBodyPart($content, 'text/html'),
            headers: $headers,
            attachments: [],
            messageId: $mail['messageId'] ?? $headers['Message-ID'] ?? '',
            inReplyTo: $headers['In-Reply-To'] ?? null,
            references: $headers['References'] ?? null,
            spamScore: null,
            providerMessageId: $mail['messageId'] ?? null,
            providerEventId: $body['MessageId'] ?? uniqid('ses_', true),
            recipientAddress: $mail['destination'][0] ?? '',
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
        $region = $config['region'] ?? $this->settingService->get('ses', 'region', 'us-east-1');
        $accessKey = $config['access_key_id'] ?? $this->settingService->get('ses', 'access_key_id');
        $secretKey = $config['secret_access_key'] ?? $this->settingService->get('ses', 'secret_access_key');

        if (empty($accessKey) || empty($secretKey)) {
            return SendResult::failure('AWS SES credentials not configured');
        }

        $fromAddress = "{$mailbox->address}@{$domain->name}";
        $displayName = $mailbox->display_name;
        $from = $displayName ? "\"{$displayName}\" <{$fromAddress}>" : $fromAddress;

        try {
            $endpoint = "https://email.{$region}.amazonaws.com";

            $params = [
                'Action' => 'SendEmail',
                'Source' => $from,
                'Message.Subject.Data' => $subject,
                'Message.Subject.Charset' => 'UTF-8',
                'Message.Body.Html.Data' => $html,
                'Message.Body.Html.Charset' => 'UTF-8',
            ];

            if ($text) {
                $params['Message.Body.Text.Data'] = $text;
                $params['Message.Body.Text.Charset'] = 'UTF-8';
            }

            foreach (array_values($to) as $i => $addr) {
                $params["Destination.ToAddresses.member." . ($i + 1)] = is_array($addr) ? $addr['address'] : $addr;
            }
            foreach (array_values($cc) as $i => $addr) {
                $params["Destination.CcAddresses.member." . ($i + 1)] = is_array($addr) ? $addr['address'] : $addr;
            }
            foreach (array_values($bcc) as $i => $addr) {
                $params["Destination.BccAddresses.member." . ($i + 1)] = is_array($addr) ? $addr['address'] : $addr;
            }

            // Sign request with AWS Signature V4
            $response = Http::withHeaders($this->signAwsRequest('POST', $endpoint, $params, $region, $accessKey, $secretKey))
                ->asForm()
                ->post($endpoint, $params);

            if ($response->successful()) {
                $messageId = $this->extractXmlValue($response->body(), 'MessageId');
                return SendResult::success($messageId ?? '');
            }

            return SendResult::failure('SES API error: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('SES send failed', ['error' => $e->getMessage()]);
            return SendResult::failure($e->getMessage());
        }
    }

    public function parseDeliveryEvent(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        $message = json_decode($body['Message'] ?? '{}', true);
        $eventType = $message['eventType'] ?? $message['notificationType'] ?? '';

        $status = match ($eventType) {
            'Delivery' => 'delivered',
            'Bounce' => 'bounced',
            'Complaint' => 'complained',
            'Reject' => 'rejected',
            default => 'unknown',
        };

        $mail = $message['mail'] ?? [];

        return [
            'status' => $status,
            'provider_message_id' => $mail['messageId'] ?? null,
            'timestamp' => $mail['timestamp'] ?? now()->toIso8601String(),
            'details' => $message,
        ];
    }

    public function addDomain(string $domain, array $config = []): DomainResult
    {
        $region = $config['region'] ?? $this->settingService->get('ses', 'region', 'us-east-1');
        $accessKey = $config['access_key_id'] ?? $this->settingService->get('ses', 'access_key_id');
        $secretKey = $config['secret_access_key'] ?? $this->settingService->get('ses', 'secret_access_key');

        try {
            $endpoint = "https://email.{$region}.amazonaws.com";
            $params = [
                'Action' => 'VerifyDomainIdentity',
                'Domain' => $domain,
            ];

            $response = Http::withHeaders($this->signAwsRequest('POST', $endpoint, $params, $region, $accessKey, $secretKey))
                ->asForm()
                ->post($endpoint, $params);

            if ($response->successful()) {
                $token = $this->extractXmlValue($response->body(), 'VerificationToken');
                return DomainResult::success($domain, [
                    ['type' => 'TXT', 'name' => "_amazonses.{$domain}", 'value' => $token],
                ]);
            }

            return DomainResult::failure('SES API error: ' . $response->body());
        } catch (\Exception $e) {
            return DomainResult::failure($e->getMessage());
        }
    }

    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult
    {
        $region = $config['region'] ?? $this->settingService->get('ses', 'region', 'us-east-1');
        $accessKey = $config['access_key_id'] ?? $this->settingService->get('ses', 'access_key_id');
        $secretKey = $config['secret_access_key'] ?? $this->settingService->get('ses', 'secret_access_key');

        try {
            $endpoint = "https://email.{$region}.amazonaws.com";
            $params = [
                'Action' => 'GetIdentityVerificationAttributes',
                'Identities.member.1' => $domain,
            ];

            $response = Http::withHeaders($this->signAwsRequest('POST', $endpoint, $params, $region, $accessKey, $secretKey))
                ->asForm()
                ->post($endpoint, $params);

            if ($response->successful()) {
                $isVerified = str_contains($response->body(), '<VerificationStatus>Success</VerificationStatus>');
                return new DomainVerificationResult($isVerified);
            }

            return new DomainVerificationResult(false, [], 'SES API error');
        } catch (\Exception $e) {
            return new DomainVerificationResult(false, [], $e->getMessage());
        }
    }

    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool
    {
        // SES uses SNS topics for notifications — this requires SNS configuration
        // which is typically done via AWS console or CloudFormation
        Log::info('SES domain webhook configuration requires SNS topic setup', [
            'domain' => $domain,
            'webhook_url' => $webhookUrl,
        ]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Management API — shared helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve credentials and region from per-domain config with fallback to
     * global settings.
     *
     * @return array{region: string, access_key: string, secret_key: string}
     */
    private function resolveCredentials(array $config): array
    {
        return [
            'region'     => $config['region'] ?? $this->settingService->get('ses', 'region', 'us-east-1'),
            'access_key' => $config['access_key_id'] ?? $this->settingService->get('ses', 'access_key_id', ''),
            'secret_key' => $config['secret_access_key'] ?? $this->settingService->get('ses', 'secret_access_key', ''),
        ];
    }

    /**
     * Perform an authenticated request against the SES v2 JSON API.
     *
     * All SES v2 endpoints use JSON bodies (unlike the v1 form-encoded API used
     * by sendEmail()). GET parameters are passed as query-string via $query.
     *
     * @param  string  $method   HTTP verb in lowercase: get|post|put|delete
     * @param  string  $path     Path relative to the v2 base, e.g. "email/account"
     * @param  array   $payload  JSON body (for POST/PUT) or query params (for GET/DELETE)
     * @param  array   $config   Per-domain credential overrides
     * @return array{status: int, body: array, ok: bool}
     */
    private function managementRequest(string $method, string $path, array $payload = [], array $config = []): array
    {
        $creds  = $this->resolveCredentials($config);
        $region = $creds['region'];
        $host   = "https://email.{$region}.amazonaws.com";
        $url    = "{$host}/v2/{$path}";

        try {
            $bodyString      = '';
            $queryString     = '';
            $canonicalUri    = '/v2/' . ltrim($path, '/');

            if (in_array(strtolower($method), ['post', 'put'], true)) {
                $bodyString = $payload ? json_encode($payload) : '{}';
            } else {
                // For GET / DELETE, encode payload as query parameters
                if ($payload) {
                    $queryString = http_build_query($payload);
                    $url .= '?' . $queryString;
                }
            }

            $headers = $this->signAwsV2Request(
                strtoupper($method),
                $host,
                $canonicalUri,
                $queryString,
                $bodyString,
                $region,
                $creds['access_key'],
                $creds['secret_key'],
            );

            $http = Http::withHeaders($headers);

            $response = match (strtolower($method)) {
                'post'   => $http->withBody($bodyString, 'application/json')->post($url),
                'put'    => $http->withBody($bodyString, 'application/json')->put($url),
                'delete' => $http->delete($url),
                default  => $http->get($url),
            };

            return [
                'status' => $response->status(),
                'body'   => $response->json() ?? [],
                'ok'     => $response->successful(),
            ];
        } catch (\Exception $e) {
            Log::error('SES management request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return ['status' => 0, 'body' => ['message' => $e->getMessage()], 'ok' => false];
        }
    }

    /**
     * Like managementRequest(), but throws SesApiException on failure.
     *
     * @throws SesApiException
     */
    private function managementRequestOrFail(string $method, string $path, array $payload = [], array $config = []): array
    {
        $result = $this->managementRequest($method, $path, $payload, $config);

        if (! $result['ok']) {
            $message = $result['body']['message'] ?? ('SES API error: HTTP ' . $result['status']);
            throw new SesApiException($message, $result['status'], $result['body']);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // API health
    // -------------------------------------------------------------------------

    public function checkApiHealth(array $config = []): bool
    {
        $result = $this->managementRequest('get', 'email/account', [], $config);
        return $result['ok'];
    }

    // -------------------------------------------------------------------------
    // HasDkimManagement
    // -------------------------------------------------------------------------

    /**
     * Retrieve DKIM signing attributes for the given domain identity.
     *
     * SES v2 returns DKIM details inside the identity response. There is no
     * standalone "get DKIM key" endpoint — the selector and public key are
     * exposed via GetEmailIdentity.
     */
    public function getDkimKey(string $domain, array $config = []): array
    {
        $result = $this->managementRequestOrFail('get', "email/identities/{$domain}", [], $config);
        $body   = $result['body'];

        $dkimAttrs = $body['DkimAttributes'] ?? [];
        $tokens    = $dkimAttrs['Tokens'] ?? [];
        $signingAttrs = $body['DkimSigningAttributes'] ?? [];

        return [
            'selector'    => $signingAttrs['DomainSigningSelector'] ?? ($tokens[0] ?? null),
            'public_key'  => $signingAttrs['DomainSigningPrivateKey'] ?? null, // private key is not returned; public key must be looked up in DNS
            'status'      => $dkimAttrs['Status'] ?? null,           // 'SUCCESS'|'PENDING'|'FAILED'|'TEMPORARY_FAILURE'|'NOT_STARTED'
            'signing_enabled' => $dkimAttrs['SigningEnabled'] ?? null,
            'tokens'      => $tokens,  // CNAME token(s) to add to DNS when using Easy DKIM
            'domain_info' => [
                'verified_for_sending' => $body['VerifiedForSendingStatus'] ?? null,
                'sending_enabled'      => $body['SendingAttributes']['SendingEnabled'] ?? null,
            ],
            'note' => 'SES DKIM is managed via DNS CNAME records (Easy DKIM) or by supplying your own RSA key pair. No rotation endpoint exists; re-enable Easy DKIM to rotate.',
        ];
    }

    /**
     * SES does not expose a DKIM rotation endpoint.
     *
     * The closest equivalent is to disable and re-enable Easy DKIM, which causes
     * SES to issue new CNAME tokens. We implement that here via the
     * PutEmailIdentityDkimAttributes API.
     */
    public function rotateDkimKey(string $domain, array $config = []): array
    {
        // Step 1: disable Easy DKIM
        $this->managementRequest('put', "email/identities/{$domain}/dkim", [
            'SigningEnabled' => false,
        ], $config);

        // Step 2: re-enable Easy DKIM to trigger token regeneration
        $result = $this->managementRequestOrFail('put', "email/identities/{$domain}/dkim", [
            'SigningEnabled' => true,
        ], $config);

        // Fetch updated identity to return fresh tokens
        $identity = $this->managementRequest('get', "email/identities/{$domain}", [], $config);
        $dkimAttrs = $identity['body']['DkimAttributes'] ?? [];

        return [
            'rotated'  => true,
            'tokens'   => $dkimAttrs['Tokens'] ?? [],
            'status'   => $dkimAttrs['Status'] ?? null,
            'note'     => 'Easy DKIM tokens regenerated. Update your DNS CNAME records with the new tokens.',
        ];
    }

    // -------------------------------------------------------------------------
    // HasWebhookManagement — SES Configuration Sets
    // -------------------------------------------------------------------------

    /**
     * List SES configuration sets (each set can have event destinations).
     *
     * SES does not have a single "webhooks" concept. Configuration sets group
     * sending events and route them to destinations (SNS, CloudWatch, etc.).
     * We model each configuration set + its SNS event destinations as webhooks.
     */
    public function listWebhooks(string $domain, array $config = []): array
    {
        $result = $this->managementRequestOrFail('get', 'email/configuration-sets', [], $config);
        $sets   = $result['body']['ConfigurationSets'] ?? [];

        $webhooks = [];
        foreach ($sets as $setName) {
            $destResult = $this->managementRequest('get', "email/configuration-sets/{$setName}/event-destinations", [], $config);
            foreach ($destResult['body']['EventDestinations'] ?? [] as $dest) {
                $snsUrl = $dest['SnsDestination']['TopicArn'] ?? null;
                $webhooks[] = [
                    'id'                  => "{$setName}::{$dest['Name']}",
                    'configuration_set'   => $setName,
                    'destination_name'    => $dest['Name'],
                    'enabled'             => $dest['Enabled'] ?? false,
                    'matching_event_types' => $dest['MatchingEventTypes'] ?? [],
                    'sns_topic_arn'       => $snsUrl,
                ];
            }
        }

        return $webhooks;
    }

    /**
     * Create an SNS event destination on the given configuration set.
     *
     * $event should be a comma-separated list of SES event types or a single type,
     * e.g. "SEND,DELIVERY,BOUNCE,COMPLAINT".
     * $url should be an SNS topic ARN (e.g. arn:aws:sns:us-east-1:123:MyTopic).
     * If the configuration set named $domain does not exist it will be created.
     */
    public function createWebhook(string $domain, string $event, string $url, array $config = []): array
    {
        // Ensure configuration set exists
        $this->managementRequest('post', 'email/configuration-sets', [
            'ConfigurationSetName' => $domain,
        ], $config);

        $eventTypes = array_map('trim', explode(',', strtoupper($event)));
        $destName   = 'dest-' . substr(md5($url . $event), 0, 8);

        $result = $this->managementRequestOrFail(
            'post',
            "email/configuration-sets/{$domain}/event-destinations",
            [
                'EventDestinationName' => $destName,
                'EventDestination'     => [
                    'Enabled'             => true,
                    'MatchingEventTypes'  => $eventTypes,
                    'SnsDestination'      => ['TopicArn' => $url],
                ],
            ],
            $config,
        );

        return [
            'id'                 => "{$domain}::{$destName}",
            'configuration_set' => $domain,
            'destination_name'  => $destName,
            'matching_event_types' => $eventTypes,
            'sns_topic_arn'     => $url,
        ];
    }

    /**
     * Update the SNS topic ARN or event types on an existing event destination.
     *
     * $webhookId should be in the format "ConfigSetName::DestinationName" as
     * returned by listWebhooks().
     */
    public function updateWebhook(string $domain, string $webhookId, string $url, array $config = []): array
    {
        [$setName, $destName] = $this->parseWebhookId($webhookId, $domain);

        // Fetch existing destination to preserve event types
        $existing  = $this->managementRequest('get', "email/configuration-sets/{$setName}/event-destinations", [], $config);
        $eventTypes = [];
        foreach ($existing['body']['EventDestinations'] ?? [] as $dest) {
            if ($dest['Name'] === $destName) {
                $eventTypes = $dest['MatchingEventTypes'] ?? [];
                break;
            }
        }

        $result = $this->managementRequestOrFail(
            'put',
            "email/configuration-sets/{$setName}/event-destinations/{$destName}",
            [
                'EventDestination' => [
                    'Enabled'            => true,
                    'MatchingEventTypes' => $eventTypes ?: ['SEND', 'DELIVERY', 'BOUNCE', 'COMPLAINT'],
                    'SnsDestination'     => ['TopicArn' => $url],
                ],
            ],
            $config,
        );

        return [
            'id'                 => $webhookId,
            'configuration_set' => $setName,
            'destination_name'  => $destName,
            'sns_topic_arn'     => $url,
        ];
    }

    /**
     * Delete an event destination from a configuration set.
     */
    public function deleteWebhook(string $domain, string $webhookId, array $config = []): array
    {
        [$setName, $destName] = $this->parseWebhookId($webhookId, $domain);

        $this->managementRequestOrFail(
            'delete',
            "email/configuration-sets/{$setName}/event-destinations/{$destName}",
            [],
            $config,
        );

        return ['deleted' => true, 'id' => $webhookId];
    }

    /**
     * Send a test payload to the given URL using SSRF-protected HTTP POST.
     *
     * SES event destinations route to SNS rather than arbitrary HTTP endpoints,
     * so we simulate a test by posting a sample SNS-style payload directly to
     * the target URL (as selfmx's webhook receiver endpoint would normally
     * receive).
     */
    public function testWebhook(string $domain, string $webhookId, string $url, array $config = []): array
    {
        // SSRF protection: validate URL and pin DNS to prevent rebinding
        $urlValidator = app(\App\Services\UrlValidationService::class);
        $resolved = $urlValidator->validateAndResolve($url);
        if ($resolved === null) {
            return [
                'success'     => false,
                'status_code' => null,
                'message'     => 'Webhook URL must not resolve to a private or reserved IP address',
            ];
        }

        $timestamp = now()->toIso8601String();
        $payload = [
            'Type'      => 'Notification',
            'MessageId' => 'test-' . uniqid(),
            'TopicArn'  => 'arn:aws:sns:us-east-1:000000000000:selfmx-test',
            'Subject'   => 'Amazon SES Email Event Notification',
            'Timestamp' => $timestamp,
            'Message'   => json_encode([
                'eventType' => 'Delivery',
                'mail'      => [
                    'timestamp'   => $timestamp,
                    'messageId'   => 'test-' . uniqid(),
                    'source'      => "noreply@{$domain}",
                    'destination' => ['test@example.com'],
                ],
                'delivery' => [
                    'timestamp'            => $timestamp,
                    'recipients'           => ['test@example.com'],
                    'processingTimeMillis' => 100,
                    'smtpResponse'         => '250 2.0.0 OK',
                ],
            ]),
        ];

        try {
            $response = Http::timeout(10)
                ->withOptions($urlValidator->pinnedOptions($resolved))
                ->post($url, $payload);

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
     * SES v2 does not expose a queryable event log API.
     *
     * Events are routed to CloudWatch Logs, S3, Kinesis Data Firehose, or SNS
     * via configuration sets. There is no "list recent events" endpoint.
     *
     * This stub returns an empty result with an explanatory note so the UI can
     * surface a meaningful message rather than a hard error.
     */
    public function getEvents(string $domain, array $filters = [], array $config = []): array
    {
        return [
            'items'    => [],
            'nextPage' => null,
            'note'     => 'SES does not provide a queryable event log. Configure CloudWatch Logs or an S3 bucket as an event destination on your configuration set to archive events.',
        ];
    }

    // -------------------------------------------------------------------------
    // HasSuppressionManagement — SES v2 account-level suppression list
    // -------------------------------------------------------------------------

    /**
     * List suppressed addresses with reason BOUNCE.
     *
     * SES v2 has a single suppression list; we filter by Reason.
     */
    public function listBounces(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $params = ['Reason' => 'BOUNCE', 'PageSize' => $limit];
        if ($page) {
            $params['NextToken'] = $page;
        }

        $result = $this->managementRequestOrFail('get', 'email/suppression/addresses', $params, $config);
        return [
            'items'    => array_map([$this, 'normalizeSuppressionEntry'], $result['body']['SuppressedDestinationSummaries'] ?? []),
            'nextPage' => $result['body']['NextToken'] ?? null,
        ];
    }

    /**
     * List suppressed addresses with reason COMPLAINT.
     */
    public function listComplaints(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $params = ['Reason' => 'COMPLAINT', 'PageSize' => $limit];
        if ($page) {
            $params['NextToken'] = $page;
        }

        $result = $this->managementRequestOrFail('get', 'email/suppression/addresses', $params, $config);
        return [
            'items'    => array_map([$this, 'normalizeSuppressionEntry'], $result['body']['SuppressedDestinationSummaries'] ?? []),
            'nextPage' => $result['body']['NextToken'] ?? null,
        ];
    }

    /**
     * SES v2 suppression list does not have an "unsubscribe" reason.
     *
     * Returns empty list with a note explaining the limitation.
     */
    public function listUnsubscribes(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        return [
            'items'    => [],
            'nextPage' => null,
            'note'     => 'SES does not maintain a separate unsubscribe list. Opt-out tracking should be handled at the application level.',
        ];
    }

    /**
     * Remove a bounce entry from the SES v2 suppression list.
     */
    public function deleteBounce(string $domain, string $address, array $config = []): bool
    {
        $result = $this->managementRequest('delete', 'email/suppression/addresses/' . urlencode($address), [], $config);
        return $result['ok'];
    }

    /**
     * Remove a complaint entry from the SES v2 suppression list.
     */
    public function deleteComplaint(string $domain, string $address, array $config = []): bool
    {
        $result = $this->managementRequest('delete', 'email/suppression/addresses/' . urlencode($address), [], $config);
        return $result['ok'];
    }

    /**
     * SES has no unsubscribe list to delete from; returns true as a no-op.
     */
    public function deleteUnsubscribe(string $domain, string $address, ?string $tag = null, array $config = []): bool
    {
        return true;
    }

    /**
     * Check if an address appears in the SES suppression list.
     */
    public function checkSuppression(string $domain, string $address, array $config = []): array
    {
        $result = $this->managementRequest('get', 'email/suppression/addresses/' . urlencode($address), [], $config);

        if ($result['ok']) {
            $entry  = $result['body']['SuppressedDestination'] ?? [];
            $reason = strtolower($entry['Reason'] ?? 'unknown');
            return [
                'suppressed' => true,
                'reason'     => $reason === 'bounce' ? 'bounce' : ($reason === 'complaint' ? 'complaint' : $reason),
                'detail'     => $entry['LastUpdateTime'] ?? null,
            ];
        }

        if ($result['status'] === 404) {
            return ['suppressed' => false, 'reason' => null, 'detail' => null];
        }

        // Unexpected error — treat as unknown
        Log::warning('SES checkSuppression unexpected response', ['address' => $address, 'status' => $result['status']]);
        return ['suppressed' => false, 'reason' => null, 'detail' => null];
    }

    /**
     * Add addresses to the SES suppression list as BOUNCE.
     *
     * SES v2 only allows adding one address at a time via PUT; we loop.
     * $entries should be an array of ['address' => ..., 'code' => ..., 'error' => ...].
     */
    public function importBounces(string $domain, array $entries, array $config = []): array
    {
        return $this->importSuppressed($entries, 'BOUNCE', $config);
    }

    /**
     * Add addresses to the SES suppression list as COMPLAINT.
     */
    public function importComplaints(string $domain, array $entries, array $config = []): array
    {
        return $this->importSuppressed($entries, 'COMPLAINT', $config);
    }

    /**
     * SES has no unsubscribe concept — returns a not-supported result.
     */
    public function importUnsubscribes(string $domain, array $entries, array $config = []): array
    {
        return [
            'imported' => 0,
            'failed'   => 0,
            'note'     => 'SES does not support unsubscribe imports.',
        ];
    }

    // -------------------------------------------------------------------------
    // HasDeliveryStats
    // -------------------------------------------------------------------------

    /**
     * Retrieve basic send statistics from the SES v2 account.
     *
     * SES v2 provides aggregate send quota usage (GetSendQuota via v1) and
     * send statistics (GetSendStatistics via v1). The v2 API exposes
     * /v2/email/insights (requires a dedicated IP pool).  We use the v1
     * GetSendStatistics action which is available to all accounts.
     *
     * The returned format mirrors the Mailgun stats shape so the UI can render
     * it generically.
     */
    public function getDomainStats(string $domain, array $events, string $duration = '30d', string $resolution = 'day', array $config = []): array
    {
        $creds     = $this->resolveCredentials($config);
        $region    = $creds['region'];
        $endpoint  = "https://email.{$region}.amazonaws.com";

        // SES v1 GetSendStatistics returns rolling 14-day data in 15-minute buckets
        $params = ['Action' => 'GetSendStatistics'];
        try {
            $response = Http::withHeaders($this->signAwsRequest('POST', $endpoint, $params, $region, $creds['access_key'], $creds['secret_key']))
                ->asForm()
                ->post($endpoint, $params);

            if (! $response->successful()) {
                return ['stats' => [], 'start' => null, 'end' => null, 'note' => 'Unable to retrieve SES send statistics.'];
            }

            $stats = $this->parseSendStatisticsXml($response->body(), $duration, $resolution);

            return [
                'stats' => $stats,
                'start' => $stats ? $stats[0]['time'] ?? null : null,
                'end'   => $stats ? $stats[count($stats) - 1]['time'] ?? null : null,
                'note'  => 'SES statistics are account-level (not per-domain) and cover the last 14 days in 15-minute intervals.',
            ];
        } catch (\Exception $e) {
            Log::error('SES getDomainStats failed', ['error' => $e->getMessage()]);
            return ['stats' => [], 'start' => null, 'end' => null];
        }
    }

    /**
     * SES does not expose per-domain tracking settings via a management API.
     *
     * Open/click tracking is configured per configuration set in the AWS console
     * or via CloudFormation. Return a static representation.
     */
    public function getTrackingSettings(string $domain, array $config = []): array
    {
        return [
            'open'        => ['active' => null, 'note' => 'Managed via SES configuration set tracking options in the AWS console.'],
            'click'       => ['active' => null, 'note' => 'Managed via SES configuration set tracking options in the AWS console.'],
            'unsubscribe' => ['active' => null, 'note' => 'Not directly supported by SES.'],
        ];
    }

    /**
     * SES tracking settings cannot be updated via the v2 API in a domain-scoped
     * manner without knowing the configuration set name. Return a not-supported result.
     */
    public function updateTrackingSetting(string $domain, string $type, bool $active, array $config = []): array
    {
        return [
            'updated' => false,
            'note'    => 'SES tracking settings are managed per configuration set in the AWS console or via the PutConfigurationSetTrackingOptions API.',
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Sign an SES v2 JSON API request using AWS Signature V4.
     *
     * This is separate from the existing signAwsRequest() which signs form-encoded
     * v1 requests. Both use the same signing algorithm but differ in content-type
     * and payload hash calculation.
     *
     * @param  string  $canonicalUri  Full path including /v2/... prefix
     * @param  string  $queryString   Already-encoded query string (no leading ?)
     * @param  string  $bodyString    Raw JSON body (empty string for GET/DELETE)
     */
    private function signAwsV2Request(
        string $method,
        string $host,
        string $canonicalUri,
        string $queryString,
        string $bodyString,
        string $region,
        string $accessKey,
        string $secretKey,
    ): array {
        $service    = 'ses';
        $date       = gmdate('Ymd\THis\Z');
        $dateStamp  = gmdate('Ymd');
        $hostHeader = parse_url($host, PHP_URL_HOST);

        $contentType = empty($bodyString) ? 'application/json' : 'application/json';
        $payloadHash = hash('sha256', $bodyString);

        $canonicalHeaders = "content-type:{$contentType}\nhost:{$hostHeader}\nx-amz-date:{$date}\n";
        $signedHeaders    = 'content-type;host;x-amz-date';

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $queryString,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign    = "AWS4-HMAC-SHA256\n{$date}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', $service,
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true), true), true), true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return [
            'Host'          => $hostHeader,
            'X-Amz-Date'    => $date,
            'Content-Type'  => $contentType,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }

    /**
     * Parse a webhook ID in "ConfigSetName::DestinationName" format.
     *
     * Falls back to ($domain, $webhookId) when the ID does not contain "::".
     *
     * @return array{0: string, 1: string}
     */
    private function parseWebhookId(string $webhookId, string $domain): array
    {
        if (str_contains($webhookId, '::')) {
            [$setName, $destName] = explode('::', $webhookId, 2);
            return [$setName, $destName];
        }
        // Fallback: treat $domain as the configuration set and $webhookId as the destination name
        return [$domain, $webhookId];
    }

    /**
     * Normalize a SES suppression list entry to a common shape.
     */
    private function normalizeSuppressionEntry(array $entry): array
    {
        return [
            'address'    => $entry['EmailAddress'] ?? null,
            'reason'     => strtolower($entry['Reason'] ?? ''),
            'created_at' => $entry['LastUpdateTime'] ?? null,
        ];
    }

    /**
     * Import addresses into the SES v2 suppression list for the given reason.
     *
     * SES only supports one address per PUT call, so we iterate and collect results.
     */
    private function importSuppressed(array $entries, string $reason, array $config): array
    {
        $imported = 0;
        $failed   = 0;

        foreach ($entries as $entry) {
            $address = is_array($entry) ? ($entry['address'] ?? '') : (string) $entry;
            if (empty($address)) {
                $failed++;
                continue;
            }

            $result = $this->managementRequest('put', 'email/suppression/addresses', [
                'EmailAddress' => $address,
                'Reason'       => $reason,
            ], $config);

            $result['ok'] ? $imported++ : $failed++;
        }

        return ['imported' => $imported, 'failed' => $failed];
    }

    /**
     * Parse the GetSendStatistics XML response and aggregate into time-bucketed
     * stats matching the Mailgun stats shape:
     * [['time' => '...', 'delivered' => n, 'bounced' => n, 'complained' => n, 'sent' => n], ...]
     *
     * SES returns 15-minute DataPoints; we aggregate according to $resolution.
     */
    private function parseSendStatisticsXml(string $xml, string $duration, string $resolution): array
    {
        // Extract all SendDataPoint elements
        preg_match_all('/<member>(.*?)<\/member>/s', $xml, $matches);
        $dataPoints = [];

        foreach ($matches[1] ?? [] as $member) {
            $timestamp   = $this->extractXmlValue($member, 'Timestamp');
            $delivAttempts = (int) ($this->extractXmlValue($member, 'DeliveryAttempts') ?? 0);
            $bounces     = (int) ($this->extractXmlValue($member, 'Bounces') ?? 0);
            $complaints  = (int) ($this->extractXmlValue($member, 'Complaints') ?? 0);
            $rejects     = (int) ($this->extractXmlValue($member, 'Rejects') ?? 0);

            if ($timestamp) {
                $dataPoints[] = [
                    'time'      => $timestamp,
                    'sent'      => $delivAttempts,
                    'delivered' => $delivAttempts - $bounces - $rejects,
                    'bounced'   => $bounces,
                    'complained' => $complaints,
                    'rejected'  => $rejects,
                ];
            }
        }

        if (empty($dataPoints)) {
            return [];
        }

        // Sort ascending by timestamp
        usort($dataPoints, fn ($a, $b) => strcmp($a['time'], $b['time']));

        // Apply duration filter — SES returns up to 14 days; we can filter client-side
        $durationDays = (int) rtrim($duration, 'd');
        if ($durationDays > 0) {
            $cutoff = now()->subDays($durationDays)->toIso8601String();
            $dataPoints = array_values(array_filter($dataPoints, fn ($p) => $p['time'] >= $cutoff));
        }

        if ($resolution === 'hour' || $resolution === 'day' || $resolution === 'month') {
            $dataPoints = $this->aggregateStatsByResolution($dataPoints, $resolution);
        }

        return $dataPoints;
    }

    /**
     * Aggregate 15-minute data points into the requested resolution bucket.
     */
    private function aggregateStatsByResolution(array $dataPoints, string $resolution): array
    {
        $format = match ($resolution) {
            'month' => 'Y-m',
            'day'   => 'Y-m-d',
            default => 'Y-m-d\TH:00:00\Z', // hour
        };

        $buckets = [];
        foreach ($dataPoints as $point) {
            try {
                $dt  = new \DateTimeImmutable($point['time']);
                $key = $dt->format($format);
            } catch (\Exception) {
                continue;
            }

            if (! isset($buckets[$key])) {
                $buckets[$key] = ['time' => $key, 'sent' => 0, 'delivered' => 0, 'bounced' => 0, 'complained' => 0, 'rejected' => 0];
            }

            foreach (['sent', 'delivered', 'bounced', 'complained', 'rejected'] as $field) {
                $buckets[$key][$field] += $point[$field] ?? 0;
            }
        }

        return array_values($buckets);
    }

    // -------------------------------------------------------------------------
    // Unchanged private helpers from original implementation
    // -------------------------------------------------------------------------

    private function buildSnsStringToSign(array $data): string
    {
        $type = $data['Type'] ?? '';
        if ($type === 'Notification') {
            return "Message\n{$data['Message']}\nMessageId\n{$data['MessageId']}\nSubject\n" .
                ($data['Subject'] ?? '') . "\nTimestamp\n{$data['Timestamp']}\nTopicArn\n{$data['TopicArn']}\nType\n{$type}\n";
        }
        return "Message\n{$data['Message']}\nMessageId\n{$data['MessageId']}\nSubscribeURL\n" .
            ($data['SubscribeURL'] ?? '') . "\nTimestamp\n{$data['Timestamp']}\nToken\n" .
            ($data['Token'] ?? '') . "\nTopicArn\n{$data['TopicArn']}\nType\n{$type}\n";
    }

    private function parseEmailAddress(string $raw): array
    {
        if (preg_match('/^"?([^"<]*)"?\s*<([^>]+)>/', $raw, $matches)) {
            return ['name' => trim($matches[1]), 'address' => trim($matches[2])];
        }
        return ['name' => null, 'address' => trim($raw)];
    }

    private function parseAddressHeader(string $header): array
    {
        if (empty($header)) return [];
        return array_map(fn ($addr) => ['address' => trim($addr), 'name' => null], explode(',', $header));
    }

    private function extractBodyPart(string $content, string $mimeType): string
    {
        if (empty($content)) {
            return '';
        }

        // Split headers from body
        $parts = preg_split('/\r?\n\r?\n/', $content, 2);
        $headerBlock = $parts[0] ?? '';
        $bodyContent = $parts[1] ?? '';

        // Check if this is a multipart message
        if (preg_match('/Content-Type:\s*multipart\/\w+;\s*boundary="?([^";\r\n]+)"?/i', $headerBlock, $m)) {
            return $this->extractFromMultipart($bodyContent, $m[1], $mimeType);
        }

        // Single-part message — check if the content type matches
        if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $headerBlock, $m)) {
            $contentType = trim($m[1]);
            if (stripos($contentType, $mimeType) === false) {
                return '';
            }
        } elseif ($mimeType !== 'text/plain') {
            // No content-type header defaults to text/plain per RFC 2045
            return '';
        }

        return $this->decodeTransferEncoding($bodyContent, $headerBlock);
    }

    private function extractFromMultipart(string $body, string $boundary, string $mimeType): string
    {
        $parts = explode('--' . $boundary, $body);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === '--') {
                continue;
            }

            $sections = preg_split('/\r?\n\r?\n/', $part, 2);
            $partHeaders = $sections[0] ?? '';
            $partBody = $sections[1] ?? '';

            // Check for nested multipart
            if (preg_match('/Content-Type:\s*multipart\/\w+;\s*boundary="?([^";\r\n]+)"?/i', $partHeaders, $m)) {
                $result = $this->extractFromMultipart($partBody, $m[1], $mimeType);
                if ($result !== '') {
                    return $result;
                }
                continue;
            }

            if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $partHeaders, $m)) {
                if (stripos(trim($m[1]), $mimeType) !== false) {
                    return $this->decodeTransferEncoding($partBody, $partHeaders);
                }
            }
        }

        return '';
    }

    private function decodeTransferEncoding(string $body, string $headers): string
    {
        $encoding = '';
        if (preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $headers, $m)) {
            $encoding = strtolower(trim($m[1]));
        }

        return match ($encoding) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body)) ?: '',
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function extractXmlValue(string $xml, string $tag): ?string
    {
        if (preg_match("/<{$tag}>(.*?)<\/{$tag}>/s", $xml, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Sign a SES v1 form-encoded request using AWS Signature V4.
     *
     * Used by sendEmail(), addDomain(), verifyDomain(), and getDomainStats()
     * (which uses the v1 GetSendStatistics action). Do not modify.
     */
    private function signAwsRequest(string $method, string $endpoint, array $params, string $region, string $accessKey, string $secretKey): array
    {
        $service = 'ses';
        $date = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $headers = [
            'Host' => parse_url($endpoint, PHP_URL_HOST),
            'X-Amz-Date' => $date,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $canonicalQueryString = '';
        $canonicalHeaders = "content-type:application/x-www-form-urlencoded\nhost:{$headers['Host']}\nx-amz-date:{$date}\n";
        $signedHeaders = 'content-type;host;x-amz-date';
        $payloadHash = hash('sha256', http_build_query($params));

        $canonicalRequest = "{$method}\n/\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', $service,
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true), true), true), true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $headers;
    }
}
