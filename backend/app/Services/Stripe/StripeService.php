<?php

namespace App\Services\Stripe;

use App\Models\Payment;
use App\Models\StripeCustomer;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class StripeService
{
    private ?StripeClient $client = null;

    public function isEnabled(): bool
    {
        return ! empty(config('stripe.secret_key'));
    }

    /**
     * Test connection to Stripe API by retrieving account info.
     *
     * @return array{success: bool, error?: string, account_id?: string}
     */
    public function testConnection(): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled or secret key is missing'];
        }

        try {
            $client = $this->getClient();
            $account = $client->accounts->retrieve('self');

            return [
                'success' => true,
                'account_id' => $account->id,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a Stripe customer for a user.
     *
     * @return array{success: bool, customer_id?: string, error?: string}
     */
    public function createCustomer(User $user): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled'];
        }

        $existing = StripeCustomer::where('user_id', $user->id)->first();
        if ($existing) {
            return [
                'success' => true,
                'customer_id' => $existing->stripe_customer_id,
            ];
        }

        try {
            $client = $this->getClient();
            $customer = $client->customers->create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => $user->id,
                ],
            ]);

            StripeCustomer::create([
                'user_id' => $user->id,
                'stripe_customer_id' => $customer->id,
            ]);

            return [
                'success' => true,
                'customer_id' => $customer->id,
            ];
        } catch (\Throwable $e) {
            Log::warning('Stripe customer creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a payment intent with destination charge for a connected account.
     *
     * @param  array{amount: int, currency?: string, customer_id?: string, connected_account_id: string, description?: string, metadata?: array}  $params
     * @return array{success: bool, payment_intent_id?: string, client_secret?: string, error?: string}
     */
    public function createPaymentIntent(array $params): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled'];
        }

        if (! isset($params['amount']) || $params['amount'] <= 0) {
            return ['success' => false, 'error' => 'Amount must be a positive integer (in cents)'];
        }

        if (empty($params['connected_account_id'])) {
            return ['success' => false, 'error' => 'Connected account ID is required'];
        }

        try {
            $client = $this->getClient();
            $currency = $params['currency'] ?? config('stripe.currency', 'usd');
            $feePercent = (float) config('stripe.application_fee_percent', 1.0);
            $applicationFee = (int) round($params['amount'] * ($feePercent / 100));

            $intentParams = [
                'amount' => $params['amount'],
                'currency' => $currency,
                'application_fee_amount' => $applicationFee,
                'transfer_data' => [
                    'destination' => $params['connected_account_id'],
                ],
            ];

            if (! empty($params['customer_id'])) {
                $intentParams['customer'] = $params['customer_id'];
            }
            if (! empty($params['description'])) {
                $intentParams['description'] = $params['description'];
            }
            if (! empty($params['metadata'])) {
                $intentParams['metadata'] = $params['metadata'];
            }

            $intent = $client->paymentIntents->create($intentParams);

            return [
                'success' => true,
                'payment_intent_id' => $intent->id,
                'client_secret' => $intent->client_secret,
            ];
        } catch (\Throwable $e) {
            Log::warning('Stripe payment intent creation failed', [
                'amount' => $params['amount'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Refund a payment intent (full or partial).
     *
     * @return array{success: bool, refund_id?: string, error?: string}
     */
    public function refund(string $paymentIntentId, ?int $amount = null): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled'];
        }

        try {
            $client = $this->getClient();
            $refundParams = ['payment_intent' => $paymentIntentId];

            if ($amount !== null) {
                $refundParams['amount'] = $amount;
            }

            $refund = $client->refunds->create($refundParams);

            return [
                'success' => true,
                'refund_id' => $refund->id,
            ];
        } catch (\Throwable $e) {
            Log::warning('Stripe refund failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Lazy-initialize the Stripe client.
     */
    public function getClient(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = new StripeClient(config('stripe.secret_key'));

        return $this->client;
    }
}
