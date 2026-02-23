# Handle Stripe Webhooks

How to add handling for new Stripe webhook event types.

## When to Use

- You need to respond to a Stripe event that isn't currently handled (e.g., `invoice.paid`, `customer.subscription.updated`).
- You're extending the payment module with new functionality.

## Critical Principles

1. **Idempotency is automatic** — The `stripe_webhook_events` table has a unique constraint on `stripe_event_id`. Duplicate deliveries are silently skipped.
2. **Signature verification is automatic** — `StripeWebhookController` verifies the webhook signature before delegating to the service.
3. **Always return 200 quickly** — Stripe retries on non-2xx responses. Don't do slow work inline; queue it if needed.
4. **Log and record all events** — Every event is stored in `stripe_webhook_events` regardless of whether it's handled.

## Files

| File | Purpose |
|------|---------|
| `backend/app/Services/Stripe/StripeWebhookService.php` | Event dispatch and handler methods |
| `backend/app/Http/Controllers/Api/StripeWebhookController.php` | Public endpoint, signature verification |
| `backend/app/Models/StripeWebhookEvent.php` | Idempotency tracking |

## Steps

### 1. Subscribe to the Event in Stripe Dashboard

Go to Stripe Dashboard → Webhooks → your endpoint → Add events. Select the event type you want to handle (e.g., `invoice.paid`).

### 2. Add a Handler Method in StripeWebhookService

```php
// In backend/app/Services/Stripe/StripeWebhookService.php

private function handleInvoicePaid(Event $event): bool
{
    $invoice = $event->data->object;

    Log::info('Stripe invoice.paid received', [
        'invoice_id' => $invoice->id,
        'amount_paid' => $invoice->amount_paid,
        'customer' => $invoice->customer,
    ]);

    // Your business logic here...

    return true;
}
```

### 3. Register in the Event Dispatch Map

Add the new event type to the `match` expression in `handleEvent()`:

```php
$handled = match ($event->type) {
    'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
    'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
    'charge.refunded' => $this->handleChargeRefunded($event),
    'account.updated' => $this->handleAccountUpdated($event),
    'account.application.deauthorized' => $this->handleAccountDeauthorized($event),
    'invoice.paid' => $this->handleInvoicePaid($event),  // ← Add here
    default => false,
};
```

### 4. Test with Stripe CLI

```bash
# Forward webhooks to your local dev server
stripe listen --forward-to localhost:8080/stripe/webhook

# Trigger a specific event
stripe trigger invoice.paid
```

### 5. Write a Unit Test

Follow the existing test pattern in `backend/tests/Unit/StripeWebhookServiceTest.php`:

```php
public function test_handle_invoice_paid(): void
{
    $event = new Event();
    $event->id = 'evt_test_invoice_paid';
    $event->type = 'invoice.paid';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'in_test123',
            'amount_paid' => 2000,
            'customer' => 'cus_test123',
        ],
    ];

    $result = $this->webhookService->handleEvent($event);

    $this->assertTrue($result['handled']);
    $this->assertFalse($result['skipped']);
}
```

## Event Flow

```
Stripe sends POST /stripe/webhook
  → StripeWebhookController::handle()
    → constructEvent() (signature verification)
    → StripeWebhookService::handleEvent()
      → Insert into stripe_webhook_events (idempotency — UniqueConstraintViolation = skip)
      → match($event->type) → handler method
      → Return {handled, skipped, reason}
    → Return 200
```

## Checklist

- [ ] Event subscribed in Stripe Dashboard webhook settings
- [ ] Handler method added to `StripeWebhookService`
- [ ] Event type added to the `match` expression in `handleEvent()`
- [ ] Unit test written for the new handler
- [ ] Tested with Stripe CLI (`stripe trigger <event>`)

## Common Mistakes

- **❌ Not returning 200 on unhandled events** — Stripe will retry indefinitely.
- **✅ Unhandled events return `false`** — The service marks them as `skipped` and returns 200.

- **❌ Doing slow work inline** — Stripe has a 20-second timeout.
- **✅ Queue heavy operations** — Use Laravel's queue system for slow processing.

- **❌ Forgetting to subscribe in Stripe** — Your handler won't receive events.
- **✅ Always add the event in Stripe Dashboard** — Then test with `stripe trigger`.

## Related

- [ADR-026: Stripe Connect Integration](../../adr/026-stripe-connect-integration.md)
- [Pattern: Stripe Webhooks](../patterns/stripe-webhooks.md)
- [Recipe: Add Payment Flow](add-payment-flow.md)
