# Recipe: Add Email Provider

Add a new email provider (e.g., Postmark, Resend, MailerSend) with full or partial management capabilities.

**Before starting**: Review the [email provider comparison guide](../../plans/email-provider-comparison.md) for provider-specific requirements and API details.

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `backend/app/Services/Email/NewProvider.php` | Create | Provider implementation |
| `backend/app/Services/Email/DomainService.php` | Modify | Register in `resolveProvider()` |
| `backend/app/Models/EmailProviderAccount.php` | Modify | Add to `supportedProviders()` + `credentialFieldsFor()` |
| `backend/config/settings-schema.php` | Modify | Add settings group for provider (if needed) |
| `backend/tests/Feature/NewProviderTest.php` | Create | Test management endpoints |
| `backend/routes/api.php` | — | No changes needed (routes are provider-agnostic) |
| `backend/app/Http/Controllers/Api/ProviderManagementController.php` | — | No changes needed |

## Reference Implementation

**Full capabilities**: `backend/app/Services/Email/MailgunProvider.php` (Mailgun — implements all 7 concern interfaces)
**Partial capabilities**: `backend/app/Services/Email/SesProvider.php` (SES — implements 6 of 7 concern interfaces, missing only `HasInboundRoutes`)

## Step 1: Determine Provider Capabilities

Review the provider's API documentation and map to our capability interfaces:

| Our Interface | Provider Support? | Provider API Equivalent |
|--------------|------------------|------------------------|
| `EmailProviderInterface` (core) | **Required** | Send API, webhook/inbound API, domain API |
| `HasDkimManagement` | ? | DKIM key retrieval/rotation endpoint |
| `HasDomainListing` | ? | List domains endpoint |
| `HasWebhookManagement` | ? | Webhook CRUD endpoints |
| `HasInboundRoutes` | ? | Inbound routing/rule endpoints |
| `HasEventLog` | ? | Event/activity log query endpoint |
| `HasSuppressionManagement` | ? | Bounce/complaint/unsubscribe list endpoints |
| `HasDeliveryStats` | ? | Sending statistics endpoint |

**Requirements for the core interface**:
- Provider must support **inbound email processing** (webhooks or polling)
- Provider must support **outbound sending** via HTTP API
- Provider must support **custom domain registration**

## Step 2: Create the Provider Class

```php
<?php

namespace App\Services\Email;

use App\Exceptions\ProviderApiException;
use App\Models\Mailbox;
use App\Services\Email\Concerns\HasWebhookManagement;
// ... import only the concern interfaces you'll implement
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class NewProvider implements
    EmailProviderInterface,
    ProviderManagementInterface,
    // Only list interfaces the provider actually supports:
    HasWebhookManagement
{
    public function __construct(
        private SettingService $settingService,
    ) {}

    // ── Core EmailProviderInterface ─────────────────────

    public function getName(): string
    {
        return 'newprovider';
    }

    public function getCapabilities(): array
    {
        return [
            'dkim_rotation'     => false,
            'webhooks'          => true,   // Only true for interfaces we implement
            'inbound_routes'    => false,
            'events'            => false,
            'suppressions'      => false,
            'stats'             => false,
            'domain_listing'    => false,
            'domain_management' => false,
            'dns_records'       => false,
        ];
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // Implement provider-specific signature verification
        // See Mailgun (HMAC-SHA256) or SES (SNS certificate) for examples
    }

    public function parseInboundEmail(Request $request): ParsedEmail
    {
        // Parse provider-specific webhook payload into ParsedEmail DTO
        // Must return stable providerMessageId for idempotency
    }

    public function sendEmail(
        Mailbox $mailbox, array $to, string $subject, string $html,
        ?string $text = null, array $attachments = [], array $cc = [],
        array $bcc = [], array $headers = [],
    ): SendResult {
        $config = $this->getConfigForMailbox($mailbox);
        // Build provider-specific payload, make API call
        // Return SendResult::success($messageId) or SendResult::failure($error)
    }

    public function parseDeliveryEvent(Request $request): array
    {
        // Map provider events to: delivered, bounced, failed, complained, stored
        // Return: [status, provider_message_id, recipient, timestamp, reason]
    }

    public function addDomain(string $domain, array $config = []): DomainResult
    {
        // Register domain with provider, extract DNS records
        // Return DomainResult::success($domainId, $dnsRecords)
    }

    public function verifyDomain(string $domain, array $config = []): DomainVerificationResult
    {
        // Check DNS verification status with provider
    }

    public function configureDomainWebhook(string $domain, string $webhookUrl, array $config = []): bool
    {
        // Set up inbound email webhook URL with provider
    }

    // ── Health Check (for ProviderManagementInterface) ──

    public function checkApiHealth(array $config = []): bool
    {
        try {
            $this->managementRequestOrFail('GET', '/some-lightweight-endpoint', [], $config);
            return true;
        } catch (ProviderApiException) {
            return false;
        }
    }

    // ── HasWebhookManagement (example) ──────────────────

    public function listWebhooks(string $domain, array $config = []): array { /* ... */ }
    public function createWebhook(string $domain, string $event, string $url, array $config = []): array { /* ... */ }
    public function updateWebhook(string $domain, string $webhookId, string $url, array $config = []): array { /* ... */ }
    public function deleteWebhook(string $domain, string $webhookId, array $config = []): array { /* ... */ }
    public function testWebhook(string $domain, string $webhookId, string $url, array $config = []): array { /* ... */ }

    // ── Internal Helpers ────────────────────────────────

    private function getApiKey(array $config = []): string
    {
        return $config['api_key'] ?? $this->settingService->get('newprovider', 'api_key', '');
    }

    private function managementRequest(string $method, string $path, array $payload = [], array $config = []): array
    {
        $apiKey = $this->getApiKey($config);
        $baseUrl = 'https://api.newprovider.com/v1';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
        ])->{$method}($baseUrl . $path, $payload);

        return $response->json() ?? [];
    }

    private function managementRequestOrFail(string $method, string $path, array $payload = [], array $config = []): array
    {
        $result = $this->managementRequest($method, $path, $payload, $config);
        $response = Http::getLastPendingRequest(); // Pseudocode — use actual response

        // Throw ProviderApiException on failure
        if (! $response->successful()) {
            throw new ProviderApiException(
                message: $result['message'] ?? 'Provider API error',
                httpStatus: $response->status(),
                responseBody: $result,
            );
        }

        return $result;
    }
}
```

## Step 3: Register the Provider

### DomainService factory

```php
// backend/app/Services/Email/DomainService.php — resolveProvider()
return match ($provider) {
    'mailgun'      => app(MailgunProvider::class),
    'ses'          => app(SesProvider::class),
    'newprovider'  => app(NewProvider::class),  // ← Add this
    default        => throw new \InvalidArgumentException("Unknown provider: {$provider}"),
};
```

### Account model

```php
// backend/app/Models/EmailProviderAccount.php

// supportedProviders()
return ['mailgun', 'ses', 'postmark', 'resend', 'mailersend', 'smtp2go', 'newprovider'];

// credentialFieldsFor()
'newprovider' => ['api_key', 'webhook_signing_secret'],
```

## Step 4: Add Settings (Optional)

If the provider needs system-level default credentials (fallback when no account is configured):

```php
// backend/config/settings-schema.php — add a new group
'newprovider' => [
    'api_key' => [
        'type' => 'string',
        'default' => '',
        'env' => 'NEWPROVIDER_API_KEY',
        'encrypted' => true,
    ],
    'webhook_signing_secret' => [
        'type' => 'string',
        'default' => '',
        'env' => 'NEWPROVIDER_WEBHOOK_SIGNING_SECRET',
        'encrypted' => true,
    ],
],
```

## Step 5: Write Tests

Create a feature test that exercises each capability endpoint. Use the management test pattern:

```php
// backend/tests/Feature/NewProviderManagementTest.php

use App\Models\EmailDomain;
use App\Models\User;
use App\Services\Email\NewProvider;

test('health check returns healthy with valid API key', function () {
    $user = User::factory()->create();
    $domain = EmailDomain::factory()->create([
        'user_id' => $user->id,
        'provider' => 'newprovider',
    ]);

    // Mock the provider
    $this->mock(NewProvider::class, function ($mock) {
        $mock->shouldReceive('checkApiHealth')->andReturn(true);
        $mock->shouldReceive('getName')->andReturn('newprovider');
        $mock->shouldReceive('getCapabilities')->andReturn(['webhooks' => true]);
    });

    $this->actingAs($user)
        ->getJson('/api/email/provider/health?provider=newprovider')
        ->assertOk()
        ->assertJson(['healthy' => true]);
});

// Test each capability endpoint similarly...
```

**Reference test**: `backend/tests/Feature/MailgunManagementTest.php`

## Step 6: Verify

```bash
# Run tests
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan test --filter=NewProviderManagementTest"

# Verify the provider resolves
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan tinker --execute=\"echo App\Services\Email\DomainService::class;\""

# Check routes (no new routes needed — they're provider-agnostic)
docker exec selfmx-dev bash -c "cd /var/www/html/backend && php artisan route:list --path=email"
```

## Capability Implementation Guides

### Webhook Management

Map your provider's webhook API to the 5 methods. Key events to support:

| Generic Event | Description | Mailgun Equivalent | SES Equivalent |
|--------------|-------------|-------------------|----------------|
| `delivered` | Email delivered | `delivered` | `Delivery` via SNS |
| `permanent_fail` | Hard bounce | `permanent_fail` | `Bounce` via SNS |
| `complained` | Spam report | `complained` | `Complaint` via SNS |
| `stored` | Email stored/received | `stored` | `Receive` via SNS |
| `opened` | Email opened | `opened` | `Open` via config set |
| `clicked` | Link clicked | `clicked` | `Click` via config set |

Auto-configure pattern (upsert): try `createWebhook()`, if 400 (already exists), `updateWebhook()`.

### Suppression Management

Map provider's bounce/complaint lists. Key considerations:
- Some providers have **account-level** lists (SES), others **per-domain** (Mailgun)
- Support pagination (limit + page/cursor)
- Import/export should handle CSV format
- `checkSuppression()` checks if an address is on any list

### Delivery Statistics

Map provider's stats API. Normalize to common structure:

```php
return [
    'stats' => [
        ['time' => '2026-03-01T00:00:00Z', 'accepted' => 100, 'delivered' => 95, 'failed' => 3, 'bounced' => 1, 'complained' => 1],
        // ...
    ],
    'start' => '2026-03-01',
    'end' => '2026-03-10',
];
```

Support `duration` (1d, 7d, 30d, 90d) and `resolution` (hour, day, month) parameters.

### Tracking Settings

Not all providers support open/click/unsubscribe tracking via their API. If the provider **does not** support programmatic tracking control, return `active: null` with a `note`:

```php
public function getTrackingSettings(string $domain, array $config = []): array
{
    return [
        'open'        => ['active' => null, 'note' => 'Managed via provider console, not API.'],
        'click'       => ['active' => null, 'note' => 'Managed via provider console, not API.'],
        'unsubscribe' => ['active' => null, 'note' => 'Not supported by this provider.'],
    ];
}
```

The frontend will disable the toggle and display the `note` text. See [Tracking Limitations](../patterns/email-provider.md#tracking-limitations) for the full pattern.

### DKIM Management

If provider supports DKIM key retrieval/rotation:

```php
// getDkimKey() should return:
['selector' => 'k1', 'public_key' => '...', 'valid' => true, 'status' => 'active']

// rotateDkimKey() should return:
['rotated' => true, 'selector' => 'k2', 'public_key' => '...']
```

## Checklist

- [ ] Provider class created implementing `EmailProviderInterface`
- [ ] `getCapabilities()` returns accurate map matching implemented interfaces
- [ ] All implemented concern interface methods are complete
- [ ] Webhook signature verification implemented (never return `true` blindly)
- [ ] `parseInboundEmail()` returns stable `providerMessageId` for idempotency
- [ ] `sendEmail()` returns `SendResult::failure()` on error, not silent success
- [ ] `addDomain()` returns DNS records in `DomainResult`
- [ ] `parseDeliveryEvent()` maps to generic statuses: delivered, bounced, failed, complained
- [ ] Credential resolution uses `$config` parameter with fallback to `SettingService`
- [ ] Errors throw `ProviderApiException` (not `RuntimeException` or `\Exception`)
- [ ] `getTrackingSettings()` returns `active: null` + `note` for unsupported tracking features (see [Tracking Limitations](../patterns/email-provider.md#tracking-limitations))
- [ ] `checkApiHealth()` implemented for health check endpoint
- [ ] Provider registered in `DomainService::resolveProvider()`
- [ ] Provider added to `EmailProviderAccount::supportedProviders()`
- [ ] Credential fields added to `EmailProviderAccount::credentialFieldsFor()`
- [ ] Settings group added to `settings-schema.php` (if needed)
- [ ] Feature test created with mocked provider methods
- [ ] No provider-specific logic added to `ProviderManagementController`
- [ ] SSRF protection on webhook test URLs (via `UrlValidationService`)
- [ ] Webhook endpoints return 200 even on processing errors
