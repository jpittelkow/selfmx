<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Payment;
use App\Models\StripeCustomer;
use App\Services\SettingService;
use App\Services\Stripe\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripePaymentController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private StripeService $stripeService,
        private SettingService $settingService
    ) {}

    /**
     * List the authenticated user's payments.
     */
    public function index(Request $request): JsonResponse
    {

        $payments = Payment::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->dataResponse($payments);
    }

    /**
     * Show a single payment (user must own it or have payments.manage).
     */
    public function show(Request $request, Payment $payment): JsonResponse
    {

        if ($payment->user_id !== $request->user()->id && ! $request->user()->can('payments.manage')) {
            return $this->errorResponse('Not found', 404);
        }

        return $this->dataResponse(['payment' => $payment]);
    }

    /**
     * Create a Stripe payment intent (destination charge to connected account).
     */
    public function createIntent(Request $request): JsonResponse
    {

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:50'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $connectedAccountId = $this->settingService->get('stripe', 'connected_account_id');
        if (empty($connectedAccountId)) {
            return $this->errorResponse('No connected Stripe account configured', 422);
        }

        // Get or create Stripe customer for this user
        $customerResult = $this->stripeService->createCustomer($request->user());
        if (! $customerResult['success']) {
            return $this->errorResponse($customerResult['error'] ?? 'Failed to create Stripe customer', 500);
        }

        $currency = $validated['currency'] ?? config('stripe.currency', 'usd');

        $result = $this->stripeService->createPaymentIntent([
            'amount' => $validated['amount'],
            'currency' => $currency,
            'connected_account_id' => $connectedAccountId,
            'customer_id' => $customerResult['customer_id'],
            'description' => $validated['description'] ?? null,
            'metadata' => $validated['metadata'] ?? [],
        ]);

        if (! $result['success']) {
            return $this->errorResponse($result['error'] ?? 'Failed to create payment intent', 500);
        }

        // Look up the local StripeCustomer record to get its DB ID for the foreign key
        $stripeCustomer = StripeCustomer::where('stripe_customer_id', $customerResult['customer_id'])->first();

        $feePercent = (float) config('stripe.application_fee_percent', 1.0);

        $payment = Payment::create([
            'user_id' => $request->user()->id,
            'stripe_customer_id' => $stripeCustomer?->id,
            'stripe_payment_intent_id' => $result['payment_intent_id'],
            'amount' => $validated['amount'],
            'currency' => $currency,
            'status' => 'requires_payment_method',
            'description' => $validated['description'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'stripe_account_id' => $connectedAccountId,
            'application_fee_amount' => (int) round($validated['amount'] * ($feePercent / 100)),
        ]);

        return $this->dataResponse([
            'payment_id' => $payment->id,
            'client_secret' => $result['client_secret'],
        ], 201);
    }

    /**
     * List all payments across all users (admin).
     */
    public function adminIndex(Request $request): JsonResponse
    {

        $payments = Payment::with('user')
            ->orderByDesc('created_at')
            ->paginate(50);

        return $this->dataResponse($payments);
    }
}
