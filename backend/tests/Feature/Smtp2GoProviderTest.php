<?php

use App\Exceptions\Smtp2GoApiException;
use App\Models\EmailDomain;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Email\Concerns\HasEventLog;
use App\Services\Email\Concerns\HasInboundRoutes;
use App\Services\Email\Concerns\HasSuppressionManagement;
use App\Services\Email\Concerns\HasWebhookManagement;
use App\Services\Email\EmailProviderInterface;
use App\Services\Email\ProviderManagementInterface;
use App\Services\Email\Smtp2GoProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->provider = app(Smtp2GoProvider::class);
    $this->config = ['api_key' => 'test-smtp2go-key'];
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

    it('implements HasEventLog', function () {
        expect($this->provider)->toBeInstanceOf(HasEventLog::class);
    });

    it('does not implement HasWebhookManagement', function () {
        expect($this->provider)->not->toBeInstanceOf(HasWebhookManagement::class);
    });

    it('does not implement HasSuppressionManagement', function () {
        expect($this->provider)->not->toBeInstanceOf(HasSuppressionManagement::class);
    });

    it('does not implement HasInboundRoutes', function () {
        expect($this->provider)->not->toBeInstanceOf(HasInboundRoutes::class);
    });

    it('returns correct provider name', function () {
        expect($this->provider->getName())->toBe('smtp2go');
    });

    it('reports correct capabilities — only events true', function () {
        $caps = $this->provider->getCapabilities();

        expect($caps['dkim_rotation'])->toBeFalse();
        expect($caps['webhooks'])->toBeFalse();
        expect($caps['inbound_routes'])->toBeFalse();
        expect($caps['events'])->toBeTrue();
        expect($caps['suppressions'])->toBeFalse();
        expect($caps['stats'])->toBeFalse();
        expect($caps['domain_management'])->toBeFalse();
        expect($caps['dns_records'])->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// verifyWebhookSignature
// ---------------------------------------------------------------------------

describe('verifyWebhookSignature', function () {
    it('always returns true — no signature verification', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], '{"event": "delivered"}');

        expect($this->provider->verifyWebhookSignature($request))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// sendEmail
// ---------------------------------------------------------------------------

describe('sendEmail', function () {
    it('sends email with api_key in body', function () {
        Http::fake([
            'api.smtp2go.com/v3/email/send' => Http::response([
                'data' => ['email_id' => 's2g-msg-123'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $domain = EmailDomain::create([
            'user_id' => $user->id,
            'name' => 'example.com',
            'provider' => 'smtp2go',
            'provider_config' => ['api_key' => 'test-key'],
            'status' => 'active',
        ]);
        $mailbox = Mailbox::create([
            'user_id' => $user->id,
            'email_domain_id' => $domain->id,
            'address' => 'info',
            'display_name' => 'Info',
        ]);

        $result = $this->provider->sendEmail(
            $mailbox, ['user@test.com'], 'Test', '<p>Body</p>',
        );

        expect($result->success)->toBeTrue();
        expect($result->providerMessageId)->toBe('s2g-msg-123');

        Http::assertSent(function ($request) {
            return $request['api_key'] === 'test-key'
                && $request['sender'] === 'Info <info@example.com>'
                && $request['to'] === ['user@test.com']
                && $request['html_body'] === '<p>Body</p>';
        });
    });

    it('returns failure on API error', function () {
        Http::fake([
            'api.smtp2go.com/v3/email/send' => Http::response(['data' => ['error' => 'Bad']], 400),
        ]);

        $user = User::factory()->create();
        $domain = EmailDomain::create([
            'user_id' => $user->id,
            'name' => 'example.com',
            'provider' => 'smtp2go',
            'provider_config' => ['api_key' => 'test-key'],
            'status' => 'active',
        ]);
        $mailbox = Mailbox::create([
            'user_id' => $user->id,
            'email_domain_id' => $domain->id,
            'address' => 'info',
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
    it('parses multipart POST inbound email', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [
            'from' => 'sender@example.com',
            'to' => 'recipient@test.com',
            'subject' => 'Hello SMTP2GO',
            'text' => 'Plain text body',
            'html' => '<p>HTML body</p>',
            'message-id' => '<msg123@example.com>',
            'headers' => '{}',
        ]);

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->fromAddress)->toBe('sender@example.com');
        expect($parsed->subject)->toBe('Hello SMTP2GO');
        expect($parsed->bodyText)->toBe('Plain text body');
        expect($parsed->bodyHtml)->toBe('<p>HTML body</p>');
        expect($parsed->to[0]['address'])->toBe('recipient@test.com');
    });

    it('parses address list with multiple recipients', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [
            'from' => 'sender@example.com',
            'to' => 'user1@test.com, "User Two" <user2@test.com>',
            'subject' => 'Multi',
            'headers' => '{}',
        ]);

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->to)->toHaveCount(2);
        expect($parsed->to[0]['address'])->toBe('user1@test.com');
        expect($parsed->to[1]['address'])->toBe('user2@test.com');
        expect($parsed->to[1]['name'])->toBe('User Two');
    });

    it('uses sender field as fallback for from', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [
            'sender' => 'fallback@example.com',
            'to' => 'to@test.com',
            'subject' => 'Fallback',
            'headers' => '{}',
        ]);

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->fromAddress)->toBe('fallback@example.com');
    });

    it('parses JSON headers string', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [
            'from' => 'sender@example.com',
            'to' => 'to@test.com',
            'subject' => 'Headers',
            'headers' => json_encode(['X-Custom' => 'value', 'X-Another' => 'test']),
        ]);

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->headers)->toHaveKey('X-Custom');
        expect($parsed->headers['X-Custom'])->toBe('value');
    });

    it('handles empty headers gracefully', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [
            'from' => 'sender@example.com',
            'to' => 'to@test.com',
            'subject' => 'No headers',
        ]);

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->headers)->toBeArray();
    });
});

// ---------------------------------------------------------------------------
// parseDeliveryEvent
// ---------------------------------------------------------------------------

describe('parseDeliveryEvent', function () {
    it('maps delivered to delivered', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'event' => 'delivered',
            'email_id' => 'e_123',
            'recipient' => 'user@test.com',
            'timestamp' => '2026-01-01T00:00:00Z',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);

        expect($result['event_type'])->toBe('delivered');
        expect($result['provider_message_id'])->toBe('e_123');
        expect($result['recipient'])->toBe('user@test.com');
    });

    it('maps bounced to bounced', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'event' => 'bounced',
            'email_id' => 'e_456',
            'reason' => 'Mailbox full',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);

        expect($result['event_type'])->toBe('bounced');
        expect($result['error_message'])->toBe('Mailbox full');
    });

    it('maps complained to complained', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'event' => 'complained',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);
        expect($result['event_type'])->toBe('complained');
    });

    it('maps deferred to failed', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'event' => 'deferred',
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);
        expect($result['event_type'])->toBe('failed');
    });

    it('maps rejected to failed', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'event' => 'rejected',
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
    it('registers domain via POST with api_key in body', function () {
        Http::fake([
            'api.smtp2go.com/v3/domain/add' => Http::response([
                'data' => [
                    'dns_records' => [
                        ['type' => 'TXT', 'host' => '_dmarc.example.com', 'data' => 'v=DMARC1;'],
                        ['type' => 'CNAME', 'name' => 'em.example.com', 'value' => 'smtp2go.com'],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->provider->addDomain('example.com', $this->config);

        expect($result->success)->toBeTrue();
        expect($result->providerDomainId)->toBe('example.com');
        expect($result->dnsRecords)->toHaveCount(2);

        Http::assertSent(function ($request) {
            return $request['api_key'] === 'test-smtp2go-key'
                && $request['domain'] === 'example.com';
        });
    });

    it('returns failure on API error', function () {
        Http::fake([
            'api.smtp2go.com/v3/domain/add' => Http::response(['data' => ['error' => 'Invalid']], 400),
        ]);

        $result = $this->provider->addDomain('bad.com', $this->config);

        expect($result->success)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// verifyDomain
// ---------------------------------------------------------------------------

describe('verifyDomain', function () {
    it('returns verified when verified is true', function () {
        Http::fake([
            'api.smtp2go.com/v3/domain/verify' => Http::response([
                'data' => ['verified' => true],
            ], 200),
        ]);

        $result = $this->provider->verifyDomain('example.com', $this->config);

        expect($result->isVerified)->toBeTrue();
    });

    it('returns not verified when verified is false', function () {
        Http::fake([
            'api.smtp2go.com/v3/domain/verify' => Http::response([
                'data' => ['verified' => false],
            ], 200),
        ]);

        $result = $this->provider->verifyDomain('example.com', $this->config);

        expect($result->isVerified)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// configureDomainWebhook
// ---------------------------------------------------------------------------

describe('configureDomainWebhook', function () {
    it('returns false and logs info about manual config', function () {
        $result = $this->provider->configureDomainWebhook('example.com', 'https://test.com/webhook', $this->config);

        expect($result)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Management API
// ---------------------------------------------------------------------------

describe('management API', function () {
    it('checkApiHealth calls stats/email_summary', function () {
        Http::fake([
            'api.smtp2go.com/v3/stats/email_summary' => Http::response(['data' => []], 200),
        ]);

        expect($this->provider->checkApiHealth($this->config))->toBeTrue();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'stats/email_summary')
                && $request['api_key'] === 'test-smtp2go-key';
        });
    });

    it('managementRequest always uses POST', function () {
        Http::fake([
            'api.smtp2go.com/v3/test-path' => Http::response(['data' => []], 200),
        ]);

        // Even with 'get' method, SMTP2GO always POSTs
        $result = $this->provider->managementRequest('get', 'test-path', [], $this->config);

        expect($result['ok'])->toBeTrue();

        Http::assertSent(function ($request) {
            return $request->method() === 'POST';
        });
    });

    it('managementRequest merges api_key into payload', function () {
        Http::fake([
            'api.smtp2go.com/v3/test-path' => Http::response(['data' => []], 200),
        ]);

        $this->provider->managementRequest('post', 'test-path', ['extra' => 'value'], $this->config);

        Http::assertSent(function ($request) {
            return $request['api_key'] === 'test-smtp2go-key'
                && $request['extra'] === 'value';
        });
    });

    it('managementRequestOrFail throws Smtp2GoApiException on failure', function () {
        Http::fake([
            'api.smtp2go.com/v3/*' => Http::response(['data' => ['error' => 'Bad request']], 400),
        ]);

        expect(fn () => $this->provider->managementRequestOrFail('post', 'bad-path', [], $this->config))
            ->toThrow(Smtp2GoApiException::class, 'Bad request');
    });
});

// ---------------------------------------------------------------------------
// Event Log
// ---------------------------------------------------------------------------

describe('event log', function () {
    it('searches emails with sender wildcard filter', function () {
        Http::fake([
            'api.smtp2go.com/v3/email/search' => Http::response([
                'data' => ['emails' => [['id' => 'e1'], ['id' => 'e2']]],
            ], 200),
        ]);

        $result = $this->provider->getEvents('example.com', [], $this->config);

        expect($result['items'])->toHaveCount(2);
        expect($result['nextPage'])->toBeNull();

        Http::assertSent(function ($request) {
            return $request['sender'] === '*@example.com';
        });
    });

    it('includes recipient filter when provided', function () {
        Http::fake([
            'api.smtp2go.com/v3/email/search' => Http::response([
                'data' => ['emails' => []],
            ], 200),
        ]);

        $this->provider->getEvents('example.com', ['recipient' => 'user@test.com'], $this->config);

        Http::assertSent(function ($request) {
            return $request['recipients'] === 'user@test.com';
        });
    });
});
