<?php

use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\Stripe\StripeWebhookService;

describe('StripeWebhookService', function () {

    beforeEach(function () {
        $this->service = new StripeWebhookService();
        $this->orchestratorMock = Mockery::mock(NotificationOrchestrator::class);
        $this->orchestratorMock->shouldReceive('sendByType')->byDefault();
        app()->instance(NotificationOrchestrator::class, $this->orchestratorMock);
    });

    describe('constructEvent', function () {
        it('throws SignatureVerificationException when signature is invalid', function () {
            config(['stripe.webhook_secret' => 'whsec_test_secret']);

            expect(fn () => $this->service->constructEvent(
                '{"id":"evt_fake"}',
                'invalid-sig-header'
            ))->toThrow(\Stripe\Exception\SignatureVerificationException::class);
        });
    });

    describe('handleEvent — idempotency', function () {
        it('processes a new event and creates a webhook event record', function () {
            $event = buildFakePaymentIntentSucceededEvent('evt_new_001', 'pi_test_001');

            $result = $this->service->handleEvent($event);

            expect($result['handled'])->toBeTrue();
            expect($result['skipped'])->toBeFalse();

            $this->assertDatabaseHas('stripe_webhook_events', [
                'stripe_event_id' => 'evt_new_001',
                'event_type' => 'payment_intent.succeeded',
                'status' => 'processed',
            ]);
        });

        it('skips a duplicate event without re-processing', function () {
            StripeWebhookEvent::create([
                'stripe_event_id' => 'evt_dup_001',
                'event_type' => 'payment_intent.succeeded',
                'status' => 'processed',
            ]);

            $event = buildFakePaymentIntentSucceededEvent('evt_dup_001', 'pi_test_001');

            $result = $this->service->handleEvent($event);

            expect($result['handled'])->toBeFalse();
            expect($result['skipped'])->toBeTrue();
            expect($result['reason'])->toBe('duplicate');
            expect(StripeWebhookEvent::where('stripe_event_id', 'evt_dup_001')->count())->toBe(1);
        });
    });

    describe('handleEvent — payment_intent.succeeded', function () {
        it('updates payment status to succeeded and sets paid_at', function () {
            $payment = Payment::factory()->create([
                'stripe_payment_intent_id' => 'pi_test_success',
                'status' => 'pending',
                'paid_at' => null,
            ]);

            $this->orchestratorMock->shouldReceive('sendByType')
                ->once()
                ->withArgs(fn ($user, $type, $vars) =>
                    $type === 'payment.succeeded'
                    && isset($vars['amount'], $vars['currency'], $vars['payment_id'], $vars['title'], $vars['message'])
                );

            $event = buildFakePaymentIntentSucceededEvent('evt_succ_001', 'pi_test_success');
            $this->service->handleEvent($event);

            $payment->refresh();
            expect($payment->status)->toBe('succeeded');
            expect($payment->paid_at)->not->toBeNull();
        });

        it('handles missing payment record gracefully', function () {
            $event = buildFakePaymentIntentSucceededEvent('evt_succ_002', 'pi_nonexistent');

            $result = $this->service->handleEvent($event);

            expect($result['handled'])->toBeTrue();
            $this->assertDatabaseHas('stripe_webhook_events', [
                'stripe_event_id' => 'evt_succ_002',
                'status' => 'processed',
            ]);
        });
    });

    describe('handleEvent — payment_intent.payment_failed', function () {
        it('updates payment status to failed', function () {
            $payment = Payment::factory()->create([
                'stripe_payment_intent_id' => 'pi_test_fail',
                'status' => 'pending',
            ]);

            $this->orchestratorMock->shouldReceive('sendByType')
                ->once()
                ->withArgs(fn ($user, $type, $vars) =>
                    $type === 'payment.failed'
                    && isset($vars['amount'], $vars['currency'], $vars['error_message'], $vars['title'], $vars['message'])
                );

            $event = buildFakePaymentIntentFailedEvent('evt_fail_001', 'pi_test_fail');
            $this->service->handleEvent($event);

            $payment->refresh();
            expect($payment->status)->toBe('failed');
        });
    });

    describe('handleEvent — charge.refunded', function () {
        it('updates payment status to refunded and sets refunded_at', function () {
            $payment = Payment::factory()->create([
                'stripe_payment_intent_id' => 'pi_test_refund',
                'status' => 'succeeded',
                'refunded_at' => null,
            ]);

            $this->orchestratorMock->shouldReceive('sendByType')
                ->once()
                ->withArgs(fn ($user, $type, $vars) =>
                    $type === 'payment.refunded'
                    && isset($vars['amount'], $vars['refund_amount'], $vars['currency'], $vars['refund_type'], $vars['title'], $vars['message'])
                );

            $event = buildFakeChargeRefundedEvent('evt_ref_001', 'ch_test', 'pi_test_refund');
            $this->service->handleEvent($event);

            $payment->refresh();
            expect($payment->status)->toBe('refunded');
            expect($payment->refunded_at)->not->toBeNull();
        });

        it('updates payment status to partially_refunded for partial refunds', function () {
            $payment = Payment::factory()->create([
                'stripe_payment_intent_id' => 'pi_test_partial',
                'status' => 'succeeded',
                'refunded_at' => null,
            ]);

            $this->orchestratorMock->shouldReceive('sendByType')
                ->once()
                ->withArgs(fn ($user, $type, $vars) =>
                    $type === 'payment.refunded'
                    && $vars['refund_type'] === 'Partial refund'
                );

            $event = buildFakeChargeRefundedEvent('evt_ref_partial', 'ch_partial', 'pi_test_partial', false);
            $this->service->handleEvent($event);

            $payment->refresh();
            expect($payment->status)->toBe('partially_refunded');
            expect($payment->refunded_at)->not->toBeNull();
        });

        it('handles charge with no payment_intent reference', function () {
            $event = buildFakeChargeRefundedEvent('evt_ref_002', 'ch_noref', null);

            $result = $this->service->handleEvent($event);

            expect($result['handled'])->toBeTrue();
        });
    });

    describe('handleEvent — account.updated', function () {
        it('acknowledges the event and marks it processed', function () {
            $event = buildFakeAccountUpdatedEvent('evt_acct_001', 'acct_test_001');

            $result = $this->service->handleEvent($event);

            expect($result['handled'])->toBeTrue();
            $this->assertDatabaseHas('stripe_webhook_events', [
                'stripe_event_id' => 'evt_acct_001',
                'status' => 'processed',
            ]);
        });
    });

    describe('handleEvent — account.application.deauthorized', function () {
        it('acknowledges the event and marks it processed', function () {
            $event = buildFakeAccountDeauthorizedEvent('evt_deauth_001', 'acct_test_002');

            $result = $this->service->handleEvent($event);

            expect($result['handled'])->toBeTrue();
            $this->assertDatabaseHas('stripe_webhook_events', [
                'stripe_event_id' => 'evt_deauth_001',
                'status' => 'processed',
            ]);
        });
    });

    describe('handleEvent — unknown event type', function () {
        it('marks the event as skipped for unhandled event types', function () {
            $event = buildFakeStripeEvent('evt_unk_001', 'customer.created', [
                'id' => 'cus_test',
                'object' => 'customer',
            ]);

            $result = $this->service->handleEvent($event);

            expect($result['skipped'])->toBeTrue();
            expect($result['reason'])->toBe('unhandled_type');
            $this->assertDatabaseHas('stripe_webhook_events', [
                'stripe_event_id' => 'evt_unk_001',
                'status' => 'skipped',
            ]);
        });
    });
});
