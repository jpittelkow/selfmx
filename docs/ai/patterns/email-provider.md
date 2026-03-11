# Email Provider Pattern

Implement email providers using the capability-based interface hierarchy. Every provider implements the core `EmailProviderInterface`. Providers with management features additionally implement `ProviderManagementInterface` and the relevant concern interfaces.

**ADR**: [ADR-031: Email Provider Management Architecture](../../adr/031-email-provider-management-architecture.md)

## Interface Hierarchy

```php
// REQUIRED — every provider must implement this
class NewProvider implements EmailProviderInterface
{
    public function getName(): string { return 'newprovider'; }
    public function verifyWebhookSignature(Request $request): bool { /* ... */ }
    public function parseInboundEmail(Request $request): ParsedEmail { /* ... */ }
    public function sendEmail(Mailbox $mailbox, array $to, string $subject, string $html, ...): SendResult { /* ... */ }
    public function parseDeliveryEvent(Request $request): array { /* ... */ }
    public function addDomain(string $domain, array $config = []): DomainResult { /* ... */ }
    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult { /* ... */ }
    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool { /* ... */ }
}

// OPTIONAL — add management support with selective capabilities
class NewProvider implements
    EmailProviderInterface,
    ProviderManagementInterface,
    HasDkimManagement,        // if provider supports DKIM rotation
    HasWebhookManagement,     // if provider has webhook CRUD
    HasSuppressionManagement  // if provider has suppression lists
{
    public function getCapabilities(): array
    {
        return [
            'dkim_rotation'     => true,
            'webhooks'          => true,
            'inbound_routes'    => false,
            'events'            => false,
            'suppressions'      => true,
            'stats'             => false,
            'domain_listing'    => false,
            'domain_management' => false,
            'dns_records'       => false,
        ];
    }
}
```

## Concern Interfaces

Only implement the interfaces your provider actually supports:

| Interface | Methods | Use When |
|-----------|---------|----------|
| `HasDkimManagement` | `getDkimKey()`, `rotateDkimKey()` | Provider has DKIM key API |
| `HasDomainListing` | `listProviderDomains()` | Provider can list registered domains |
| `HasWebhookManagement` | `list/create/update/delete/testWebhook()` | Provider has webhook configuration API |
| `HasInboundRoutes` | `list/create/update/deleteRoute()` | Provider has mail routing rules |
| `HasEventLog` | `getEvents()` | Provider has queryable event/activity log |
| `HasSuppressionManagement` | `list/delete/check/importBounces/Complaints/Unsubscribes()` | Provider has suppression lists |
| `HasDeliveryStats` | `getDomainStats()`, `getTrackingSettings()`, `updateTrackingSetting()` | Provider has delivery statistics API |

## Core Method Implementations

### Webhook Signature Verification

Each provider has its own signature scheme. Verify before processing:

```php
// Mailgun: HMAC-SHA256 with timestamp + token
public function verifyWebhookSignature(Request $request): bool
{
    $data = $request->input('signature', []);
    $timestamp = $data['timestamp'] ?? '';
    $token = $data['token'] ?? '';
    $signature = $data['signature'] ?? '';

    $computed = hash_hmac('sha256', $timestamp . $token, $this->getSigningKey());
    return hash_equals($computed, $signature);
}

// SES: SNS certificate-based verification
public function verifyWebhookSignature(Request $request): bool
{
    // Fetch certificate from SNS URL, verify with OpenSSL
    $certUrl = $data['SigningCertURL'] ?? '';
    // Validate URL is from sns.*.amazonaws.com (SSRF protection)
    // ...
}
```

### Inbound Email Parsing

Parse provider-specific format into the common `ParsedEmail` DTO:

```php
public function parseInboundEmail(Request $request): ParsedEmail
{
    // Extract fields from provider-specific payload format
    return new ParsedEmail(
        fromAddress: $this->parseEmailAddress($rawFrom)['address'],
        fromName: $this->parseEmailAddress($rawFrom)['name'],
        to: $this->parseAddressList($rawTo),
        cc: $this->parseAddressList($rawCc),
        bcc: [],
        subject: $payload['subject'] ?? '',
        bodyText: $payload['body-plain'] ?? null,
        bodyHtml: $payload['body-html'] ?? null,
        headers: $this->parseHeaders($payload['message-headers'] ?? ''),
        attachments: $this->parseAttachments($request),
        messageId: $messageId,
        inReplyTo: $inReplyTo,
        references: $references,
        spamScore: $this->parseSpamScore($payload),
        providerMessageId: $providerMsgId,
        providerEventId: null,
        recipientAddress: $recipientAddress,
    );
}
```

### Sending Email

Build provider-specific payload and return `SendResult`:

```php
public function sendEmail(Mailbox $mailbox, array $to, string $subject, string $html, ...): SendResult
{
    try {
        $response = $this->callProviderApi('POST', '/messages', [
            'from' => $mailbox->display_name . ' <' . $mailbox->full_address . '>',
            'to' => implode(', ', array_map(fn($r) => $r['address'], $to)),
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            // ... attachments, cc, bcc, headers
        ], $config);

        return SendResult::success($response['id']);
    } catch (\Exception $e) {
        return SendResult::failure($e->getMessage());
    }
}
```

### Domain Registration

Register domain and return DNS records for verification:

```php
public function addDomain(string $domain, array $config = []): DomainResult
{
    try {
        $response = $this->callProviderApi('POST', '/domains', [
            'name' => $domain,
        ], $config);

        $dnsRecords = $this->extractDnsRecords($response);

        return DomainResult::success(
            providerDomainId: $response['domain']['name'] ?? $domain,
            dnsRecords: $dnsRecords,
        );
    } catch (\Exception $e) {
        return DomainResult::failure($e->getMessage());
    }
}
```

### Delivery Event Parsing

Map provider-specific events to generic status strings:

```php
public function parseDeliveryEvent(Request $request): array
{
    $eventData = $request->input('event-data', []);
    $event = $eventData['event'] ?? '';

    // Map provider events → generic statuses
    $statusMap = [
        'delivered'      => 'delivered',
        'failed'         => 'bounced',      // permanent
        'rejected'       => 'failed',
        'complained'     => 'complained',
        'stored'         => 'stored',
    ];

    return [
        'status' => $statusMap[$event] ?? $event,
        'provider_message_id' => $eventData['message']['headers']['message-id'] ?? null,
        'recipient' => $eventData['recipient'] ?? null,
        'timestamp' => $eventData['timestamp'] ?? null,
        'reason' => $eventData['delivery-status']['description'] ?? null,
    ];
}
```

## Credential Resolution

Providers receive credentials via the `$config` array. Never access settings directly in concern methods:

```php
// GOOD — credentials come via config parameter
public function listWebhooks(string $domain, array $config = []): array
{
    return $this->managementRequest('GET', "/domains/{$domain}/webhooks", [], $config);
}

// Inside managementRequest, resolve credentials:
private function managementRequest(string $method, string $path, array $payload, array $config): array
{
    $apiKey = $config['api_key'] ?? $this->getApiKey();  // config override → setting fallback
    $region = $config['region'] ?? $this->getRegion();
    $baseUrl = $region === 'eu' ? 'https://api.eu.mailgun.net/v3' : 'https://api.mailgun.net/v3';

    // Make HTTP request with auth...
}
```

## Error Handling

Throw `ProviderApiException` for API failures. The controller handles mapping:

```php
use App\Exceptions\ProviderApiException;

private function managementRequestOrFail(string $method, string $path, ...): array
{
    $response = Http::withBasicAuth('api', $apiKey)->{$method}($url, $payload);

    if (! $response->successful()) {
        throw new ProviderApiException(
            message: $response->json('message', 'Provider API error'),
            httpStatus: $response->status(),
            responseBody: $response->json() ?? [],
        );
    }

    return $response->json();
}
```

## Health Check

Management-capable providers implement `checkApiHealth()`:

```php
public function checkApiHealth(array $config = []): bool
{
    try {
        // Make a lightweight API call to verify credentials
        $this->managementRequestOrFail('GET', '/domains', ['limit' => 1], $config);
        return true;
    } catch (ProviderApiException) {
        return false;
    }
}
```

## Provider Registration

Register in `DomainService::resolveProvider()` and `EmailProviderAccount::supportedProviders()`:

```php
// DomainService::resolveProvider()
return match ($provider) {
    'mailgun'    => app(MailgunProvider::class),
    'ses'        => app(SesProvider::class),
    'postmark'   => app(PostmarkProvider::class),
    'newprovider' => app(NewProvider::class),
    default => throw new \InvalidArgumentException("Unknown provider: {$provider}"),
};

// EmailProviderAccount::credentialFieldsFor()
'newprovider' => ['api_key', 'webhook_signing_secret'],
```

## Webhook Auto-Configuration

On domain creation, `DomainService::createDomain()` auto-configures webhooks. If your provider uses `HasWebhookManagement`, the upsert pattern handles this:

```php
// Try create first, fall back to update on conflict (400)
try {
    $provider->createWebhook($domain, $event, $webhookUrl, $config);
} catch (ProviderApiException $e) {
    if ($e->httpStatus === 400) {
        // Webhook already exists, update it
        $provider->updateWebhook($domain, $event, $webhookUrl, $config);
    }
}
```

## Key Files

| File | Purpose |
|------|---------|
| `backend/app/Services/Email/EmailProviderInterface.php` | Core provider contract |
| `backend/app/Services/Email/ProviderManagementInterface.php` | Management capability declaration |
| `backend/app/Services/Email/Concerns/Has*.php` | Capability-specific interfaces (7 files) |
| `backend/app/Services/Email/MailgunProvider.php` | Reference implementation (full capabilities) |
| `backend/app/Services/Email/SesProvider.php` | Near-full capability implementation (missing only `HasInboundRoutes`) |
| `backend/app/Services/Email/ParsedEmail.php` | Inbound email DTO |
| `backend/app/Services/Email/SendResult.php` | Send result DTO |
| `backend/app/Services/Email/DomainResult.php` | Domain registration DTO |
| `backend/app/Services/Email/DomainVerificationResult.php` | Verification result DTO |
| `backend/app/Exceptions/ProviderApiException.php` | Provider error type |
| `backend/app/Services/Email/DomainService.php` | Domain lifecycle + provider factory |
| `backend/app/Http/Controllers/Api/ProviderManagementController.php` | Provider-agnostic management API |
| `backend/app/Http/Controllers/Api/EmailWebhookController.php` | Inbound/event webhook handler |
| `backend/app/Models/EmailProviderAccount.php` | Multi-account credential storage |
| `backend/app/Models/EmailDomain.php` | Domain model with config resolution |

**Related**: [ADR-031](../../adr/031-email-provider-management-architecture.md), [Recipe: Add Email Provider](../recipes/add-email-provider.md), [Anti-Patterns: Email Provider](../anti-patterns/email-provider.md)
