<?php

namespace App\Services\Email;

use App\Models\Mailbox;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostmarkProvider implements EmailProviderInterface
{
    public function __construct(
        private SettingService $settingService,
    ) {}

    public function getName(): string
    {
        return 'postmark';
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // Postmark does not sign webhook payloads
        // Security relies on the webhook URL being secret or IP allowlisting
        return true;
    }

    public function parseInboundEmail(Request $request): ParsedEmail
    {
        // Postmark sends inbound emails as JSON
        $data = $request->json()->all();

        $from = $data['FromFull'] ?? [];
        $toRecipients = array_map(fn ($r) => ['address' => $r['Email'] ?? '', 'name' => $r['Name'] ?? null], $data['ToFull'] ?? []);
        $ccRecipients = array_map(fn ($r) => ['address' => $r['Email'] ?? '', 'name' => $r['Name'] ?? null], $data['CcFull'] ?? []);

        $headers = [];
        foreach ($data['Headers'] ?? [] as $header) {
            $headers[$header['Name']] = $header['Value'];
        }

        $attachments = [];
        foreach ($data['Attachments'] ?? [] as $att) {
            $attachments[] = [
                'filename' => $att['Name'] ?? 'attachment',
                'content_type' => $att['ContentType'] ?? 'application/octet-stream',
                'content' => base64_decode($att['Content'] ?? ''),
                'size' => $att['ContentLength'] ?? strlen(base64_decode($att['Content'] ?? '')),
            ];
        }

        return new ParsedEmail(
            fromAddress: $from['Email'] ?? $data['From'] ?? '',
            fromName: $from['Name'] ?? null,
            to: $toRecipients,
            cc: $ccRecipients,
            bcc: [],
            subject: $data['Subject'] ?? '',
            bodyText: $data['TextBody'] ?? '',
            bodyHtml: $data['HtmlBody'] ?? '',
            headers: $headers,
            attachments: $attachments,
            messageId: $data['MessageID'] ?? $headers['Message-ID'] ?? '',
            inReplyTo: $headers['In-Reply-To'] ?? null,
            references: $headers['References'] ?? null,
            spamScore: isset($data['SpamScore']) ? (float) $data['SpamScore'] : null,
            providerMessageId: $data['MessageID'] ?? null,
            providerEventId: $data['MessageID'] ?? uniqid('pm_', true),
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
        $config = $domain->provider_config ?? [];
        $serverToken = $config['server_token'] ?? $this->settingService->get('postmark', 'server_token');

        if (empty($serverToken)) {
            return SendResult::failure('Postmark server token not configured');
        }

        $fromAddress = "{$mailbox->address}@{$domain->name}";
        $displayName = $mailbox->display_name;
        $from = $displayName ? "\"{$displayName}\" <{$fromAddress}>" : $fromAddress;

        $payload = [
            'From' => $from,
            'To' => implode(',', array_map(fn ($addr) => is_array($addr) ? $addr['address'] : $addr, $to)),
            'Subject' => $subject,
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
                'Accept' => 'application/json',
            ])->post('https://api.postmarkapp.com/email', $payload);

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
            'Delivery' => 'delivered',
            'Bounce' => 'bounced',
            'SpamComplaint' => 'complained',
            default => 'unknown',
        };

        return [
            'status' => $status,
            'provider_message_id' => $data['MessageID'] ?? null,
            'timestamp' => $data['DeliveredAt'] ?? $data['BouncedAt'] ?? now()->toIso8601String(),
            'details' => $data,
        ];
    }

    public function addDomain(string $domain, array $config = []): DomainResult
    {
        $accountToken = $config['account_token'] ?? $this->settingService->get('postmark', 'server_token');

        try {
            $response = Http::withHeaders([
                'X-Postmark-Server-Token' => $accountToken,
                'Accept' => 'application/json',
            ])->post('https://api.postmarkapp.com/domains', [
                'Name' => $domain,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $dnsRecords = [];

                if (isset($data['DKIMPendingHost'])) {
                    $dnsRecords[] = [
                        'type' => 'TXT',
                        'name' => $data['DKIMPendingHost'],
                        'value' => $data['DKIMPendingTextValue'] ?? '',
                    ];
                }
                if (isset($data['ReturnPathDomain'])) {
                    $dnsRecords[] = [
                        'type' => 'CNAME',
                        'name' => $data['ReturnPathDomain'],
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
                'X-Postmark-Server-Token' => $accountToken,
                'Accept' => 'application/json',
            ])->get('https://api.postmarkapp.com/domains');

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
            // Set inbound webhook URL
            $response = Http::withHeaders([
                'X-Postmark-Server-Token' => $serverToken,
                'Accept' => 'application/json',
            ])->put('https://api.postmarkapp.com/server', [
                'InboundHookUrl' => $webhookUrl,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Failed to configure Postmark webhook', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
