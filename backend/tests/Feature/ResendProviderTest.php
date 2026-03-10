<?php

use App\Exceptions\ResendApiException;
use App\Models\EmailDomain;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Email\Concerns\HasEventLog;
use App\Services\Email\Concerns\HasInboundRoutes;
use App\Services\Email\Concerns\HasSuppressionManagement;
use App\Services\Email\Concerns\HasWebhookManagement;
use App\Services\Email\EmailProviderInterface;
use App\Services\Email\ProviderManagementInterface;
use App\Services\Email\ResendProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->provider = app(ResendProvider::class);
    $this->config = ['api_key' => 'test-resend-key'];
});

// ---------------------------------------------------------------------------
// Interface contracts
// ---------------------------------------------------------------------------

describe('interface contracts', function () {
    it('implements EmailProviderInterface', function () {
        expect($this->provider)->toBeInstanceOf(EmailProviderInterface::class);
    });

    it('implements ProviderManagementInterface', function () {
        expect($this->provider)->toBeInstanceOf(ProviderManagementInterface::class);
    });

    it('implements HasWebhookManagement', function () {
        expect($this->provider)->toBeInstanceOf(HasWebhookManagement::class);
    });

    it('implements HasEventLog', function () {
        expect($this->provider)->toBeInstanceOf(HasEventLog::class);
    });

    it('does not implement HasSuppressionManagement', function () {
        expect($this->provider)->not->toBeInstanceOf(HasSuppressionManagement::class);
    });

    it('does not implement HasInboundRoutes', function () {
        expect($this->provider)->not->toBeInstanceOf(HasInboundRoutes::class);
    });

    it('returns correct provider name', function () {
        expect($this->provider->getName())->toBe('resend');
    });

    it('reports correct capabilities', function () {
        $caps = $this->provider->getCapabilities();

        expect($caps['dkim_rotation'])->toBeFalse();
        expect($caps['webhooks'])->toBeTrue();
        expect($caps['inbound_routes'])->toBeFalse();
        expect($caps['events'])->toBeTrue();
        expect($caps['suppressions'])->toBeFalse();
        expect($caps['stats'])->toBeFalse();
        expect($caps['domain_management'])->toBeTrue();
        expect($caps['dns_records'])->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// verifyWebhookSignature
// ---------------------------------------------------------------------------

describe('verifyWebhookSignature', function () {
    it('accepts when no signing secret is configured', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], '{"test": true}');

        expect($this->provider->verifyWebhookSignature($request))->toBeTrue();
    });

    it('rejects when svix headers are missing', function () {
        config(['settings-schema.resend' => ['webhook_signing_secret' => []]]);
        app(\App\Services\SettingService::class)->set('resend', 'webhook_signing_secret', 'whsec_dGVzdHNlY3JldA==');

        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], '{"test": true}');

        expect($this->provider->verifyWebhookSignature($request))->toBeFalse();
    });

    it('verifies valid svix signature', function () {
        config(['settings-schema.resend' => ['webhook_signing_secret' => []]]);
        $secret = base64_encode('testsecret');
        app(\App\Services\SettingService::class)->set('resend', 'webhook_signing_secret', "whsec_{$secret}");

        $body = '{"test": true}';
        $svixId = 'msg_test123';
        $svixTimestamp = (string) time();
        $signedContent = "{$svixId}.{$svixTimestamp}.{$body}";
        $computed = base64_encode(hash_hmac('sha256', $signedContent, 'testsecret', true));
        $svixSignature = "v1,{$computed}";

        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_SVIX_ID' => $svixId,
            'HTTP_SVIX_TIMESTAMP' => $svixTimestamp,
            'HTTP_SVIX_SIGNATURE' => $svixSignature,
        ], $body);
        $request->headers->set('Content-Type', 'application/json');

        expect($this->provider->verifyWebhookSignature($request))->toBeTrue();
    });

    it('rejects invalid svix signature', function () {
        config(['settings-schema.resend' => ['webhook_signing_secret' => []]]);
        $secret = base64_encode('testsecret');
        app(\App\Services\SettingService::class)->set('resend', 'webhook_signing_secret', "whsec_{$secret}");

        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_SVIX_ID' => 'msg_test123',
            'HTTP_SVIX_TIMESTAMP' => (string) time(),
            'HTTP_SVIX_SIGNATURE' => 'v1,invalidsignature',
        ], '{"test": true}');

        expect($this->provider->verifyWebhookSignature($request))->toBeFalse();
    });

    it('checks multiple signatures separated by spaces', function () {
        config(['settings-schema.resend' => ['webhook_signing_secret' => []]]);
        $secret = base64_encode('testsecret');
        app(\App\Services\SettingService::class)->set('resend', 'webhook_signing_secret', "whsec_{$secret}");

        $body = '{"test": true}';
        $svixId = 'msg_multi';
        $svixTimestamp = (string) time();
        $signedContent = "{$svixId}.{$svixTimestamp}.{$body}";
        $computed = base64_encode(hash_hmac('sha256', $signedContent, 'testsecret', true));
        $validSig = "v1,{$computed}";

        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_SVIX_ID' => $svixId,
            'HTTP_SVIX_TIMESTAMP' => $svixTimestamp,
            'HTTP_SVIX_SIGNATURE' => "v1,badsig {$validSig}",
        ], $body);

        expect($this->provider->verifyWebhookSignature($request))->toBeTrue();
    });

    it('rejects webhook with stale timestamp (replay protection)', function () {
        config(['settings-schema.resend' => ['webhook_signing_secret' => []]]);
        $secret = base64_encode('testsecret');
        app(\App\Services\SettingService::class)->set('resend', 'webhook_signing_secret', "whsec_{$secret}");

        $body = '{"test": true}';
        $svixId = 'msg_replay';
        $svixTimestamp = (string) (time() - 600); // 10 minutes ago
        $signedContent = "{$svixId}.{$svixTimestamp}.{$body}";
        $computed = base64_encode(hash_hmac('sha256', $signedContent, 'testsecret', true));
        $svixSignature = "v1,{$computed}";

        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_SVIX_ID' => $svixId,
            'HTTP_SVIX_TIMESTAMP' => $svixTimestamp,
            'HTTP_SVIX_SIGNATURE' => $svixSignature,
        ], $body);

        expect($this->provider->verifyWebhookSignature($request))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// sendEmail
// ---------------------------------------------------------------------------

describe('sendEmail', function () {
    it('sends email with correct payload', function () {
        Http::fake([
            'api.resend.com/emails' => Http::response(['id' => 'msg-resend-123'], 200),
        ]);

        $user = User::factory()->create();
        $domain = EmailDomain::create([
            'user_id' => $user->id,
            'name' => 'example.com',
            'provider' => 'resend',
            'provider_config' => ['api_key' => 'test-key'],
            'status' => 'active',
        ]);
        $mailbox = Mailbox::create([
            'user_id' => $user->id,
            'email_domain_id' => $domain->id,
            'address' => 'hello',
            'display_name' => 'Hello Bot',
        ]);

        $result = $this->provider->sendEmail(
            $mailbox, ['user@test.com'], 'Test Subject', '<p>Body</p>',
        );

        expect($result->success)->toBeTrue();
        expect($result->providerMessageId)->toBe('msg-resend-123');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.resend.com/emails'
                && $request['from'] === 'Hello Bot <hello@example.com>'
                && $request['to'] === ['user@test.com']
                && $request['subject'] === 'Test Subject'
                && $request['html'] === '<p>Body</p>';
        });
    });

    it('returns failure on API error', function () {
        Http::fake([
            'api.resend.com/emails' => Http::response(['message' => 'Validation error'], 422),
        ]);

        $user = User::factory()->create();
        $domain = EmailDomain::create([
            'user_id' => $user->id,
            'name' => 'example.com',
            'provider' => 'resend',
            'provider_config' => ['api_key' => 'test-key'],
            'status' => 'active',
        ]);
        $mailbox = Mailbox::create([
            'user_id' => $user->id,
            'email_domain_id' => $domain->id,
            'address' => 'hello',
        ]);

        $result = $this->provider->sendEmail(
            $mailbox, ['user@test.com'], 'Subject', '<p>Body</p>',
        );

        expect($result->success)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// parseInboundEmail
// ---------------------------------------------------------------------------

describe('parseInboundEmail', function () {
    it('parses basic inbound email', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'from' => 'sender@example.com',
            'to' => ['recipient@test.com'],
            'subject' => 'Hello',
            'text' => 'Plain text',
            'html' => '<p>HTML body</p>',
            'headers' => ['message-id' => '<abc@example.com>'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->fromAddress)->toBe('sender@example.com');
        expect($parsed->fromName)->toBeNull();
        expect($parsed->subject)->toBe('Hello');
        expect($parsed->bodyText)->toBe('Plain text');
        expect($parsed->bodyHtml)->toBe('<p>HTML body</p>');
        expect($parsed->to[0]['address'])->toBe('recipient@test.com');
        expect($parsed->messageId)->toBe('<abc@example.com>');
        expect($parsed->recipientAddress)->toBe('recipient@test.com');
    });

    it('parses email address with display name', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'from' => 'John Doe <john@example.com>',
            'to' => ['"Jane Smith" <jane@test.com>'],
            'subject' => 'Test',
            'headers' => [],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->fromAddress)->toBe('john@example.com');
        expect($parsed->fromName)->toBe('John Doe');
        expect($parsed->to[0]['address'])->toBe('jane@test.com');
        expect($parsed->to[0]['name'])->toBe('Jane Smith');
    });

    it('parses attachments with base64 content', function () {
        $content = base64_encode('file content');
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'from' => 'sender@example.com',
            'to' => ['to@test.com'],
            'subject' => 'With attachment',
            'headers' => [],
            'attachments' => [
                ['filename' => 'test.txt', 'content_type' => 'text/plain', 'content' => $content],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->attachments)->toHaveCount(1);
        expect($parsed->attachments[0]['filename'])->toBe('test.txt');
        expect($parsed->attachments[0]['mimeType'])->toBe('text/plain');
        expect($parsed->attachments[0]['content'])->toBe('file content');
    });

    it('handles missing optional fields', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'from' => 'sender@example.com',
            'to' => ['to@test.com'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->subject)->toBe('');
        expect($parsed->bodyText)->toBe('');
        expect($parsed->bodyHtml)->toBe('');
        expect($parsed->attachments)->toBeEmpty();
        expect($parsed->cc)->toBeEmpty();
        expect($parsed->bcc)->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// parseDeliveryEvent
// ---------------------------------------------------------------------------

describe('parseDeliveryEvent', function () {
    it('maps email.delivered to delivered', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'email.delivered',
            'data' => ['email_id' => 'msg-123', 'to' => ['user@test.com'], 'created_at' => '2026-01-01T00:00:00Z'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);

        expect($result['event_type'])->toBe('delivered');
        expect($result['provider_message_id'])->toBe('msg-123');
        expect($result['recipient'])->toBe('user@test.com');
    });

    it('maps email.bounced to bounced with error', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'email.bounced',
            'data' => ['email_id' => 'msg-456', 'to' => ['bad@test.com'], 'bounce' => ['message' => 'Mailbox not found']],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);

        expect($result['event_type'])->toBe('bounced');
        expect($result['error_message'])->toBe('Mailbox not found');
    });

    it('maps email.complained to complained', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'email.complained',
            'data' => ['email_id' => 'msg-789'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);
        expect($result['event_type'])->toBe('complained');
    });

    it('maps email.sent to queued', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'email.sent',
            'data' => ['email_id' => 'msg-queued'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);
        expect($result['event_type'])->toBe('queued');
    });

    it('maps email.delivery_delayed to failed', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'email.delivery_delayed',
            'data' => ['email_id' => 'msg-delayed'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);
        expect($result['event_type'])->toBe('failed');
    });
});

// ---------------------------------------------------------------------------
// addDomain
// ---------------------------------------------------------------------------

describe('addDomain', function () {
    it('registers domain with provider', function () {
        Http::fake([
            'api.resend.com/domains' => Http::response([
                'id' => 'dom_123',
                'records' => [
                    ['type' => 'TXT', 'name' => 'resend._domainkey', 'value' => 'v=DKIM1;', 'status' => 'verified', 'record' => 'dkim'],
                ],
            ], 201),
        ]);

        $result = $this->provider->addDomain('example.com', $this->config);

        expect($result->success)->toBeTrue();
        expect($result->providerDomainId)->toBe('dom_123');
        expect($result->dnsRecords)->toHaveCount(1);
        expect($result->dnsRecords[0]['type'])->toBe('TXT');
        expect($result->dnsRecords[0]['valid'])->toBe('valid');
    });

    it('returns failure on API error', function () {
        Http::fake([
            'api.resend.com/domains' => Http::response(['message' => 'Invalid domain'], 422),
        ]);

        $result = $this->provider->addDomain('bad-domain', $this->config);

        expect($result->success)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// verifyDomain
// ---------------------------------------------------------------------------

describe('verifyDomain', function () {
    it('returns verified when status is verified', function () {
        Http::fake([
            'api.resend.com/domains' => Http::response([
                'data' => [
                    ['name' => 'example.com', 'status' => 'verified', 'records' => []],
                ],
            ], 200),
        ]);

        $result = $this->provider->verifyDomain('example.com', $this->config);

        expect($result->isVerified)->toBeTrue();
    });

    it('returns not verified when status is pending', function () {
        Http::fake([
            'api.resend.com/domains' => Http::response([
                'data' => [
                    ['name' => 'example.com', 'status' => 'pending', 'records' => []],
                ],
            ], 200),
        ]);

        $result = $this->provider->verifyDomain('example.com', $this->config);

        expect($result->isVerified)->toBeFalse();
    });

    it('returns not verified when domain not found', function () {
        Http::fake([
            'api.resend.com/domains' => Http::response(['data' => []], 200),
        ]);

        $result = $this->provider->verifyDomain('missing.com', $this->config);

        expect($result->isVerified)->toBeFalse();
        expect($result->error)->toBe('Domain not found in Resend');
    });
});

// ---------------------------------------------------------------------------
// configureDomainWebhook
// ---------------------------------------------------------------------------

describe('configureDomainWebhook', function () {
    it('creates webhook for email.received events', function () {
        Http::fake([
            'api.resend.com/webhooks' => Http::response(['id' => 'wh_123'], 201),
        ]);

        $result = $this->provider->configureDomainWebhook('example.com', 'https://app.test/webhook', $this->config);

        expect($result)->toBeTrue();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/webhooks')
                && $request['endpoint'] === 'https://app.test/webhook'
                && $request['events'] === ['email.received'];
        });
    });

    it('returns false on API error', function () {
        Http::fake([
            'api.resend.com/webhooks' => Http::response(['message' => 'Error'], 500),
        ]);

        $result = $this->provider->configureDomainWebhook('example.com', 'https://app.test/webhook', $this->config);

        expect($result)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Management API
// ---------------------------------------------------------------------------

describe('management API', function () {
    it('checkApiHealth returns true on success', function () {
        Http::fake([
            'api.resend.com/domains' => Http::response(['data' => []], 200),
        ]);

        expect($this->provider->checkApiHealth($this->config))->toBeTrue();
    });

    it('checkApiHealth returns false on failure', function () {
        Http::fake([
            'api.resend.com/domains' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        expect($this->provider->checkApiHealth($this->config))->toBeFalse();
    });

    it('managementRequestOrFail throws ResendApiException on failure', function () {
        Http::fake([
            'api.resend.com/*' => Http::response(['message' => 'Not found'], 404),
        ]);

        expect(fn () => $this->provider->managementRequestOrFail('get', 'domains/bad', [], $this->config))
            ->toThrow(ResendApiException::class, 'Not found');
    });
});

// ---------------------------------------------------------------------------
// Webhooks
// ---------------------------------------------------------------------------

describe('webhook management', function () {
    it('lists webhooks', function () {
        Http::fake([
            'api.resend.com/webhooks' => Http::response([
                'data' => [['id' => 'wh_1', 'endpoint' => 'https://test.com/hook']],
            ], 200),
        ]);

        $result = $this->provider->listWebhooks('example.com', $this->config);

        expect($result)->toHaveCount(1);
        expect($result[0]['id'])->toBe('wh_1');
    });

    it('creates webhook with mapped event name', function () {
        Http::fake([
            'api.resend.com/webhooks' => Http::response(['id' => 'wh_new', 'endpoint' => 'https://test.com'], 201),
        ]);

        $result = $this->provider->createWebhook('example.com', 'delivered', 'https://test.com', $this->config);

        expect($result)->toHaveKey('id');

        Http::assertSent(function ($request) {
            return $request['events'] === ['email.delivered'];
        });
    });

    it('updates webhook endpoint', function () {
        Http::fake([
            'api.resend.com/webhooks/wh_1' => Http::response(['id' => 'wh_1'], 200),
        ]);

        $result = $this->provider->updateWebhook('example.com', 'wh_1', 'https://new-url.com', $this->config);

        expect($result)->toHaveKey('id');

        Http::assertSent(function ($request) {
            return $request['endpoint'] === 'https://new-url.com';
        });
    });

    it('deletes webhook', function () {
        Http::fake([
            'api.resend.com/webhooks/wh_1' => Http::response([], 200),
        ]);

        $result = $this->provider->deleteWebhook('example.com', 'wh_1', $this->config);

        expect($result)->toBeArray();
    });
});

// ---------------------------------------------------------------------------
// Event Log
// ---------------------------------------------------------------------------

describe('event log', function () {
    it('returns emails as events', function () {
        Http::fake([
            'api.resend.com/emails*' => Http::response([
                'data' => [
                    ['id' => 'email_1', 'subject' => 'Test'],
                    ['id' => 'email_2', 'subject' => 'Another'],
                ],
            ], 200),
        ]);

        $result = $this->provider->getEvents('example.com', [], $this->config);

        expect($result['items'])->toHaveCount(2);
        expect($result['nextPage'])->toBeNull();
    });

    it('passes limit filter', function () {
        Http::fake([
            'api.resend.com/emails*' => Http::response(['data' => []], 200),
        ]);

        $this->provider->getEvents('example.com', ['limit' => 10], $this->config);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'limit=10');
        });
    });
});
