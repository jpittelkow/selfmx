<?php

namespace App\Services\Email;

use App\Models\Mailbox;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SesProvider implements EmailProviderInterface
{
    public function __construct(
        private SettingService $settingService,
    ) {}

    public function getName(): string
    {
        return 'ses';
    }

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
        $config = $domain->provider_config ?? [];
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
