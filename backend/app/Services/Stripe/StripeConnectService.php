<?php

namespace App\Services\Stripe;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class StripeConnectService
{
    public function __construct(
        private StripeService $stripeService,
    ) {}

    /**
     * Create a Standard connected account for a user.
     *
     * @return array{success: bool, account_id?: string, error?: string}
     */
    public function createAccount(User $user): array
    {
        if (! $this->stripeService->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled'];
        }

        try {
            $client = $this->stripeService->getClient();
            $account = $client->accounts->create([
                'type' => 'standard',
                'email' => $user->email,
                'metadata' => [
                    'user_id' => $user->id,
                ],
            ]);

            return [
                'success' => true,
                'account_id' => $account->id,
            ];
        } catch (\Throwable $e) {
            Log::warning('Stripe Connect account creation failed', [
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
     * Exchange an OAuth authorization code for a connected account ID.
     *
     * Called after Stripe redirects back to our callback URL with ?code=.
     * Returns the stripe_user_id (connected account ID, e.g. acct_1ABC...).
     *
     * @return array{success: bool, stripe_user_id?: string, error?: string}
     */
    public function exchangeOAuthCode(string $code): array
    {
        if (! $this->stripeService->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled'];
        }

        try {
            $client = $this->stripeService->getClient();
            $response = $client->oauth->token([
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);

            return [
                'success' => true,
                'stripe_user_id' => $response->stripe_user_id,
            ];
        } catch (\Throwable $e) {
            Log::warning('Stripe Connect OAuth code exchange failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create an account link for onboarding or updating a connected account.
     *
     * @param  string  $type  'account_onboarding' or 'account_update'
     * @return array{success: bool, url?: string, error?: string}
     */
    public function createAccountLink(string $accountId, string $type = 'account_onboarding'): array
    {
        if (! $this->stripeService->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled'];
        }

        try {
            $client = $this->stripeService->getClient();
            $appUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

            $link = $client->accountLinks->create([
                'account' => $accountId,
                'refresh_url' => $appUrl . '/configuration/stripe?refresh=true',
                'return_url' => $appUrl . '/configuration/stripe?onboarding=complete',
                'type' => $type,
            ]);

            return [
                'success' => true,
                'url' => $link->url,
            ];
        } catch (\Throwable $e) {
            Log::warning('Stripe Connect account link creation failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get the Stripe Dashboard URL for a Standard connected account.
     *
     * Standard accounts manage their own dashboard at dashboard.stripe.com
     * (unlike Express/Custom accounts which use login links).
     *
     * @return array{success: bool, url?: string, error?: string}
     */
    public function getDashboardUrl(string $accountId): array
    {
        if (! $this->stripeService->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled'];
        }

        return [
            'success' => true,
            'url' => 'https://dashboard.stripe.com',
        ];
    }

    /**
     * Get the status of a connected account.
     *
     * @return array{success: bool, status?: string, details_submitted?: bool, charges_enabled?: bool, payouts_enabled?: bool, error?: string}
     */
    public function getAccountStatus(string $accountId): array
    {
        if (! $this->stripeService->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled'];
        }

        try {
            $client = $this->stripeService->getClient();
            $account = $client->accounts->retrieve($accountId);

            $status = 'pending';
            if ($account->charges_enabled && $account->payouts_enabled) {
                $status = 'active';
            } elseif ($account->details_submitted) {
                $status = 'pending_verification';
            }

            return [
                'success' => true,
                'status' => $status,
                'details_submitted' => $account->details_submitted,
                'charges_enabled' => $account->charges_enabled,
                'payouts_enabled' => $account->payouts_enabled,
            ];
        } catch (\Throwable $e) {
            Log::warning('Stripe Connect account status check failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Disconnect (deauthorize) a connected Standard account via OAuth.
     *
     * Uses the OAuth deauthorize endpoint rather than account deletion,
     * which is the correct approach for Standard connected accounts.
     *
     * @return array{success: bool, error?: string}
     */
    public function disconnectAccount(string $accountId): array
    {
        if (! $this->stripeService->isEnabled()) {
            return ['success' => false, 'error' => 'Stripe is not enabled'];
        }

        try {
            $client = $this->stripeService->getClient();
            $client->oauth->deauthorize([
                'client_id' => config('stripe.platform_client_id'),
                'stripe_user_id' => $accountId,
            ]);

            return ['success' => true];
        } catch (\Throwable $e) {
            Log::warning('Stripe Connect account disconnect failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
