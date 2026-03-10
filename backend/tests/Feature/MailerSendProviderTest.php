<?php

use App\Exceptions\MailerSendApiException;
use App\Models\EmailDomain;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Email\Concerns\HasDkimManagement;
use App\Services\Email\Concerns\HasDeliveryStats;
use App\Services\Email\Concerns\HasEventLog;
use App\Services\Email\Concerns\HasInboundRoutes;
use App\Services\Email\Concerns\HasSuppressionManagement;
use App\Services\Email\Concerns\HasWebhookManagement;
use App\Services\Email\EmailProviderInterface;
use App\Services\Email\MailerSendProvider;
use App\Services\Email\ProviderManagementInterface;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->provider = app(MailerSendProvider::class);
    $this->config = ['api_key' => 'test-mailersend-key'];
});

/** Fake the domains endpoint used by findDomainId, returning one domain. */
function fakeDomainLookup(string $domainName = 'example.com', string $domainId = 'dom_abc'): void
{
    Http::fake([
        'api.mailersend.com/v1/domains' => Http::response([
            'data' => [['id' => $domainId, 'name' => $domainName, 'is_verified' => true]],
        ], 200),
    ]);
}

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

    it('implements HasSuppressionManagement', function () {
        expect($this->provider)->toBeInstanceOf(HasSuppressionManagement::class);
    });

    it('implements HasInboundRoutes', function () {
        expect($this->provider)->toBeInstanceOf(HasInboundRoutes::class);
    });

    it('does not implement HasDkimManagement', function () {
        expect($this->provider)->not->toBeInstanceOf(HasDkimManagement::class);
    });

    it('does not implement HasDeliveryStats', function () {
        expect($this->provider)->not->toBeInstanceOf(HasDeliveryStats::class);
    });

    it('returns correct provider name', function () {
        expect($this->provider->getName())->toBe('mailersend');
    });

    it('reports correct capabilities', function () {
        $caps = $this->provider->getCapabilities();

        expect($caps['dkim_rotation'])->toBeFalse();
        expect($caps['webhooks'])->toBeTrue();
        expect($caps['inbound_routes'])->toBeTrue();
        expect($caps['events'])->toBeTrue();
        expect($caps['suppressions'])->toBeTrue();
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

    it('rejects when Signature header is missing', function () {
        config(['settings-schema.mailersend' => ['webhook_signing_secret' => []]]);
        app(\App\Services\SettingService::class)->set('mailersend', 'webhook_signing_secret', 'test-secret');

        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], '{"test": true}');

        expect($this->provider->verifyWebhookSignature($request))->toBeFalse();
    });

    it('verifies valid HMAC-SHA256 signature', function () {
        config(['settings-schema.mailersend' => ['webhook_signing_secret' => []]]);
        $secret = 'test-webhook-secret';
        app(\App\Services\SettingService::class)->set('mailersend', 'webhook_signing_secret', $secret);

        $body = '{"test": true}';
        $signature = hash_hmac('sha256', $body, $secret);

        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_SIGNATURE' => $signature,
        ], $body);

        expect($this->provider->verifyWebhookSignature($request))->toBeTrue();
    });

    it('rejects invalid signature', function () {
        config(['settings-schema.mailersend' => ['webhook_signing_secret' => []]]);
        app(\App\Services\SettingService::class)->set('mailersend', 'webhook_signing_secret', 'real-secret');

        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_SIGNATURE' => 'invalid-sig',
        ], '{"test": true}');

        expect($this->provider->verifyWebhookSignature($request))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// sendEmail
// ---------------------------------------------------------------------------

describe('sendEmail', function () {
    it('sends email with structured from/to payload', function () {
        Http::fake([
            'api.mailersend.com/v1/email' => Http::response(['x_message_id' => 'ms-msg-123'], 202),
        ]);

        $user = User::factory()->create();
        $domain = EmailDomain::create([
            'user_id' => $user->id,
            'name' => 'example.com',
            'provider' => 'mailersend',
            'provider_config' => ['api_key' => 'test-key'],
            'status' => 'active',
        ]);
        $mailbox = Mailbox::create([
            'user_id' => $user->id,
            'email_domain_id' => $domain->id,
            'address' => 'support',
            'display_name' => 'Support Team',
        ]);

        $result = $this->provider->sendEmail(
            $mailbox, ['user@test.com'], 'Test Subject', '<p>Body</p>',
        );

        expect($result->success)->toBeTrue();
        expect($result->providerMessageId)->toBe('ms-msg-123');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mailersend.com/v1/email'
                && $request['from']['email'] === 'support@example.com'
                && $request['from']['name'] === 'Support Team'
                && $request['to'][0]['email'] === 'user@test.com'
                && $request['subject'] === 'Test Subject';
        });
    });

    it('returns failure on API error', function () {
        Http::fake([
            'api.mailersend.com/v1/email' => Http::response(['message' => 'Validation failed'], 422),
        ]);

        $user = User::factory()->create();
        $domain = EmailDomain::create([
            'user_id' => $user->id,
            'name' => 'example.com',
            'provider' => 'mailersend',
            'provider_config' => ['api_key' => 'test-key'],
            'status' => 'active',
        ]);
        $mailbox = Mailbox::create([
            'user_id' => $user->id,
            'email_domain_id' => $domain->id,
            'address' => 'support',
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
            'subject' => 'Hello MailerSend',
            'text' => 'Plain text',
            'html' => '<p>HTML</p>',
            'headers' => [
                ['name' => 'Message-ID', 'value' => '<msg@example.com>'],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->fromAddress)->toBe('sender@example.com');
        expect($parsed->subject)->toBe('Hello MailerSend');
        expect($parsed->bodyText)->toBe('Plain text');
        expect($parsed->bodyHtml)->toBe('<p>HTML</p>');
        expect($parsed->to[0]['address'])->toBe('recipient@test.com');
        expect($parsed->messageId)->toBe('<msg@example.com>');
    });

    it('parses headers from name/value pairs', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'from' => 'sender@example.com',
            'to' => ['to@test.com'],
            'subject' => 'Headers test',
            'headers' => [
                ['name' => 'X-Custom', 'value' => 'custom-value'],
                ['name' => 'In-Reply-To', 'value' => '<reply@example.com>'],
                ['name' => 'References', 'value' => '<ref@example.com>'],
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->headers)->toHaveKey('X-Custom');
        expect($parsed->headers['X-Custom'])->toBe('custom-value');
        expect($parsed->inReplyTo)->toBe('<reply@example.com>');
        expect($parsed->references)->toBe('<ref@example.com>');
    });

    it('parses email with display name', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'from' => 'John Doe <john@example.com>',
            'to' => ['"Jane" <jane@test.com>'],
            'subject' => 'Names',
            'headers' => [],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $parsed = $this->provider->parseInboundEmail($request);

        expect($parsed->fromAddress)->toBe('john@example.com');
        expect($parsed->fromName)->toBe('John Doe');
        expect($parsed->to[0]['name'])->toBe('Jane');
    });
});

// ---------------------------------------------------------------------------
// parseDeliveryEvent
// ---------------------------------------------------------------------------

describe('parseDeliveryEvent', function () {
    it('maps activity.delivered to delivered', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'activity.delivered',
            'data' => [
                'message_id' => 'msg-123',
                'email' => ['recipient' => ['email' => 'user@test.com']],
                'timestamp' => '2026-01-01T00:00:00Z',
            ],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);

        expect($result['event_type'])->toBe('delivered');
        expect($result['provider_message_id'])->toBe('msg-123');
        expect($result['recipient'])->toBe('user@test.com');
    });

    it('maps activity.hard_bounced to bounced', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'activity.hard_bounced',
            'data' => ['message_id' => 'msg-bounce', 'morph' => ['reason' => 'Mailbox not found']],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);

        expect($result['event_type'])->toBe('bounced');
        expect($result['error_message'])->toBe('Mailbox not found');
    });

    it('maps activity.soft_bounced to failed', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'activity.soft_bounced',
            'data' => ['message_id' => 'msg-soft'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);
        expect($result['event_type'])->toBe('failed');
    });

    it('maps activity.spam_complaint to complained', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'activity.spam_complaint',
            'data' => ['message_id' => 'msg-spam'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);
        expect($result['event_type'])->toBe('complained');
    });

    it('maps activity.sent to queued', function () {
        $request = Illuminate\Http\Request::create('/webhook', 'POST', [], [], [], [], json_encode([
            'type' => 'activity.sent',
            'data' => ['message_id' => 'msg-sent'],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $result = $this->provider->parseDeliveryEvent($request);
        expect($result['event_type'])->toBe('queued');
    });
});

// ---------------------------------------------------------------------------
// addDomain
// ---------------------------------------------------------------------------

describe('addDomain', function () {
    it('registers domain and fetches DNS records', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => ['id' => 'dom_new', 'name' => 'example.com'],
            ], 201),
            'api.mailersend.com/v1/domains/dom_new/dns-records' => Http::response([
                'data' => [
                    ['type' => 'TXT', 'hostname' => '_dmarc.example.com', 'value' => 'v=DMARC1;', 'status' => 'valid', 'purpose' => 'authentication'],
                ],
            ], 200),
        ]);

        $result = $this->provider->addDomain('example.com', $this->config);

        expect($result->success)->toBeTrue();
        expect($result->providerDomainId)->toBe('dom_new');
        expect($result->dnsRecords)->toHaveCount(1);
        expect($result->dnsRecords[0]['valid'])->toBe('valid');
    });

    it('returns failure on API error', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response(['message' => 'Validation error'], 422),
        ]);

        $result = $this->provider->addDomain('bad.com', $this->config);

        expect($result->success)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// verifyDomain
// ---------------------------------------------------------------------------

describe('verifyDomain', function () {
    it('returns verified when is_verified is true', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com', 'is_verified' => true]],
            ], 200),
            'api.mailersend.com/v1/domains/dom_1/dns-records' => Http::response(['data' => []], 200),
        ]);

        $result = $this->provider->verifyDomain('example.com', $this->config);

        expect($result->isVerified)->toBeTrue();
    });

    it('returns not verified when is_verified is false', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com', 'is_verified' => false]],
            ], 200),
            'api.mailersend.com/v1/domains/dom_1/dns-records' => Http::response(['data' => []], 200),
        ]);

        $result = $this->provider->verifyDomain('example.com', $this->config);

        expect($result->isVerified)->toBeFalse();
    });

    it('returns not found when domain missing from list', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response(['data' => []], 200),
        ]);

        $result = $this->provider->verifyDomain('missing.com', $this->config);

        expect($result->isVerified)->toBeFalse();
        expect($result->error)->toBe('Domain not found in MailerSend');
    });
});

// ---------------------------------------------------------------------------
// configureDomainWebhook
// ---------------------------------------------------------------------------

describe('configureDomainWebhook', function () {
    it('creates inbound route with webhook forward', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com']],
            ], 200),
            'api.mailersend.com/v1/inbound' => Http::response(['data' => ['id' => 'inb_1']], 201),
        ]);

        $result = $this->provider->configureDomainWebhook('example.com', 'https://app.test/webhook', $this->config);

        expect($result)->toBeTrue();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/inbound') || $request->method() !== 'POST') {
                return false;
            }
            return $request['domain_id'] === 'dom_1'
                && $request['forwards'][0]['type'] === 'webhook'
                && $request['forwards'][0]['value'] === 'https://app.test/webhook';
        });
    });

    it('returns false when domain not found', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response(['data' => []], 200),
        ]);

        $result = $this->provider->configureDomainWebhook('unknown.com', 'https://app.test/webhook', $this->config);

        expect($result)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Management API
// ---------------------------------------------------------------------------

describe('management API', function () {
    it('checkApiHealth returns true on success', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response(['data' => []], 200),
        ]);

        expect($this->provider->checkApiHealth($this->config))->toBeTrue();
    });

    it('checkApiHealth returns false on failure', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        expect($this->provider->checkApiHealth($this->config))->toBeFalse();
    });

    it('managementRequestOrFail throws MailerSendApiException', function () {
        Http::fake([
            'api.mailersend.com/v1/*' => Http::response(['message' => 'Server error'], 500),
        ]);

        expect(fn () => $this->provider->managementRequestOrFail('get', 'bad', [], $this->config))
            ->toThrow(MailerSendApiException::class, 'Server error');
    });
});

// ---------------------------------------------------------------------------
// Webhook management
// ---------------------------------------------------------------------------

describe('webhook management', function () {
    it('lists webhooks filtered by domain', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com']],
            ], 200),
            'api.mailersend.com/v1/webhooks*' => Http::response([
                'data' => [['id' => 'wh_1', 'url' => 'https://test.com']],
            ], 200),
        ]);

        $result = $this->provider->listWebhooks('example.com', $this->config);

        expect($result)->toHaveCount(1);
        expect($result[0]['id'])->toBe('wh_1');
    });

    it('creates webhook with domain ID and mapped event', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com']],
            ], 200),
            'api.mailersend.com/v1/webhooks' => Http::response([
                'data' => ['id' => 'wh_new'],
            ], 201),
        ]);

        $result = $this->provider->createWebhook('example.com', 'delivered', 'https://test.com/hook', $this->config);

        expect($result)->toHaveKey('data');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/webhooks') || $request->method() !== 'POST') {
                return false;
            }
            return $request['domain_id'] === 'dom_1'
                && $request['events'] === ['activity.delivered'];
        });
    });

    it('throws when domain not found for webhook creation', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response(['data' => []], 200),
        ]);

        expect(fn () => $this->provider->createWebhook('missing.com', 'delivered', 'https://test.com', $this->config))
            ->toThrow(MailerSendApiException::class);
    });

    it('updates webhook', function () {
        Http::fake([
            'api.mailersend.com/v1/webhooks/wh_1' => Http::response(['data' => ['id' => 'wh_1']], 200),
        ]);

        $result = $this->provider->updateWebhook('example.com', 'wh_1', 'https://new.com/hook', $this->config);

        expect($result)->toHaveKey('data');

        Http::assertSent(function ($request) {
            return $request['url'] === 'https://new.com/hook' && $request['enabled'] === true;
        });
    });

    it('deletes webhook', function () {
        Http::fake([
            'api.mailersend.com/v1/webhooks/wh_1' => Http::response([], 200),
        ]);

        $result = $this->provider->deleteWebhook('example.com', 'wh_1', $this->config);

        expect($result)->toBeArray();
    });
});

// ---------------------------------------------------------------------------
// Event log
// ---------------------------------------------------------------------------

describe('event log', function () {
    it('returns events for domain', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com']],
            ], 200),
            'api.mailersend.com/v1/activity/dom_1*' => Http::response([
                'data' => [['id' => 'evt_1'], ['id' => 'evt_2']],
                'links' => ['next' => 'https://api.mailersend.com/v1/activity/dom_1?page=2'],
            ], 200),
        ]);

        $result = $this->provider->getEvents('example.com', [], $this->config);

        expect($result['items'])->toHaveCount(2);
        expect($result['nextPage'])->not->toBeNull();
    });

    it('returns empty when domain not found', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response(['data' => []], 200),
        ]);

        $result = $this->provider->getEvents('unknown.com', [], $this->config);

        expect($result['items'])->toBeEmpty();
        expect($result['nextPage'])->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Suppressions
// ---------------------------------------------------------------------------

describe('suppressions', function () {
    it('lists bounces for domain', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com']],
            ], 200),
            'api.mailersend.com/v1/suppressions/hard-bounces*' => Http::response([
                'data' => [['id' => 'b_1', 'recipient' => 'bad@test.com']],
                'links' => ['next' => null],
            ], 200),
        ]);

        $result = $this->provider->listBounces('example.com', 25, null, $this->config);

        expect($result['items'])->toHaveCount(1);
        expect($result['items'][0]['recipient'])->toBe('bad@test.com');
    });

    it('lists complaints for domain', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com']],
            ], 200),
            'api.mailersend.com/v1/suppressions/spam-complaints*' => Http::response([
                'data' => [['id' => 'c_1', 'recipient' => 'spam@test.com']],
                'links' => ['next' => null],
            ], 200),
        ]);

        $result = $this->provider->listComplaints('example.com', 25, null, $this->config);

        expect($result['items'])->toHaveCount(1);
    });

    it('lists unsubscribes for domain', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com']],
            ], 200),
            'api.mailersend.com/v1/suppressions/unsubscribes*' => Http::response([
                'data' => [['id' => 'u_1', 'recipient' => 'unsub@test.com']],
                'links' => ['next' => null],
            ], 200),
        ]);

        $result = $this->provider->listUnsubscribes('example.com', 25, null, $this->config);

        expect($result['items'])->toHaveCount(1);
    });

    it('deletes bounce entry', function () {
        Http::fake([
            'api.mailersend.com/v1/suppressions/hard-bounces' => Http::response([], 200),
        ]);

        $result = $this->provider->deleteBounce('example.com', 'bounce_id', $this->config);

        expect($result)->toBeTrue();
    });

    it('deletes complaint entry', function () {
        Http::fake([
            'api.mailersend.com/v1/suppressions/spam-complaints' => Http::response([], 200),
        ]);

        $result = $this->provider->deleteComplaint('example.com', 'complaint_id', $this->config);

        expect($result)->toBeTrue();
    });

    it('deletes unsubscribe entry', function () {
        Http::fake([
            'api.mailersend.com/v1/suppressions/unsubscribes' => Http::response([], 200),
        ]);

        $result = $this->provider->deleteUnsubscribe('example.com', 'unsub_id', null, $this->config);

        expect($result)->toBeTrue();
    });

    it('checks suppression — found in bounces', function () {
        Http::fake([
            'api.mailersend.com/v1/suppressions/hard-bounces*' => Http::response([
                'data' => [['recipient' => 'bad@test.com']],
            ], 200),
        ]);

        $result = $this->provider->checkSuppression('example.com', 'bad@test.com', $this->config);

        expect($result['suppressed'])->toBeTrue();
        expect($result['reason'])->toBe('bounce');
    });

    it('checks suppression — found in complaints', function () {
        Http::fake([
            'api.mailersend.com/v1/suppressions/hard-bounces*' => Http::response(['data' => []], 200),
            'api.mailersend.com/v1/suppressions/spam-complaints*' => Http::response([
                'data' => [['recipient' => 'spam@test.com']],
            ], 200),
        ]);

        $result = $this->provider->checkSuppression('example.com', 'spam@test.com', $this->config);

        expect($result['suppressed'])->toBeTrue();
        expect($result['reason'])->toBe('complaint');
    });

    it('checks suppression — not suppressed', function () {
        Http::fake([
            'api.mailersend.com/v1/suppressions/hard-bounces*' => Http::response(['data' => []], 200),
            'api.mailersend.com/v1/suppressions/spam-complaints*' => Http::response(['data' => []], 200),
        ]);

        $result = $this->provider->checkSuppression('example.com', 'good@test.com', $this->config);

        expect($result['suppressed'])->toBeFalse();
    });

    it('imports bounces with domain ID mapping', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com']],
            ], 200),
            'api.mailersend.com/v1/suppressions/hard-bounces' => Http::response(['data' => []], 200),
        ]);

        $result = $this->provider->importBounces('example.com', [
            ['address' => 'bad1@test.com'],
            ['address' => 'bad2@test.com'],
        ], $this->config);

        expect($result)->toBeArray();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'hard-bounces') || $request->method() !== 'POST') {
                return false;
            }
            $recipients = $request['recipients'] ?? [];
            return count($recipients) === 2
                && $recipients[0]['domain_id'] === 'dom_1';
        });
    });
});

// ---------------------------------------------------------------------------
// Inbound routes
// ---------------------------------------------------------------------------

describe('inbound routes', function () {
    it('lists routes for domain', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com']],
            ], 200),
            'api.mailersend.com/v1/inbound*' => Http::response([
                'data' => [['id' => 'rt_1', 'name' => 'Inbound for example.com']],
            ], 200),
        ]);

        $result = $this->provider->listRoutes('example.com', $this->config);

        expect($result)->toHaveCount(1);
        expect($result[0]['id'])->toBe('rt_1');
    });

    it('creates route with webhook forwards', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response([
                'data' => [['id' => 'dom_1', 'name' => 'example.com']],
            ], 200),
            'api.mailersend.com/v1/inbound' => Http::response([
                'data' => ['id' => 'rt_new'],
            ], 201),
        ]);

        $result = $this->provider->createRoute(
            'example.com',
            ['https://app.test/webhook'],
            'Inbound route',
            0,
            $this->config,
        );

        expect($result)->toHaveKey('data');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/inbound') || $request->method() !== 'POST') {
                return false;
            }
            return $request['domain_id'] === 'dom_1'
                && $request['forwards'][0]['type'] === 'webhook'
                && $request['forwards'][0]['value'] === 'https://app.test/webhook';
        });
    });

    it('updates route', function () {
        Http::fake([
            'api.mailersend.com/v1/inbound/rt_1' => Http::response(['data' => ['id' => 'rt_1']], 200),
        ]);

        $result = $this->provider->updateRoute('rt_1', ['name' => 'Updated'], $this->config);

        expect($result)->toHaveKey('data');
    });

    it('deletes route', function () {
        Http::fake([
            'api.mailersend.com/v1/inbound/rt_1' => Http::response([], 200),
        ]);

        $result = $this->provider->deleteRoute('rt_1', $this->config);

        expect($result)->toBeArray();
    });

    it('returns empty when domain not found for routes', function () {
        Http::fake([
            'api.mailersend.com/v1/domains' => Http::response(['data' => []], 200),
        ]);

        $result = $this->provider->listRoutes('unknown.com', $this->config);

        expect($result)->toBeEmpty();
    });
});
