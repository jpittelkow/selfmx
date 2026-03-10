<?php

use App\Models\Email;
use App\Models\EmailDomain;
use App\Models\Mailbox;
use App\Models\MailboxForward;
use App\Models\User;
use App\Services\Email\DomainService;
use App\Services\Email\EmailForwardingService;
use App\Services\Email\EmailProviderInterface;
use App\Services\Email\ParsedEmail;
use App\Services\Email\SendResult;
use Illuminate\Support\Facades\Log;

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

    // Create mock provider and DomainService
    $this->mockProvider = $this->mock(EmailProviderInterface::class);
    $this->mockDomainService = $this->mock(DomainService::class);
    $this->mockDomainService->shouldReceive('resolveProvider')
        ->with('mailgun')
        ->andReturn($this->mockProvider);

    $this->service = new EmailForwardingService($this->mockDomainService);
});

describe('EmailForwardingService', function () {

    describe('getActiveForward', function () {

        it('returns null when no forward exists', function () {
            $result = $this->service->getActiveForward($this->mailbox);

            expect($result)->toBeNull();
        });

        it('returns active forward for mailbox', function () {
            $forward = MailboxForward::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'forward_to' => 'external@gmail.com',
                'keep_local_copy' => true,
                'is_active' => true,
            ]);

            $result = $this->service->getActiveForward($this->mailbox);

            expect($result)->not->toBeNull();
            expect($result->id)->toBe($forward->id);
            expect($result->forward_to)->toBe('external@gmail.com');
        });

        it('ignores inactive forwards', function () {
            MailboxForward::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'forward_to' => 'external@gmail.com',
                'keep_local_copy' => true,
                'is_active' => false,
            ]);

            $result = $this->service->getActiveForward($this->mailbox);

            expect($result)->toBeNull();
        });

        it('ignores forwards for other mailboxes', function () {
            $otherMailbox = Mailbox::create([
                'user_id' => $this->user->id,
                'email_domain_id' => $this->domain->id,
                'address' => 'other',
                'is_active' => true,
            ]);

            MailboxForward::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $otherMailbox->id,
                'forward_to' => 'external@gmail.com',
                'keep_local_copy' => true,
                'is_active' => true,
            ]);

            $result = $this->service->getActiveForward($this->mailbox);

            expect($result)->toBeNull();
        });
    });

    describe('forwardParsed (pass-through mode)', function () {

        it('calls provider sendEmail with correct parameters', function () {
            $forward = MailboxForward::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'forward_to' => 'external@gmail.com',
                'keep_local_copy' => false,
                'is_active' => true,
            ]);

            $parsed = new ParsedEmail(
                fromAddress: 'sender@example.com',
                fromName: 'Sender',
                to: [['address' => 'inbox@test.example.com', 'name' => '']],
                cc: [],
                bcc: [],
                subject: 'Test Subject',
                bodyText: 'Plain text body',
                bodyHtml: '<p>HTML body</p>',
                headers: [],
                attachments: [],
                messageId: '<msg-123@example.com>',
                inReplyTo: null,
                references: null,
                spamScore: null,
                providerMessageId: 'provider-123',
                providerEventId: 'event-123',
                recipientAddress: 'inbox@test.example.com',
            );

            $this->mockProvider->shouldReceive('sendEmail')
                ->once()
                ->with(
                    $this->mailbox,
                    [['address' => 'external@gmail.com']],
                    'Test Subject',
                    '<p>HTML body</p>',
                    'Plain text body',
                    [],
                    [],
                    [],
                    \Mockery::on(function ($headers) {
                        return $headers['X-Forwarded-To'] === 'external@gmail.com'
                            && $headers['X-Original-From'] === 'sender@example.com';
                    })
                )
                ->andReturn(SendResult::success('msg-id'));

            Log::shouldReceive('info')
                ->once();

            $this->service->forwardParsed($parsed, $this->mailbox, $forward);
        });

        it('re-throws exceptions on send failure', function () {
            $forward = MailboxForward::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'forward_to' => 'external@gmail.com',
                'keep_local_copy' => false,
                'is_active' => true,
            ]);

            $parsed = new ParsedEmail(
                fromAddress: 'sender@example.com',
                fromName: 'Sender',
                to: [['address' => 'inbox@test.example.com', 'name' => '']],
                cc: [],
                bcc: [],
                subject: 'Test',
                bodyText: 'text',
                bodyHtml: null,
                headers: [],
                attachments: [],
                messageId: null,
                inReplyTo: null,
                references: null,
                spamScore: null,
                providerMessageId: null,
                providerEventId: null,
                recipientAddress: 'inbox@test.example.com',
            );

            $this->mockProvider->shouldReceive('sendEmail')
                ->once()
                ->andThrow(new \RuntimeException('Send failed'));

            Log::shouldReceive('error')
                ->once();

            $this->service->forwardParsed($parsed, $this->mailbox, $forward);
        })->throws(\RuntimeException::class, 'Send failed');

        it('uses bodyText as fallback when bodyHtml is null', function () {
            $forward = MailboxForward::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'forward_to' => 'external@gmail.com',
                'keep_local_copy' => false,
                'is_active' => true,
            ]);

            $parsed = new ParsedEmail(
                fromAddress: 'sender@example.com',
                fromName: null,
                to: [['address' => 'inbox@test.example.com', 'name' => '']],
                cc: [],
                bcc: [],
                subject: null,
                bodyText: 'Plain only',
                bodyHtml: null,
                headers: [],
                attachments: [],
                messageId: null,
                inReplyTo: null,
                references: null,
                spamScore: null,
                providerMessageId: null,
                providerEventId: null,
                recipientAddress: 'inbox@test.example.com',
            );

            $this->mockProvider->shouldReceive('sendEmail')
                ->once()
                ->with(
                    $this->mailbox,
                    [['address' => 'external@gmail.com']],
                    '(no subject)',
                    'Plain only',
                    'Plain only',
                    [],
                    [],
                    [],
                    \Mockery::any()
                )
                ->andReturn(SendResult::success('msg-id'));

            Log::shouldReceive('info')->once();

            $this->service->forwardParsed($parsed, $this->mailbox, $forward);
        });
    });

    describe('forwardEmail (keep-copy mode)', function () {

        it('calls provider sendEmail with correct parameters from Email record', function () {
            $email = Email::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'message_id' => '<msg-456@example.com>',
                'direction' => 'inbound',
                'from_address' => 'sender@example.com',
                'from_name' => 'Sender',
                'subject' => 'Keep Copy Test',
                'body_text' => 'Plain text',
                'body_html' => '<p>HTML</p>',
                'is_read' => false,
                'sent_at' => now(),
            ]);

            $forward = MailboxForward::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'forward_to' => 'external@gmail.com',
                'keep_local_copy' => true,
                'is_active' => true,
            ]);

            $this->mockProvider->shouldReceive('sendEmail')
                ->once()
                ->with(
                    $this->mailbox,
                    [['address' => 'external@gmail.com']],
                    'Keep Copy Test',
                    '<p>HTML</p>',
                    'Plain text',
                    [],
                    [],
                    [],
                    \Mockery::on(function ($headers) {
                        return $headers['X-Forwarded-To'] === 'external@gmail.com'
                            && $headers['X-Original-From'] === 'sender@example.com';
                    })
                )
                ->andReturn(SendResult::success('msg-id'));

            Log::shouldReceive('info')
                ->once();

            $this->service->forwardEmail($email, $this->mailbox, $forward);
        });

        it('logs error on send failure but still throws', function () {
            $email = Email::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'message_id' => '<msg-789@example.com>',
                'direction' => 'inbound',
                'from_address' => 'sender@example.com',
                'subject' => 'Fail Test',
                'body_text' => 'text',
                'is_read' => false,
                'sent_at' => now(),
            ]);

            $forward = MailboxForward::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'forward_to' => 'external@gmail.com',
                'keep_local_copy' => true,
                'is_active' => true,
            ]);

            $this->mockProvider->shouldReceive('sendEmail')
                ->once()
                ->andThrow(new \RuntimeException('Send failed'));

            Log::shouldReceive('error')
                ->once();

            $this->service->forwardEmail($email, $this->mailbox, $forward);
        })->throws(\RuntimeException::class);

        it('uses bodyText fallback when bodyHtml is null', function () {
            $email = Email::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'message_id' => '<msg-fallback@example.com>',
                'direction' => 'inbound',
                'from_address' => 'sender@example.com',
                'subject' => 'Fallback Test',
                'body_text' => 'Only plain text',
                'body_html' => null,
                'is_read' => false,
                'sent_at' => now(),
            ]);

            $forward = MailboxForward::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $this->mailbox->id,
                'forward_to' => 'external@gmail.com',
                'keep_local_copy' => true,
                'is_active' => true,
            ]);

            $this->mockProvider->shouldReceive('sendEmail')
                ->once()
                ->with(
                    $this->mailbox,
                    [['address' => 'external@gmail.com']],
                    'Fallback Test',
                    'Only plain text',
                    'Only plain text',
                    [],
                    [],
                    [],
                    \Mockery::any()
                )
                ->andReturn(SendResult::success('msg-id'));

            Log::shouldReceive('info')->once();

            $this->service->forwardEmail($email, $this->mailbox, $forward);
        });
    });

    describe('catchall forwarding', function () {

        it('works with wildcard mailbox address', function () {
            $catchallMailbox = Mailbox::create([
                'user_id' => $this->user->id,
                'email_domain_id' => $this->domain->id,
                'address' => '*',
                'is_active' => true,
            ]);

            $forward = MailboxForward::create([
                'user_id' => $this->user->id,
                'mailbox_id' => $catchallMailbox->id,
                'forward_to' => 'catchall@gmail.com',
                'keep_local_copy' => true,
                'is_active' => true,
            ]);

            $result = $this->service->getActiveForward($catchallMailbox);

            expect($result)->not->toBeNull();
            expect($result->forward_to)->toBe('catchall@gmail.com');
        });
    });
});
