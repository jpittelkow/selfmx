# Stripe Webhooks Pattern

Stripe webhook handling uses signature verification, idempotent event processing, and a service-based handler pattern.

## Architecture

```
Stripe → POST /stripe/webhook
  → StripeWebhookController::handle()
    → constructEvent(payload, signature) — signature verification
    → StripeWebhookService::handleEvent(event)
      → Insert stripe_webhook_events (unique constraint = idempotency)
      → match($event->type) → handler method
      → UsageTrackingService::recordPayment() (for payment events)
    → Return 200
```

## Idempotency

Every event is recorded in `stripe_webhook_events` with its Stripe event ID. The table has a unique constraint on `stripe_event_id`. Duplicate deliveries trigger a `UniqueConstraintViolationException` and are silently skipped (return 200).

```php
// In StripeWebhookService::handleEvent()
try {
    $record = StripeWebhookEvent::create([
        'stripe_event_id' => $event->id,
        'event_type' => $event->type,
        'status' => 'processed',
        'payload' => $event->toArray(),
    ]);
} catch (UniqueConstraintViolationException $e) {
    return ['handled' => false, 'skipped' => true, 'reason' => 'duplicate'];
}
```

## Handled Events

| Event | Handler | Action |
|-------|---------|--------|
| `payment_intent.succeeded` | `handlePaymentIntentSucceeded` | Update Payment status, record usage |
| `payment_intent.payment_failed` | `handlePaymentIntentFailed` | Update Payment status to failed |
| `charge.refunded` | `handleChargeRefunded` | Update Payment status, record negative usage |
| `account.updated` | `handleAccountUpdated` | Log account status changes |
| `account.application.deauthorized` | `handleAccountDeauthorized` | Log disconnection |

## Adding a New Event Handler

1. Add handler method `handle{EventType}()` in `StripeWebhookService`
2. Add to the `match` expression in `handleEvent()`
3. Subscribe to the event in Stripe Dashboard → Webhooks
4. Test with Stripe CLI: `stripe trigger <event>`

## Testing

Use Stripe CLI for local testing:

```bash
# Forward webhooks to local dev server
stripe listen --forward-to localhost:8080/stripe/webhook

# Trigger specific events
stripe trigger payment_intent.succeeded
stripe trigger charge.refunded
```

**Key files:** `backend/app/Services/Stripe/StripeWebhookService.php`, `backend/app/Http/Controllers/Api/StripeWebhookController.php`, `backend/app/Models/StripeWebhookEvent.php`.

**Related:** [Recipe: Handle Stripe Webhooks](../recipes/handle-stripe-webhooks.md), [ADR-026](../../adr/026-stripe-connect-integration.md).
