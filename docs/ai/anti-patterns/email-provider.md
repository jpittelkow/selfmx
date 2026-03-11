# Email Provider Anti-Patterns

### Don't: Implement Concern Interfaces for Unsupported Features

If a provider doesn't natively support a capability, don't implement the interface with stub/empty methods. Report `false` in `getCapabilities()` and skip the interface entirely.

```php
// BAD — implements interface but returns empty/stub data
class NewProvider implements EmailProviderInterface, HasEventLog
{
    public function getCapabilities(): array
    {
        return ['events' => false]; // Says false but implements the interface!
    }

    public function getEvents(string $domain, array $filters = [], array $config = []): array
    {
        return ['events' => [], 'note' => 'Not supported'];
    }
}

// GOOD — don't implement the interface at all
class NewProvider implements EmailProviderInterface, ProviderManagementInterface
{
    public function getCapabilities(): array
    {
        return ['events' => false]; // No HasEventLog interface = controller won't call it
    }
}
```

**Exception**: If a provider has a *workaround* that provides meaningful data (like SES using disable/re-enable for DKIM rotation), implementing the interface with a note is acceptable.

### Don't: Access Settings Directly in Concern Methods

Concern interface methods receive credentials via the `$config` parameter. Don't bypass this by reading from `SettingService` directly — it breaks the credential hierarchy (account → domain → system).

```php
// BAD — ignores passed config, always uses system settings
public function listWebhooks(string $domain, array $config = []): array
{
    $apiKey = $this->settingService->get('newprovider', 'api_key');
    return $this->request('GET', "/domains/{$domain}/webhooks", $apiKey);
}

// GOOD — uses config with fallback
public function listWebhooks(string $domain, array $config = []): array
{
    $apiKey = $config['api_key'] ?? $this->getApiKey(); // fallback reads settings
    return $this->request('GET', "/domains/{$domain}/webhooks", $apiKey);
}
```

### Don't: Return Provider-Specific Error Codes to Frontend

The frontend interprets 401/403 as session expired. If the *provider* returns 401/403 (bad API key), map it to 502. The controller's `wrapProviderCall()` handles this, but provider code must throw `ProviderApiException` with the original status.

```php
// BAD — catches provider 401 and returns it directly
public function listWebhooks(string $domain, array $config = []): array
{
    $response = Http::get($url);
    if ($response->status() === 401) {
        throw new \RuntimeException('Unauthorized'); // No status code!
    }
}

// GOOD — throw ProviderApiException with original status
public function listWebhooks(string $domain, array $config = []): array
{
    $response = Http::get($url);
    if (! $response->successful()) {
        throw new ProviderApiException(
            message: $response->json('message', 'API error'),
            httpStatus: $response->status(),
            responseBody: $response->json() ?? [],
        );
    }
}
```

### Don't: Parse Webhooks Without Signature Verification

Always verify webhook signatures before processing. A missing or failed verification should return 403, not silently process the payload.

```php
// BAD — processes webhook without verification
public function verifyWebhookSignature(Request $request): bool
{
    return true; // "We'll add this later"
}

// GOOD — proper HMAC verification
public function verifyWebhookSignature(Request $request): bool
{
    $signature = $request->header('X-Provider-Signature');
    $computed = hash_hmac('sha256', $request->getContent(), $this->getSigningKey());
    return hash_equals($computed, $signature);
}
```

### Don't: Forget Idempotency in Webhook Handlers

Provider webhooks may retry. The `EmailWebhookController` checks `email_webhook_logs` for duplicates, but your `parseInboundEmail()` must return a stable `providerMessageId` so the dedup works.

```php
// BAD — no provider message ID, dedup can't work
return new ParsedEmail(
    // ...
    providerMessageId: null,  // Every retry creates a duplicate email!
);

// GOOD — extract stable message ID from provider payload
return new ParsedEmail(
    // ...
    providerMessageId: $payload['Message-Id'] ?? $payload['token'] ?? null,
);
```

### Don't: Silently Swallow Provider Errors in Send

`sendEmail()` must return `SendResult::failure()` with a meaningful error message, not an empty success.

```php
// BAD — returns success even when provider rejected the email
public function sendEmail(...): SendResult
{
    try {
        $response = Http::post($url, $payload);
        return SendResult::success($response->json('id'));
    } catch (\Exception $e) {
        return SendResult::success(null); // Silent failure!
    }
}

// GOOD — propagate failure with message
public function sendEmail(...): SendResult
{
    try {
        $response = Http::post($url, $payload);
        if (! $response->successful()) {
            return SendResult::failure($response->json('message', 'Send failed: ' . $response->status()));
        }
        return SendResult::success($response->json('id'));
    } catch (\Exception $e) {
        return SendResult::failure($e->getMessage());
    }
}
```

### Don't: Forget DNS Records in Domain Registration

`addDomain()` must return DNS records so the user knows what to configure. An empty `dnsRecords` array leaves users unable to verify their domain.

```php
// BAD — registers domain but doesn't return DNS records
public function addDomain(string $domain, array $config = []): DomainResult
{
    $response = $this->callApi('POST', '/domains', ['name' => $domain]);
    return DomainResult::success($domain, []); // No DNS records!
}

// GOOD — extract and normalize DNS records
public function addDomain(string $domain, array $config = []): DomainResult
{
    $response = $this->callApi('POST', '/domains', ['name' => $domain]);
    $dnsRecords = $this->extractDnsRecords($response); // TXT, CNAME, MX records
    return DomainResult::success($domain, $dnsRecords);
}
```

### Don't: Hardcode Provider API URLs

Support multiple regions (US, EU) and allow base URL to vary per provider config.

```php
// BAD — hardcoded to US region
private function getBaseUrl(): string
{
    return 'https://api.mailgun.net/v3';
}

// GOOD — region-aware
private function getBaseUrl(array $config = []): string
{
    $region = $config['region'] ?? $this->getRegion();
    return $region === 'eu'
        ? 'https://api.eu.mailgun.net/v3'
        : 'https://api.mailgun.net/v3';
}
```

### Don't: Forget SSRF Protection on Webhook URLs

When testing webhooks or configuring callback URLs, validate through `UrlValidationService`:

```php
// BAD — sends test payload to any URL (SSRF vulnerability)
public function testWebhook(string $domain, string $webhookId, string $url, array $config = []): array
{
    Http::post($url, $testPayload); // Could hit internal services!
}

// GOOD — validate URL first
public function testWebhook(string $domain, string $webhookId, string $url, array $config = []): array
{
    $validator = app(UrlValidationService::class);
    if (! $validator->isUrlSafe($url)) {
        throw new ProviderApiException('URL failed safety validation', 422);
    }
    Http::post($url, $testPayload);
}
```

### Don't: Map Capabilities Incorrectly in getCapabilities()

The capability map must exactly match which concern interfaces your provider implements. A mismatch causes runtime errors.

```php
// BAD — claims suppression support but doesn't implement HasSuppressionManagement
class NewProvider implements EmailProviderInterface, ProviderManagementInterface
{
    public function getCapabilities(): array
    {
        return ['suppressions' => true]; // Controller will try instanceof, method doesn't exist
    }
}

// GOOD — capabilities match implemented interfaces
class NewProvider implements EmailProviderInterface, ProviderManagementInterface, HasSuppressionManagement
{
    public function getCapabilities(): array
    {
        return ['suppressions' => true]; // HasSuppressionManagement is implemented
    }

    public function listBounces(...): array { /* ... */ }
    // ... all HasSuppressionManagement methods
}
```

### Don't: Forget to Register the Provider

A new provider must be registered in three places:

```php
// 1. DomainService::resolveProvider() — factory method
// 2. EmailProviderAccount::supportedProviders() — validation list
// 3. EmailProviderAccount::credentialFieldsFor() — required credentials

// Missing any of these causes silent failures or validation errors
```

### Don't: Return 4xx/5xx from Webhook Endpoints

Webhook endpoints should return 200 even when processing fails. Non-200 responses cause providers to retry, creating duplicate processing attempts.

```php
// BAD — returns 500 on processing error (provider will retry)
public function handleWebhook(Request $request): JsonResponse
{
    $parsed = $provider->parseInboundEmail($request);
    $this->emailService->processInboundEmail($parsed); // Throws on error
    return response()->json(['status' => 'ok']);
}

// GOOD — always return 200, log the error
public function handleWebhook(Request $request): JsonResponse
{
    try {
        $parsed = $provider->parseInboundEmail($request);
        $this->emailService->processInboundEmail($parsed);
    } catch (\Exception $e) {
        Log::error('Webhook processing failed', ['error' => $e->getMessage()]);
    }
    return response()->json(['status' => 'ok'], 200);
}
```

### Don't: Use Provider-Specific Logic in the Management Controller

The `ProviderManagementController` is provider-agnostic. Never add `if ($provider->getName() === 'mailgun')` branches.

```php
// BAD — provider-specific logic in controller
public function listWebhooks(Request $request, int $domainId): JsonResponse
{
    [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);

    if ($provider->getName() === 'mailgun') {
        // Mailgun-specific handling
    } elseif ($provider->getName() === 'ses') {
        // SES-specific handling
    }
}

// GOOD — provider handles its own differences behind the interface
public function listWebhooks(Request $request, int $domainId): JsonResponse
{
    [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);

    if (! $provider instanceof HasWebhookManagement) {
        return $this->errorResponse('Not supported', 422);
    }

    $config = $this->domainService->getCredentialsForDomain($domain);
    $result = $provider->listWebhooks($domain->name, $config);
    return $this->dataResponse(['data' => $result]);
}
```
