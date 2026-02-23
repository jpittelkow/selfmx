# Add a Payment Flow

How to create a new payment flow (e.g., one-time charge, subscription, donation) using Sourdough's Stripe module.

## When to Use

- You need to collect a payment from a user.
- You want to create a payment intent with Stripe Connect destination charges.

## Critical Principles

1. **Always use `StripeService`** — never use the Stripe PHP SDK directly. The service handles client initialization, feature gating, connected account routing, and application fee calculation.
2. **Use destination charges** — all payments flow through Connect with `application_fee_amount`.
3. **Record via `UsageTrackingService`** — payment events should be tracked for cost visibility.
4. **Handle webhook events** — payment confirmation comes via webhooks, not synchronous responses.

## Files

| File | Purpose |
|------|---------|
| `backend/app/Services/Stripe/StripeService.php` | Payment intent creation, customer management, refunds |
| `backend/app/Http/Controllers/Api/StripePaymentController.php` | Payment API endpoints |
| `backend/app/Services/Stripe/StripeWebhookService.php` | Webhook handlers for payment events |
| `backend/app/Models/Payment.php` | Payment model |
| `frontend/lib/stripe.ts` | Stripe.js loader and hooks |

## Steps

### 1. Create a Payment Record and Intent (Backend)

```php
use App\Services\Stripe\StripeService;
use App\Models\Payment;

public function createPayment(Request $request, StripeService $stripeService)
{
    // Ensure customer exists
    $customerResult = $stripeService->createCustomer($request->user());
    if (!$customerResult['success']) {
        return response()->json(['error' => $customerResult['error']], 500);
    }

    // Get connected account ID from settings
    $connectedAccountId = config('stripe.connected_account_id');

    // Create payment intent with destination charge
    $result = $stripeService->createPaymentIntent([
        'amount' => 2000, // $20.00 in cents
        'currency' => config('stripe.currency', 'usd'),
        'customer_id' => $customerResult['customer_id'],
        'connected_account_id' => $connectedAccountId,
        'description' => 'Order #123',
        'metadata' => ['order_id' => 123],
    ]);

    if (!$result['success']) {
        return response()->json(['error' => $result['error']], 500);
    }

    // Create local payment record
    $payment = Payment::create([
        'user_id' => $request->user()->id,
        'stripe_payment_intent_id' => $result['payment_intent_id'],
        'amount' => 2000,
        'currency' => config('stripe.currency', 'usd'),
        'status' => 'pending',
        'description' => 'Order #123',
    ]);

    return response()->json([
        'client_secret' => $result['client_secret'],
        'payment_id' => $payment->id,
    ]);
}
```

### 2. Confirm Payment (Frontend)

```typescript
import { getStripe, useStripeSettings } from "@/lib/stripe";

// Load Stripe.js with the publishable key
const { data: settings } = useStripeSettings();
const stripe = await getStripe(settings.publishable_key);

// Confirm the payment with the client secret from step 1
const { error } = await stripe.confirmCardPayment(clientSecret, {
  payment_method: {
    card: cardElement, // From @stripe/react-stripe-js <CardElement>
  },
});

if (error) {
  // Show error to user
  console.error(error.message);
} else {
  // Payment submitted — wait for webhook confirmation
}
```

### 3. Handle Webhook Confirmation

The `StripeWebhookService` automatically handles `payment_intent.succeeded` and `payment_intent.payment_failed` events. When a payment succeeds:

1. The `Payment` record is updated to `status: 'succeeded'`.
2. `UsageTrackingService::recordPayment()` is called to track the payment.

If you need custom logic on payment success, add it to `handlePaymentIntentSucceeded()` in `StripeWebhookService`.

### 4. Handle Refunds

```php
// Full refund
$result = $stripeService->refund($payment->stripe_payment_intent_id);

// Partial refund (500 cents = $5.00)
$result = $stripeService->refund($payment->stripe_payment_intent_id, 500);
```

The `charge.refunded` webhook handler updates the payment status and records a negative usage entry.

## Checklist

- [ ] Payment intent created via `StripeService::createPaymentIntent()`
- [ ] Local `Payment` record created before confirming
- [ ] Frontend uses `getStripe()` to load Stripe.js
- [ ] Webhook handles `payment_intent.succeeded` and `payment_intent.payment_failed`
- [ ] Usage tracking records the payment event

## Common Mistakes

- **❌ Confirming payment without a local record** — You won't be able to match the webhook event.
- **✅ Always create a `Payment` record first** — The webhook handler finds payments by `stripe_payment_intent_id`.

- **❌ Using the Stripe SDK directly** — Bypasses feature gating, fee calculation, and connected account routing.
- **✅ Always use `StripeService`** — It handles all Stripe API calls with proper configuration.

- **❌ Treating the synchronous response as confirmation** — The payment may still be processing.
- **✅ Rely on webhooks for confirmation** — `payment_intent.succeeded` is the authoritative signal.

## Related

- [ADR-026: Stripe Connect Integration](../../adr/026-stripe-connect-integration.md)
- [Pattern: Stripe Service](../patterns/stripe-service.md)
- [Recipe: Handle Stripe Webhooks](handle-stripe-webhooks.md)
