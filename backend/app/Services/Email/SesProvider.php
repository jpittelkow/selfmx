<?php

namespace App\Services\Email;

use App\Exceptions\SesApiException;
use App\Models\Mailbox;
use App\Services\Email\Concerns\HasDeliveryStats;
use App\Services\Email\Concerns\HasDkimManagement;
use App\Services\Email\Concerns\HasDomainListing;
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
    HasDomainListing,
    HasWebhookManagement,
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
            'dkim_rotation'     => true,  // disable/re-enable Easy DKIM to rotate tokens
            'webhooks'          => true,  // via SES configuration sets + event destinations
            'inbound_routes'    => false, // not supported
            'events'            => false, // no queryable event log — use CloudWatch
            'suppressions'      => true,  // SES v2 account-level suppression list
            'stats'             => true,  // basic send statistics
            'domain_listing'    => true,  // SES v2 ListEmailIdentities
            'domain_management' => false,
            'dns_records'       => false,
        ];
    }

    // -------------------------------------------------------------------------
    // HasDomainListing
    // -------------------------------------------------------------------------

    public function listProviderDomains(array $config = []): array
    {
        $allDomains = [];
        $nextToken  = null;

        do {
            $params = ['PageSize' => 100];
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            $result = $this->managementRequestOrFail('get', 'email/identities', $params, $config);

            $items     = $result['body']['EmailIdentities'] ?? [];
            $nextToken = $result['body']['NextToken'] ?? null;

            foreach ($items as $item) {
                $identityName = $item['IdentityName'] ?? '';
                $identityType = $item['IdentityType'] ?? '';

                // Only include domain identities, skip email address identities
                if ($identityType !== 'DOMAIN') {
                    continue;
                }

                $sendingEnabled = $item['SendingEnabled'] ?? false;

                $allDomains[] = [
                    'name'       => $identityName,
                    'state'      => $sendingEnabled ? 'active' : 'unverified',
                    'created_at' => null,
                    'type'       => 'domain',
                    'is_disabled' => false,
                ];
            }
        } while ($nextToken);

        return [
            'domains' => $allDomains,
            'total'   => count($allDomains),
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
            attachments: $this->extractMimeAttachments($content),
            messageId: $mail['messageId'] ?? $headers['Message-ID'] ?? '',
            inReplyTo: $headers['In-Reply-To'] ?? null,
            references: $headers['References'] ?? null,
            spamScore: $this->parseSesSpamScore($headers),
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
        $creds = $this->resolveCredentials($config);

        if (empty($creds['access_key']) || empty($creds['secret_key'])) {
            return SendResult::failure('AWS SES credentials not configured');
        }

        $fromAddress = $mailbox->full_address;
        $displayName = $mailbox->display_name;
        $from = $displayName ? "\"{$displayName}\" <{$fromAddress}>" : $fromAddress;

        try {
            $endpoint = "https://email.{$creds['region']}.amazonaws.com";
            $configSetName = $this->configSetName($domain->name);

            // Build raw MIME message to support attachments and custom headers
            $rawMessage = $this->buildRawMimeMessage($from, $to, $cc, $bcc, $subject, $html, $text, $attachments, $headers);

            $params = [
                'Action' => 'SendRawEmail',
                'Source' => $from,
                'ConfigurationSetName' => $configSetName,
                'RawMessage.Data' => base64_encode($rawMessage),
            ];

            // Add explicit destinations so SES knows all recipients
            $destIndex = 1;
            foreach (array_values($to) as $addr) {
                $params["Destinations.member.{$destIndex}"] = is_array($addr) ? $addr['address'] : $addr;
                $destIndex++;
            }
            foreach (array_values($cc) as $addr) {
                $params["Destinations.member.{$destIndex}"] = is_array($addr) ? $addr['address'] : $addr;
                $destIndex++;
            }
            foreach (array_values($bcc) as $addr) {
                $params["Destinations.member.{$destIndex}"] = is_array($addr) ? $addr['address'] : $addr;
                $destIndex++;
            }

            $response = Http::withHeaders($this->signAwsRequest('POST', $endpoint, $params, $creds['region'], $creds['access_key'], $creds['secret_key']))
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

        // Handle SNS subscription confirmation on the events endpoint
        if (($body['Type'] ?? '') === 'SubscriptionConfirmation') {
            Http::get($body['SubscribeURL']);
            Log::info('SNS subscription confirmed for SES events endpoint', ['TopicArn' => $body['TopicArn'] ?? '']);
            return [
                'event_type' => 'subscription_confirmed',
                'provider_message_id' => null,
                'recipient' => null,
            ];
        }

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
        $recipients = $mail['destination'] ?? [];
        $bounce = $message['bounce'] ?? [];
        $complaint = $message['complaint'] ?? [];

        // Extract recipient from event-specific data
        $recipient = $recipients[0] ?? null;
        if ($bounce) {
            $recipient = $bounce['bouncedRecipients'][0]['emailAddress'] ?? $recipient;
        } elseif ($complaint) {
            $recipient = $complaint['complainedRecipients'][0]['emailAddress'] ?? $recipient;
        }

        // Extract error message for bounces
        $errorMessage = null;
        if ($bounce) {
            $errorMessage = $bounce['bouncedRecipients'][0]['diagnosticCode'] ?? ($bounce['bounceType'] ?? null);
        }

        return [
            'event_type' => $status,
            'provider_message_id' => $mail['messageId'] ?? null,
            'timestamp' => $mail['timestamp'] ?? now()->toIso8601String(),
            'recipient' => $recipient,
            'error_message' => $errorMessage,
        ];
    }

    public function addDomain(string $domain, array $config = []): DomainResult
    {
        $creds = $this->resolveCredentials($config);

        if (empty($creds['access_key']) || empty($creds['secret_key']) || empty($creds['region'])) {
            return DomainResult::failure('AWS SES credentials not configured');
        }

        try {
            $endpoint = "https://email.{$creds['region']}.amazonaws.com";

            // Step 1: Register domain identity
            $params = [
                'Action' => 'VerifyDomainIdentity',
                'Domain' => $domain,
            ];

            $response = Http::withHeaders($this->signAwsRequest('POST', $endpoint, $params, $creds['region'], $creds['access_key'], $creds['secret_key']))
                ->asForm()
                ->post($endpoint, $params);

            if (! $response->successful()) {
                return DomainResult::failure('SES API error: ' . $response->body());
            }

            $token = $this->extractXmlValue($response->body(), 'VerificationToken');
            if ($token === null) {
                return DomainResult::failure('SES did not return a verification token');
            }

            $dnsRecords = [
                ['type' => 'TXT', 'name' => "_amazonses.{$domain}", 'value' => $token],
            ];

            // Step 2: Enable DKIM and get CNAME tokens
            $dkimParams = [
                'Action' => 'VerifyDomainDkim',
                'Domain' => $domain,
            ];

            $dkimResponse = Http::withHeaders($this->signAwsRequest('POST', $endpoint, $dkimParams, $creds['region'], $creds['access_key'], $creds['secret_key']))
                ->asForm()
                ->post($endpoint, $dkimParams);

            if ($dkimResponse->successful()) {
                preg_match_all('/<member>([^<]+)<\/member>/', $dkimResponse->body(), $dkimMatches);
                foreach ($dkimMatches[1] ?? [] as $dkimToken) {
                    $dnsRecords[] = [
                        'type' => 'CNAME',
                        'name' => "{$dkimToken}._domainkey.{$domain}",
                        'value' => "{$dkimToken}.dkim.amazonses.com",
                    ];
                }
            }

            // Step 3: Add standard MX record for SES inbound
            $dnsRecords[] = [
                'type' => 'MX',
                'name' => $domain,
                'value' => "10 inbound-smtp.{$creds['region']}.amazonaws.com",
                'priority' => 10,
            ];

            return DomainResult::success($domain, $dnsRecords, [
                'ses_verification_token' => $token,
            ]);
        } catch (\Exception $e) {
            return DomainResult::failure($e->getMessage());
        }
    }

    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult
    {
        // Use SES v2 GetEmailIdentity for richer results (verification + DKIM status)
        try {
            $result = $this->managementRequestOrFail('get', "email/identities/{$domain}", [], $config);
            $body = $result['body'];

            $identityVerified = ($body['VerifiedForSendingStatus'] ?? false) === true;
            $dkimAttrs = $body['DkimAttributes'] ?? [];
            $dkimVerified = ($dkimAttrs['Status'] ?? '') === 'SUCCESS';

            // Domain is only fully verified when both identity and DKIM are confirmed
            $isVerified = $identityVerified && $dkimVerified;

            $creds = $this->resolveCredentials($config);
            $dnsRecords = [];

            // 1. TXT verification record
            $txtValue = $config['ses_verification_token'] ?? null;
            $dnsRecords[] = [
                'type' => 'TXT',
                'name' => "_amazonses.{$domain}",
                'value' => $txtValue ?: '(check SES console for token)',
                'valid' => $identityVerified ? 'valid' : 'invalid',
                'purpose' => 'verification',
            ];

            // 2. DKIM CNAME records
            foreach ($dkimAttrs['Tokens'] ?? [] as $token) {
                $dnsRecords[] = [
                    'type' => 'CNAME',
                    'name' => "{$token}._domainkey.{$domain}",
                    'value' => "{$token}.dkim.amazonses.com",
                    'valid' => $dkimVerified ? 'valid' : 'invalid',
                    'purpose' => 'dkim',
                ];
            }

            // 3. MX record for inbound email reception
            $region = $creds['region'] ?: 'us-east-1';
            $mxTarget = "inbound-smtp.{$region}.amazonaws.com";
            $mxValid = 'unknown';
            try {
                $mxRecords = @dns_get_record($domain, DNS_MX);
                foreach ($mxRecords ?: [] as $mx) {
                    if (rtrim($mx['target'] ?? '', '.') === $mxTarget) {
                        $mxValid = 'valid';
                        break;
                    }
                }
                if ($mxValid === 'unknown' && ! empty($mxRecords)) {
                    $mxValid = 'invalid';
                }
            } catch (\Exception $e) {
                // DNS lookup failed — leave as unknown
            }
            $dnsRecords[] = [
                'type' => 'MX',
                'name' => $domain,
                'value' => "10 {$mxTarget}",
                'valid' => $mxValid,
                'purpose' => 'receiving',
            ];

            return new DomainVerificationResult($isVerified, $dnsRecords);
        } catch (SesApiException $e) {
            // Identity not found — not verified
            if ($e->httpStatus === 404) {
                return new DomainVerificationResult(false, [], 'Domain not registered with SES');
            }
            return new DomainVerificationResult(false, [], $e->getMessage());
        } catch (\Exception $e) {
            return new DomainVerificationResult(false, [], $e->getMessage());
        }
    }

    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool
    {
        $creds = $this->resolveCredentials($config);

        if (empty($creds['access_key']) || empty($creds['secret_key'])) {
            Log::warning('SES inbound webhook: credentials not configured', ['domain' => $domain]);
            return false;
        }

        try {
            // Step 1: Create SNS topic and subscribe the webhook URL
            $topicArn = $this->ensureSnsTopic(
                str_replace('.', '-', $domain),
                'inbound',
                $webhookUrl,
                $config,
            );

            // Step 2: Discover or create a Receipt Rule Set
            // SES allows only ONE active rule set per account — reuse it if one exists
            $ruleSetName = $this->getOrCreateActiveRuleSet($creds);
            if ($ruleSetName === null) {
                Log::error('SES: failed to get or create active receipt rule set', ['domain' => $domain]);
                return false;
            }

            // Step 4: Create Receipt Rule with SNS action for this domain
            $ruleName = 'selfmx-inbound-' . str_replace('.', '-', $domain);
            $ruleResult = $this->sesV1Request('CreateReceiptRule', [
                'RuleSetName' => $ruleSetName,
                'Rule.Name' => $ruleName,
                'Rule.Enabled' => 'true',
                'Rule.ScanEnabled' => 'true',
                'Rule.TlsPolicy' => 'Optional',
                'Rule.Recipients.member.1' => $domain,
                'Rule.Actions.member.1.SNSAction.TopicArn' => $topicArn,
                'Rule.Actions.member.1.SNSAction.Encoding' => 'UTF-8',
            ], $creds);

            // AlreadyExists is fine — rule was previously created
            if (isset($ruleResult['error']) && $ruleResult['error']) {
                $raw = $ruleResult['raw'] ?? '';
                if (str_contains($raw, 'AlreadyExists')) {
                    Log::info('SES receipt rule already exists, skipping', ['domain' => $domain, 'rule' => $ruleName]);
                    return true;
                }

                Log::error('SES: failed to create receipt rule', [
                    'domain' => $domain,
                    'error' => $raw,
                ]);
                return false;
            }

            Log::info('SES inbound receipt rule configured', [
                'domain' => $domain,
                'rule_set' => $ruleSetName,
                'rule' => $ruleName,
                'topic_arn' => $topicArn,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('SES inbound webhook configuration failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Clean up AWS resources when a domain is deleted.
     *
     * Removes the Receipt Rule and SNS topic/subscription created by configureDomainWebhook().
     */
    public function cleanupDomainResources(string $domain, array $config = []): void
    {
        $creds = $this->resolveCredentials($config);

        if (empty($creds['access_key']) || empty($creds['secret_key'])) {
            return;
        }

        // Delete the Receipt Rule from the active rule set
        $describeResult = $this->sesV1Request('DescribeActiveReceiptRuleSet', [], $creds);
        $ruleSetName = $describeResult['Metadata']['Name'] ?? null;

        if ($ruleSetName) {
            $ruleName = 'selfmx-inbound-' . str_replace('.', '-', $domain);
            $result = $this->sesV1Request('DeleteReceiptRule', [
                'RuleSetName' => $ruleSetName,
                'RuleName' => $ruleName,
            ], $creds);

            if (isset($result['error']) && $result['error']) {
                Log::warning('SES: failed to delete receipt rule on domain cleanup', [
                    'domain' => $domain,
                    'rule' => $ruleName,
                    'error' => $result['raw'] ?? 'unknown',
                ]);
            } else {
                Log::info('SES: deleted receipt rule', ['domain' => $domain, 'rule' => $ruleName]);
            }
        }

        // Delete the SNS topic (also removes all subscriptions)
        $topicName = 'selfmx-' . str_replace('.', '-', $domain) . '-inbound';
        $region = $creds['region'];

        // We need the topic ARN to delete it — reconstruct or look it up
        // CreateTopic is idempotent and returns the ARN of an existing topic
        $topicResult = $this->snsRequest('CreateTopic', ['Name' => $topicName], $creds);
        $topicArn = $topicResult['CreateTopicResult']['TopicArn'] ?? null;

        if ($topicArn) {
            $deleteResult = $this->snsRequest('DeleteTopic', ['TopicArn' => $topicArn], $creds);
            if (isset($deleteResult['error']) && $deleteResult['error']) {
                Log::warning('SES: failed to delete SNS topic on domain cleanup', [
                    'domain' => $domain,
                    'topic' => $topicArn,
                ]);
            } else {
                Log::info('SES: deleted SNS topic', ['domain' => $domain, 'topic' => $topicArn]);
            }
        }
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
                // For GET / DELETE, encode payload as query parameters.
                // Sort by key for AWS Sig V4 canonical query string.
                if ($payload) {
                    ksort($payload);
                    $queryString = http_build_query($payload);
                    $url .= '?' . $queryString;
                }
            }

            $headers = $this->signAwsSigV4(
                strtoupper($method),
                parse_url($host, PHP_URL_HOST),
                $canonicalUri,
                $queryString,
                $bodyString,
                'application/json',
                'ses',
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
        $setName = $this->configSetName($domain);

        // Only fetch event destinations for this domain's configuration set
        $destResult = $this->managementRequest('get', "email/configuration-sets/{$setName}/event-destinations", [], $config);

        if (! $destResult['ok']) {
            // Config set doesn't exist yet — no webhooks configured
            return [];
        }

        // Map SES event types back to generic event names for the frontend
        $reverseMap = [
            'DELIVERY'  => 'delivered',
            'BOUNCE'    => 'permanent_fail',
            'COMPLAINT' => 'complained',
            'SEND'      => 'stored',
            'OPEN'      => 'opened',
            'CLICK'     => 'clicked',
        ];

        // Build {event_name: {urls: [sns_arn]}} format matching Mailgun's structure
        $webhooks = [];
        foreach ($destResult['body']['EventDestinations'] ?? [] as $dest) {
            $snsUrl = $dest['SnsDestination']['TopicArn'] ?? null;
            $eventTypes = $dest['MatchingEventTypes'] ?? [];

            foreach ($eventTypes as $sesType) {
                $eventName = $reverseMap[$sesType] ?? strtolower($sesType);
                $webhooks[$eventName] = [
                    'urls' => [$snsUrl ?? 'sns://' . $dest['Name']],
                    'id'   => "{$setName}::{$dest['Name']}",
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
        $setName = $this->configSetName($domain);

        // Ensure configuration set exists
        $this->managementRequest('post', 'email/configuration-sets', [
            'ConfigurationSetName' => $setName,
        ], $config);

        // Map generic event names to SES event types
        $eventTypes = $this->mapEventTypes($event);
        $destName   = 'dest-' . substr(md5($url . $event), 0, 8);

        // If URL is not an SNS ARN, auto-create an SNS topic + HTTP subscription
        $topicArn = $url;
        if (! str_starts_with($url, 'arn:aws:sns:')) {
            $topicArn = $this->ensureSnsTopic($setName, $destName, $url, $config);
        }

        $result = $this->managementRequestOrFail(
            'post',
            "email/configuration-sets/{$setName}/event-destinations",
            [
                'EventDestinationName' => $destName,
                'EventDestination'     => [
                    'Enabled'             => true,
                    'MatchingEventTypes'  => $eventTypes,
                    'SnsDestination'      => ['TopicArn' => $topicArn],
                ],
            ],
            $config,
        );

        return [
            'id'                 => "{$setName}::{$destName}",
            'configuration_set' => $setName,
            'destination_name'  => $destName,
            'matching_event_types' => $eventTypes,
            'sns_topic_arn'     => $topicArn,
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

        // If URL is not an SNS ARN, auto-create/reuse SNS topic
        $topicArn = $url;
        if (! str_starts_with($url, 'arn:aws:sns:')) {
            $topicArn = $this->ensureSnsTopic($setName, $destName, $url, $config);
        }

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
                    'SnsDestination'     => ['TopicArn' => $topicArn],
                ],
            ],
            $config,
        );

        return [
            'id'                 => $webhookId,
            'configuration_set' => $setName,
            'destination_name'  => $destName,
            'sns_topic_arn'     => $topicArn,
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
    // HasSuppressionManagement — SES v2 account-level suppression list
    // -------------------------------------------------------------------------

    /**
     * List suppressed addresses with reason BOUNCE.
     *
     * SES v2 has a single suppression list; we filter by Reason.
     */
    public function listBounces(string $domain, int $limit = 25, ?string $page = null, array $config = []): array
    {
        $params = ['Reasons' => 'BOUNCE', 'PageSize' => $limit];
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
        $params = ['Reasons' => 'COMPLAINT', 'PageSize' => $limit];
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

        // 403 often means account-level suppression list is not enabled in SES
        $errorMsg = $result['body']['message'] ?? ($result['body']['Message'] ?? null);
        Log::warning('SES checkSuppression unexpected response', [
            'address' => $address,
            'status' => $result['status'],
            'error' => $errorMsg,
            'hint' => $result['status'] === 403
                ? 'Ensure account-level suppression list is enabled in SES console (Account dashboard > Suppression list)'
                : null,
        ]);
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

            $rawStats = $this->parseSendStatisticsXml($response->body(), $duration, $resolution);

            // Transform flat SES format to Mailgun-compatible nested format for the frontend
            $stats = array_map(fn ($p) => [
                'time'      => $p['time'],
                'accepted'  => ['incoming' => 0, 'outgoing' => $p['sent'] ?? 0, 'total' => $p['sent'] ?? 0],
                'delivered'  => ['smtp' => $p['delivered'] ?? 0, 'http' => 0, 'total' => $p['delivered'] ?? 0],
                'failed'    => [
                    'permanent' => ['bounce' => $p['bounced'] ?? 0, 'total' => $p['bounced'] ?? 0],
                    'temporary' => ['espblock' => 0, 'total' => $p['rejected'] ?? 0],
                ],
                'complained' => ['total' => $p['complained'] ?? 0],
            ], $rawStats);

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
     * Sign an AWS API request using Signature V4.
     *
     * Shared by SES v1 (form-encoded), SES v2 (JSON), and SNS (form-encoded).
     */
    private function signAwsSigV4(
        string $method,
        string $hostHeader,
        string $canonicalUri,
        string $queryString,
        string $payload,
        string $contentType,
        string $service,
        string $region,
        string $accessKey,
        string $secretKey,
    ): array {
        $date       = gmdate('Ymd\THis\Z');
        $dateStamp  = gmdate('Ymd');

        $payloadHash = hash('sha256', $payload);

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
        return [$this->configSetName($domain), $webhookId];
    }

    /**
     * Convert a domain name to a valid SES configuration set name.
     *
     * SES only allows alphanumeric ASCII characters, hyphens, and underscores.
     */
    private function configSetName(string $domain): string
    {
        return str_replace('.', '-', $domain);
    }

    /**
     * Map generic webhook event names to SES event types.
     *
     * Accepts SES-native names (SEND, DELIVERY, etc.) or generic names
     * used by the autoconfigure flow (delivered, permanent_fail, etc.).
     */
    private function mapEventTypes(string $event): array
    {
        $map = [
            'delivered'      => 'DELIVERY',
            'permanent_fail' => 'BOUNCE',
            'complained'     => 'COMPLAINT',
            'stored'         => 'SEND',
            'opened'         => 'OPEN',
            'clicked'        => 'CLICK',
        ];

        $types = array_map('trim', explode(',', $event));
        $mapped = [];
        foreach ($types as $t) {
            $key = strtolower($t);
            $mapped[] = $map[$key] ?? strtoupper($t);
        }

        return array_unique($mapped);
    }

    /**
     * Ensure an SNS topic exists and has an HTTP(S) subscription for the given URL.
     *
     * Returns the topic ARN.
     *
     * @throws SesApiException
     */
    private function ensureSnsTopic(string $setName, string $destName, string $httpUrl, array $config = []): string
    {
        $topicName = "selfmx-{$setName}-{$destName}";
        $creds     = $this->resolveCredentials($config);

        // CreateTopic is idempotent — returns existing ARN if topic already exists
        $createResult = $this->snsRequest('CreateTopic', ['Name' => $topicName], $creds);
        // simplexml strips the root element, so TopicArn is at CreateTopicResult level
        $topicArn     = $createResult['CreateTopicResult']['TopicArn'] ?? null;

        if (! $topicArn) {
            $rawError = $createResult['raw'] ?? ($createResult['Error']['Message'] ?? json_encode($createResult));
            throw new SesApiException(
                "Failed to create SNS topic: {$rawError}",
                $createResult['status'] ?? 0,
                $createResult,
            );
        }

        // Subscribe the HTTP(S) endpoint (idempotent for same topic + endpoint)
        $protocol = str_starts_with($httpUrl, 'https') ? 'https' : 'http';
        $subResult = $this->snsRequest('Subscribe', [
            'TopicArn' => $topicArn,
            'Protocol' => $protocol,
            'Endpoint' => $httpUrl,
        ], $creds);

        $subArn = $subResult['SubscribeResult']['SubscriptionArn'] ?? null;
        if (! $subArn) {
            Log::warning('SNS Subscribe did not return SubscriptionArn — subscription may be pending confirmation', [
                'topic' => $topicArn, 'endpoint' => $httpUrl,
            ]);
        }

        return $topicArn;
    }

    /**
     * Make an AWS SNS API request (query-style API).
     */
    private function snsRequest(string $action, array $params, array $creds): array
    {
        $region = $creds['region'];
        $host   = "https://sns.{$region}.amazonaws.com";

        $params['Action']  = $action;
        $params['Version'] = '2010-03-31';

        $body = http_build_query($params);
        $hostHeader = "sns.{$region}.amazonaws.com";

        $headers = $this->signAwsSigV4(
            'POST',
            $hostHeader,
            '/',
            '',
            $body,
            'application/x-www-form-urlencoded',
            'sns',
            $region,
            $creds['access_key'],
            $creds['secret_key'],
        );

        try {
            $response = Http::withHeaders($headers)
                ->withBody($body, 'application/x-www-form-urlencoded')
                ->post($host);

            if (! $response->successful()) {
                Log::error('SNS API error', [
                    'action' => $action,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return ['error' => true, 'status' => $response->status(), 'raw' => $response->body()];
            }

            $xml = simplexml_load_string($response->body());
            return json_decode(json_encode($xml), true) ?: [];
        } catch (\Exception $e) {
            Log::error('SNS request exception', ['action' => $action, 'error' => $e->getMessage()]);
            return ['error' => true, 'status' => 0, 'raw' => $e->getMessage()];
        }
    }

    /**
     * Get the active Receipt Rule Set, or create and activate one named 'selfmx'.
     *
     * SES allows only ONE active rule set per account. If one already exists,
     * we add our rules to it rather than replacing it.
     */
    private function getOrCreateActiveRuleSet(array $creds): ?string
    {
        // DescribeActiveReceiptRuleSet returns the active set (or empty if none)
        $describeResult = $this->sesV1Request('DescribeActiveReceiptRuleSet', [], $creds);

        // If there's already an active rule set, reuse it
        $existingName = $describeResult['Metadata']['Name'] ?? null;
        if ($existingName) {
            Log::info('SES: reusing existing active receipt rule set', ['rule_set' => $existingName]);
            return $existingName;
        }

        // No active rule set — create and activate 'selfmx'
        $ruleSetName = 'selfmx';
        $createResult = $this->sesV1Request('CreateReceiptRuleSet', [
            'RuleSetName' => $ruleSetName,
        ], $creds);

        // AlreadyExists is fine (set exists but is inactive); other errors are not
        if (isset($createResult['error']) && $createResult['error']) {
            $raw = $createResult['raw'] ?? '';
            if (! str_contains($raw, 'AlreadyExists')) {
                Log::error('SES: failed to create receipt rule set', ['error' => $raw]);
                return null;
            }
        }

        $activateResult = $this->sesV1Request('SetActiveReceiptRuleSet', [
            'RuleSetName' => $ruleSetName,
        ], $creds);

        if (isset($activateResult['error']) && $activateResult['error']) {
            Log::error('SES: failed to activate receipt rule set', [
                'error' => $activateResult['raw'] ?? 'unknown',
            ]);
            return null;
        }

        return $ruleSetName;
    }

    /**
     * Make an AWS SES v1 API request (query-style, form-encoded).
     *
     * Used for Receipt Rule management which is only available in SES v1.
     */
    private function sesV1Request(string $action, array $params, array $creds): array
    {
        $region   = $creds['region'];
        $endpoint = "https://email.{$region}.amazonaws.com";

        $params['Action']  = $action;
        $params['Version'] = '2010-12-01';

        try {
            $response = Http::withHeaders($this->signAwsRequest('POST', $endpoint, $params, $region, $creds['access_key'], $creds['secret_key']))
                ->asForm()
                ->post($endpoint, $params);

            if (! $response->successful()) {
                Log::warning('SES v1 API error', [
                    'action' => $action,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return ['error' => true, 'status' => $response->status(), 'raw' => $response->body()];
            }

            $xml = simplexml_load_string($response->body());
            return json_decode(json_encode($xml), true) ?: [];
        } catch (\Exception $e) {
            Log::error('SES v1 request exception', ['action' => $action, 'error' => $e->getMessage()]);
            return ['error' => true, 'status' => 0, 'raw' => $e->getMessage()];
        }
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
                    'delivered' => max(0, $delivAttempts - $bounces - $rejects),
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
            // Per AWS SNS docs, Subject is only included in the string-to-sign when present
            $str = "Message\n{$data['Message']}\nMessageId\n{$data['MessageId']}\n";
            if (isset($data['Subject'])) {
                $str .= "Subject\n{$data['Subject']}\n";
            }
            $str .= "Timestamp\n{$data['Timestamp']}\nTopicArn\n{$data['TopicArn']}\nType\n{$type}\n";
            return $str;
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

    /**
     * Extract spam score from SES inbound email headers.
     *
     * SES adds X-SES-Spam-Verdict and X-SES-Virus-Verdict headers.
     * Returns a normalized 0-10 score (PASS=0, FAIL=10).
     */
    private function parseSesSpamScore(array $headers): ?float
    {
        $verdict = strtoupper($headers['X-SES-Spam-Verdict'] ?? '');
        if (empty($verdict)) {
            return null;
        }

        return match ($verdict) {
            'PASS' => 0.0,
            'FAIL' => 10.0,
            'GRAY', 'PROCESSING_FAILED' => 5.0,
            default => null,
        };
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
     * Build a raw MIME message string for use with SES SendRawEmail.
     *
     * Supports HTML + plain text body, CC, BCC, custom headers, and file
     * attachments. Uses multipart/mixed when attachments are present, or
     * multipart/alternative for text+HTML bodies without attachments.
     */
    private function buildRawMimeMessage(
        string $from,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $html,
        ?string $text,
        array $attachments,
        array $headers,
    ): string {
        $boundary = 'SelfMX_' . bin2hex(random_bytes(16));
        $hasAttachments = ! empty($attachments);

        $toAddresses = implode(', ', array_map(fn ($a) => is_array($a) ? $a['address'] : $a, $to));
        $ccAddresses = implode(', ', array_map(fn ($a) => is_array($a) ? $a['address'] : $a, $cc));

        $msg = "From: {$from}\r\n";
        $msg .= "To: {$toAddresses}\r\n";
        if ($ccAddresses) {
            $msg .= "Cc: {$ccAddresses}\r\n";
        }
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";

        // Custom headers (e.g., In-Reply-To, References) — strip CRLF to prevent header injection
        foreach ($headers as $name => $value) {
            if (is_string($name) && ! empty($value)) {
                $sanitizedName = str_replace(["\r", "\n"], '', $name);
                $sanitizedValue = str_replace(["\r", "\n"], '', $value);
                $msg .= "{$sanitizedName}: {$sanitizedValue}\r\n";
            }
        }

        if ($hasAttachments) {
            $altBoundary = 'SelfMX_alt_' . bin2hex(random_bytes(16));
            $msg .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";

            // Body part (multipart/alternative for text + HTML)
            $msg .= "--{$boundary}\r\n";
            $msg .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";

            if ($text) {
                $msg .= "--{$altBoundary}\r\n";
                $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
                $msg .= quoted_printable_encode($text) . "\r\n";
            }

            $msg .= "--{$altBoundary}\r\n";
            $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $msg .= quoted_printable_encode($html) . "\r\n";
            $msg .= "--{$altBoundary}--\r\n";

            // Attachment parts
            foreach ($attachments as $attachment) {
                $filename = $attachment['filename'] ?? $attachment['name'] ?? 'attachment';
                $content = $attachment['content'] ?? '';
                if (empty($content) && !empty($attachment['path']) && file_exists($attachment['path'])) {
                    $content = file_get_contents($attachment['path']);
                }
                $mimeType = $attachment['mime_type'] ?? $attachment['content_type'] ?? 'application/octet-stream';

                // Always base64-encode — round-trip detection is unreliable for short content
                $encoded = base64_encode(is_string($content) ? $content : '');

                $msg .= "--{$boundary}\r\n";
                $msg .= "Content-Type: {$mimeType}; name=\"{$filename}\"\r\n";
                $msg .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
                $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $msg .= chunk_split($encoded, 76, "\r\n");
            }

            $msg .= "--{$boundary}--\r\n";
        } else {
            // No attachments — use multipart/alternative for text + HTML
            $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";

            if ($text) {
                $msg .= "--{$boundary}\r\n";
                $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
                $msg .= quoted_printable_encode($text) . "\r\n";
            }

            $msg .= "--{$boundary}\r\n";
            $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $msg .= quoted_printable_encode($html) . "\r\n";
            $msg .= "--{$boundary}--\r\n";
        }

        return $msg;
    }

    /**
     * Extract attachments from raw MIME content in an SES inbound email.
     *
     * Parses multipart MIME parts and returns non-body parts as attachments.
     */
    private function extractMimeAttachments(string $content): array
    {
        if (empty($content)) {
            return [];
        }

        $parts = preg_split('/\r?\n\r?\n/', $content, 2);
        $headerBlock = $parts[0] ?? '';
        $bodyContent = $parts[1] ?? '';

        if (! preg_match('/Content-Type:\s*multipart\/\w+;\s*boundary="?([^";\r\n]+)"?/i', $headerBlock, $m)) {
            return [];
        }

        return $this->extractAttachmentsFromMultipart($bodyContent, $m[1]);
    }

    /**
     * Recursively extract attachments from multipart MIME sections.
     */
    private function extractAttachmentsFromMultipart(string $body, string $boundary): array
    {
        $attachments = [];
        $parts = explode('--' . $boundary, $body);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === '--') {
                continue;
            }

            $sections = preg_split('/\r?\n\r?\n/', $part, 2);
            $partHeaders = $sections[0] ?? '';
            $partBody = $sections[1] ?? '';

            // Recurse into nested multipart
            if (preg_match('/Content-Type:\s*multipart\/\w+;\s*boundary="?([^";\r\n]+)"?/i', $partHeaders, $m)) {
                $attachments = array_merge($attachments, $this->extractAttachmentsFromMultipart($partBody, $m[1]));
                continue;
            }

            // Check for Content-Disposition: attachment
            if (preg_match('/Content-Disposition:\s*attachment/i', $partHeaders)) {
                $filename = 'attachment';
                if (preg_match('/filename="?([^";\r\n]+)"?/i', $partHeaders, $m)) {
                    $filename = trim($m[1]);
                }

                $mimeType = 'application/octet-stream';
                if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $partHeaders, $m)) {
                    $mimeType = trim($m[1]);
                }

                $decoded = $this->decodeTransferEncoding($partBody, $partHeaders);

                $attachments[] = [
                    'filename' => $filename,
                    'mimeType' => $mimeType,
                    'size' => strlen($decoded),
                    'content' => $decoded,
                ];
            }
        }

        return $attachments;
    }

    /**
     * Sign a SES v1 form-encoded request using AWS Signature V4.
     *
     * Used by sendEmail(), addDomain(), and getDomainStats().
     */
    private function signAwsRequest(string $method, string $endpoint, array $params, string $region, string $accessKey, string $secretKey): array
    {
        return $this->signAwsSigV4(
            $method,
            parse_url($endpoint, PHP_URL_HOST),
            '/',
            '',
            http_build_query($params),
            'application/x-www-form-urlencoded',
            'ses',
            $region,
            $accessKey,
            $secretKey,
        );
    }
}
