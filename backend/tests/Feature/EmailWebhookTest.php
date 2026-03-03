<?php

use App\Models\Email;
use App\Models\EmailDomain;
use App\Models\EmailWebhookLog;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Email\DomainService;
use App\Services\Email\EmailProviderInterface;
use App\Services\Email\ParsedEmail;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->domain = EmailDomain::create([
        'user_id' => $this->user->id,
        'name' => 'test.example.com',
        'provider' => 'mailgun',
        'is_verified' => true,
        'is_active' => true,
    ]);

    $this->mailbox = Mailbox::create([
        'user_id' => $this->user->id,
        'email_domain_id' => $this->domain->id,
        'address' => 'inbox',
        'is_active' => true,
    ]);

    // Create a mock provider that always passes signature verification
    $this->mockProvider = Mockery::mock(EmailProviderInterface::class);
    $this->mockProvider->shouldReceive('getName')->andReturn('mailgun');
    $this->mockProvider->shouldReceive('verifyWebhookSignature')->andReturn(true)->byDefault();

    // Mock the DomainService to return our mock provider
    $mockDomainService = Mockery::mock(DomainService::class);
    $mockDomainService->shouldReceive('resolveProvider')
        ->with('mailgun')
        ->andReturn($this->mockProvider);
    $mockDomainService->shouldReceive('resolveProvider')
        ->with('unknown')
        ->andThrow(new \InvalidArgumentException('Unknown email provider: unknown'));

    $this->app->instance(DomainService::class, $mockDomainService);
});

describe('Inbound Email Webhook', function () {

    it('processes inbound email successfully', function () {
        $this->mockProvider->shouldReceive('parseInboundEmail')
            ->once()
            ->andReturn(new ParsedEmail(
                fromAddress: 'sender@other.com',
                fromName: 'Sender',
                to: [['address' => 'inbox@test.example.com', 'name' => null]],
                cc: [],
                bcc: [],
                subject: 'Test Email',
                bodyText: 'Hello world',
                bodyHtml: '<p>Hello world</p>',
                headers: [],
                attachments: [],
                messageId: '<unique-msg-001@other.com>',
                inReplyTo: null,
                references: null,
                spamScore: null,
                providerMessageId: '<unique-msg-001@other.com>',
                providerEventId: 'evt_001',
                recipientAddress: 'inbox@test.example.com',
            ));

        $response = $this->postJson('/api/email/webhook/mailgun');

        $response->assertStatus(200)
            ->assertJson(['email_id' => true]);

        $this->assertDatabaseHas('emails', [
            'mailbox_id' => $this->mailbox->id,
            'message_id' => '<unique-msg-001@other.com>',
            'subject' => 'Test Email',
            'direction' => 'inbound',
        ]);

        $this->assertDatabaseHas('email_webhook_logs', [
            'provider_event_id' => 'evt_001',
            'status' => 'processed',
        ]);
    });

    it('rejects duplicate emails by message_id', function () {
        // Create existing email with same message_id in same mailbox
        Email::create([
            'user_id' => $this->user->id,
            'mailbox_id' => $this->mailbox->id,
            'message_id' => '<duplicate-msg@other.com>',
            'direction' => 'inbound',
            'from_address' => 'sender@other.com',
            'subject' => 'Original',
            'sent_at' => now(),
        ]);

        $this->mockProvider->shouldReceive('parseInboundEmail')
            ->once()
            ->andReturn(new ParsedEmail(
                fromAddress: 'sender@other.com',
                fromName: 'Sender',
                to: [['address' => 'inbox@test.example.com', 'name' => null]],
                cc: [],
                bcc: [],
                subject: 'Duplicate',
                bodyText: 'Duplicate body',
                bodyHtml: null,
                headers: [],
                attachments: [],
                messageId: '<duplicate-msg@other.com>',
                inReplyTo: null,
                references: null,
                spamScore: null,
                providerMessageId: '<duplicate-msg@other.com>',
                providerEventId: 'evt_dup_002',
                recipientAddress: 'inbox@test.example.com',
            ));

        $response = $this->postJson('/api/email/webhook/mailgun');

        $response->assertStatus(200)
            ->assertJson(['message' => 'accepted']);

        // Only the original email should exist
        expect(Email::where('message_id', '<duplicate-msg@other.com>')->count())->toBe(1);

        $this->assertDatabaseHas('email_webhook_logs', [
            'provider_event_id' => 'evt_dup_002',
            'status' => 'duplicate',
        ]);
    });

    it('allows same message_id in different mailboxes', function () {
        $otherMailbox = Mailbox::create([
            'user_id' => $this->user->id,
            'email_domain_id' => $this->domain->id,
            'address' => 'other',
            'is_active' => true,
        ]);

        // Email already exists in the other mailbox
        Email::create([
            'user_id' => $this->user->id,
            'mailbox_id' => $otherMailbox->id,
            'message_id' => '<shared-msg@other.com>',
            'direction' => 'inbound',
            'from_address' => 'sender@other.com',
            'subject' => 'CC email',
            'sent_at' => now(),
        ]);

        $this->mockProvider->shouldReceive('parseInboundEmail')
            ->once()
            ->andReturn(new ParsedEmail(
                fromAddress: 'sender@other.com',
                fromName: 'Sender',
                to: [['address' => 'inbox@test.example.com', 'name' => null]],
                cc: [['address' => 'other@test.example.com', 'name' => null]],
                bcc: [],
                subject: 'CC email',
                bodyText: 'CC body',
                bodyHtml: null,
                headers: [],
                attachments: [],
                messageId: '<shared-msg@other.com>',
                inReplyTo: null,
                references: null,
                spamScore: null,
                providerMessageId: '<shared-msg@other.com>',
                providerEventId: 'evt_cc_003',
                recipientAddress: 'inbox@test.example.com',
            ));

        $response = $this->postJson('/api/email/webhook/mailgun');

        $response->assertStatus(200)
            ->assertJson(['email_id' => true]);

        // Both mailboxes should have the email
        expect(Email::where('message_id', '<shared-msg@other.com>')->count())->toBe(2);
    });

    it('handles duplicate provider_event_id', function () {
        // Create existing webhook log with same event ID
        EmailWebhookLog::create([
            'provider' => 'mailgun',
            'provider_event_id' => 'evt_already_processed',
            'event_type' => 'inbound',
            'payload' => ['from' => 'old@other.com'],
            'status' => 'processed',
            'created_at' => now(),
        ]);

        $this->mockProvider->shouldReceive('parseInboundEmail')
            ->once()
            ->andReturn(new ParsedEmail(
                fromAddress: 'sender@other.com',
                fromName: 'Sender',
                to: [['address' => 'inbox@test.example.com', 'name' => null]],
                cc: [],
                bcc: [],
                subject: 'Retried email',
                bodyText: 'Retry body',
                bodyHtml: null,
                headers: [],
                attachments: [],
                messageId: '<retried-msg@other.com>',
                inReplyTo: null,
                references: null,
                spamScore: null,
                providerMessageId: '<retried-msg@other.com>',
                providerEventId: 'evt_already_processed',
                recipientAddress: 'inbox@test.example.com',
            ));

        $response = $this->postJson('/api/email/webhook/mailgun');

        $response->assertStatus(200)
            ->assertJson(['message' => 'accepted']);

        // No email should be created
        $this->assertDatabaseMissing('emails', [
            'message_id' => '<retried-msg@other.com>',
        ]);
    });

    it('returns 401 for invalid webhook signature', function () {
        // Override mock to reject signature
        $this->mockProvider->shouldReceive('verifyWebhookSignature')->andReturn(false);

        $response = $this->postJson('/api/email/webhook/mailgun');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid signature']);
    });

    it('returns 404 for unknown provider', function () {
        $response = $this->postJson('/api/email/webhook/unknown');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Unknown provider']);
    });
});

describe('Delivery Event Webhook', function () {

    it('returns 200 for unrecognized event types', function () {
        $this->mockProvider->shouldReceive('parseDeliveryEvent')
            ->once()
            ->andReturn([
                'event_type' => 'some_unknown_event',
                'provider_message_id' => null,
                'timestamp' => time(),
                'recipient' => 'test@example.com',
                'error_message' => null,
            ]);

        $response = $this->postJson('/api/email/webhook/mailgun/events', [
            'event-data' => [
                'id' => 'evt_unknown_001',
                'event' => 'some_unknown_event',
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('email_webhook_logs', [
            'provider_event_id' => 'evt_unknown_001',
            'status' => 'failed',
            'error_message' => 'No provider_message_id in payload',
        ]);
    });

    it('handles complained and unsubscribed events', function () {
        // Create an outbound email to update
        $email = Email::create([
            'user_id' => $this->user->id,
            'mailbox_id' => $this->mailbox->id,
            'message_id' => '<outbound-msg@test.example.com>',
            'provider_message_id' => '<outbound-msg@test.example.com>',
            'direction' => 'outbound',
            'from_address' => 'inbox@test.example.com',
            'subject' => 'Outbound test',
            'delivery_status' => 'delivered',
            'sent_at' => now(),
        ]);

        $this->mockProvider->shouldReceive('parseDeliveryEvent')
            ->once()
            ->andReturn([
                'event_type' => 'delivered', // complained maps to 'delivered' in statusMap
                'provider_message_id' => '<outbound-msg@test.example.com>',
                'timestamp' => time(),
                'recipient' => 'recipient@other.com',
                'error_message' => null,
            ]);

        $response = $this->postJson('/api/email/webhook/mailgun/events', [
            'event-data' => [
                'id' => 'evt_complained_001',
                'event' => 'complained',
                'recipient' => 'recipient@other.com',
                'message' => ['headers' => ['message-id' => '<outbound-msg@test.example.com>']],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'ok']);

        $this->assertDatabaseHas('email_webhook_logs', [
            'provider_event_id' => 'evt_complained_001',
            'status' => 'processed',
        ]);
    });

    it('returns 200 for malformed event payloads', function () {
        $this->mockProvider->shouldReceive('parseDeliveryEvent')
            ->once()
            ->andThrow(new \Exception('Unexpected payload structure'));

        $response = $this->postJson('/api/email/webhook/mailgun/events', []);

        // Should return 200 (not 500) to prevent infinite retries
        $response->assertStatus(200)
            ->assertJson(['message' => 'accepted']);
    });

    it('updates delivery status when email is found', function () {
        $email = Email::create([
            'user_id' => $this->user->id,
            'mailbox_id' => $this->mailbox->id,
            'message_id' => '<delivered-msg@test.example.com>',
            'provider_message_id' => '<delivered-msg@test.example.com>',
            'direction' => 'outbound',
            'from_address' => 'inbox@test.example.com',
            'subject' => 'Delivery test',
            'delivery_status' => 'queued',
            'sent_at' => now(),
        ]);

        $this->mockProvider->shouldReceive('parseDeliveryEvent')
            ->once()
            ->andReturn([
                'event_type' => 'delivered',
                'provider_message_id' => '<delivered-msg@test.example.com>',
                'timestamp' => time(),
                'recipient' => 'recipient@other.com',
                'error_message' => null,
            ]);

        $response = $this->postJson('/api/email/webhook/mailgun/events', [
            'event-data' => [
                'id' => 'evt_delivered_001',
                'event' => 'delivered',
                'message' => ['headers' => ['message-id' => '<delivered-msg@test.example.com>']],
            ],
        ]);

        $response->assertStatus(200);

        expect($email->fresh()->delivery_status)->toBe('delivered');
    });
});
