<?php

namespace App\Services\Stripe;

use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\SettingService;
use App\Services\UsageTrackingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookService
{
    public function __construct(
        private NotificationOrchestrator $notificationOrchestrator
    ) {}

    /**
     * Verify the Stripe webhook signature and parse the event.
     *
     * @throws SignatureVerificationException
     * @throws \UnexpectedValueException
     */
    public function constructEvent(string $payload, string $sigHeader): Event
    {
        $secret = config('stripe.webhook_secret');

        if (empty($secret)) {
            throw new \RuntimeException('Stripe webhook secret is not configured');
        }

        return Webhook::constructEvent($payload, $sigHeader, $secret);
    }

    /**
     * Process a verified Stripe event idempotently.
     *
     * @return array{handled: bool, skipped: bool, reason: ?string}
     */
    public function handleEvent(Event $event): array
    {
        try {
            $record = StripeWebhookEvent::create([
                'stripe_event_id' => $event->id,
                'event_type' => $event->type,
                'status' => 'pending',
                'payload' => $event->toArray(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            Log::info('Stripe webhook duplicate, skipping', [
                'event_id' => $event->id,
                'type' => $event->type,
            ]);

            return ['handled' => false, 'skipped' => true, 'reason' => 'duplicate'];
        }

        try {
            $handled = match ($event->type) {
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
                'charge.refunded' => $this->handleChargeRefunded($event),
                'account.updated' => $this->handleAccountUpdated($event),
                'account.application.deauthorized' => $this->handleAccountDeauthorized($event),
                default => false,
            };

            if (! $handled) {
                $record->update(['status' => 'skipped']);

                return ['handled' => false, 'skipped' => true, 'reason' => 'unhandled_type'];
            }

            $record->update(['status' => 'processed']);

            return ['handled' => true, 'skipped' => false, 'reason' => null];
        } catch (\Throwable $e) {
            $record->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            Log::error('Stripe webhook handler failed', [
                'event_id' => $event->id,
                'type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function sendPaymentNotification(Payment $payment, string $type, array $variables): void
    {
        try {
            $user = $payment->user;
            if (! $user) {
                Log::warning("Cannot send {$type} notification: user not found", [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                ]);

                return;
            }

            $this->notificationOrchestrator->sendByType($user, $type, $variables);
        } catch (\Throwable $e) {
            Log::warning("Failed to send {$type} notification", [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handlePaymentIntentSucceeded(Event $event): bool
    {
        $intent = $event->data->object;
        $payment = Payment::where('stripe_payment_intent_id', $intent->id)->first();

        if (! $payment) {
            Log::warning('Stripe payment_intent.succeeded: no matching Payment record', [
                'payment_intent_id' => $intent->id,
            ]);

            return true;
        }

        $payment->update([
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);

        Log::info('Payment marked succeeded via webhook', [
            'payment_id' => $payment->id,
            'payment_intent_id' => $intent->id,
            'amount' => $intent->amount,
        ]);

        app(UsageTrackingService::class)->recordPayment(
            'stripe',
            $payment->amount / 100,
            'payment_processed',
            [
                'payment_id' => $payment->id,
                'stripe_payment_intent_id' => $intent->id,
                'currency' => $payment->currency,
            ],
            $payment->user_id
        );

        $formattedAmount = number_format($payment->amount / 100, 2);
        $currency = strtoupper($payment->currency);

        $this->sendPaymentNotification($payment, 'payment.succeeded', [
            'amount' => $formattedAmount,
            'currency' => $currency,
            'description' => $payment->description ?? '',
            'customer_email' => $payment->user?->email ?? '',
            'payment_id' => (string) $payment->id,
        ]);

        return true;
    }

    private function handlePaymentIntentFailed(Event $event): bool
    {
        $intent = $event->data->object;
        $payment = Payment::where('stripe_payment_intent_id', $intent->id)->first();

        if (! $payment) {
            Log::warning('Stripe payment_intent.payment_failed: no matching Payment record', [
                'payment_intent_id' => $intent->id,
            ]);

            return true;
        }

        $payment->update([
            'status' => 'failed',
        ]);

        Log::info('Payment marked failed via webhook', [
            'payment_id' => $payment->id,
            'payment_intent_id' => $intent->id,
            'failure_message' => $intent->last_payment_error?->message,
        ]);

        $formattedAmount = number_format($payment->amount / 100, 2);
        $currency = strtoupper($payment->currency);
        $errorMessage = $intent->last_payment_error?->message ?? 'Unknown error';

        $this->sendPaymentNotification($payment, 'payment.failed', [
            'amount' => $formattedAmount,
            'currency' => $currency,
            'description' => $payment->description ?? '',
            'customer_email' => $payment->user?->email ?? '',
            'payment_id' => (string) $payment->id,
            'error_message' => $errorMessage,
        ]);

        return true;
    }

    private function handleChargeRefunded(Event $event): bool
    {
        $charge = $event->data->object;
        $paymentIntentId = $charge->payment_intent;

        if (! $paymentIntentId) {
            Log::warning('Stripe charge.refunded: charge has no payment_intent', [
                'charge_id' => $charge->id,
            ]);

            return true;
        }

        $payment = Payment::where('stripe_payment_intent_id', $paymentIntentId)->first();

        if (! $payment) {
            Log::warning('Stripe charge.refunded: no matching Payment record', [
                'payment_intent_id' => $paymentIntentId,
                'charge_id' => $charge->id,
            ]);

            return true;
        }

        $refundStatus = $charge->refunded ? 'refunded' : 'partially_refunded';
        $payment->update([
            'status' => $refundStatus,
            'refunded_at' => now(),
        ]);

        Log::info("Payment marked {$refundStatus} via webhook", [
            'payment_id' => $payment->id,
            'payment_intent_id' => $paymentIntentId,
            'charge_id' => $charge->id,
            'amount_refunded' => $charge->amount_refunded,
        ]);

        app(UsageTrackingService::class)->recordPayment(
            'stripe',
            -($charge->amount_refunded / 100),
            'refund_processed',
            [
                'payment_id' => $payment->id,
                'charge_id' => $charge->id,
                'amount_refunded' => $charge->amount_refunded,
            ],
            $payment->user_id
        );

        $formattedAmount = number_format($payment->amount / 100, 2);
        $formattedRefund = number_format($charge->amount_refunded / 100, 2);
        $currency = strtoupper($payment->currency);
        $refundType = $charge->refunded ? 'Full refund' : 'Partial refund';

        $this->sendPaymentNotification($payment, 'payment.refunded', [
            'amount' => $formattedAmount,
            'refund_amount' => $formattedRefund,
            'currency' => $currency,
            'description' => $payment->description ?? '',
            'customer_email' => $payment->user?->email ?? '',
            'payment_id' => (string) $payment->id,
            'refund_type' => $refundType,
        ]);

        return true;
    }

    private function handleAccountUpdated(Event $event): bool
    {
        $account = $event->data->object;

        Log::info('Stripe account.updated received', [
            'account_id' => $account->id,
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
            'details_submitted' => $account->details_submitted,
            'type' => $account->type,
        ]);

        return true;
    }

    private function handleAccountDeauthorized(Event $event): bool
    {
        $account = $event->data->object;

        Log::warning('Stripe account.application.deauthorized — connected account revoked platform access', [
            'account_id' => $account->id,
        ]);

        // Clear the connected account so the app stops trying to use a revoked account
        $settingService = app(SettingService::class);
        $settingService->set('stripe', 'connected_account_id', null);
        $settingService->set('stripe', 'connect_onboarding_state', null);

        return true;
    }
}
