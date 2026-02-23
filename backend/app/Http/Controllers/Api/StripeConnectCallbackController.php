<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StripeConnectCallbackController extends Controller
{
    public function __construct(
        private StripeConnectService $connectService,
        private SettingService $settingService,
        private AuditService $auditService,
    ) {}

    /**
     * Handle the Stripe Connect OAuth callback.
     *
     * Stripe redirects here after the fork operator authorizes the connection.
     * On success, stores the connected account ID in settings and redirects
     * the browser to the frontend Stripe config page.
     */
    public function handle(Request $request): RedirectResponse
    {
        // Verify CSRF state token
        $state = $request->input('state');
        $storedState = $this->settingService->get('stripe', 'connect_onboarding_state');

        if (empty($state) || empty($storedState) || ! hash_equals($storedState, $state)) {
            return $this->redirectToFrontend('error=invalid_state');
        }

        // State token validated — clear it immediately to prevent replay
        $this->settingService->set('stripe', 'connect_onboarding_state', null);

        // Check for errors from Stripe (user denied, etc.)
        if ($request->has('error')) {
            $error = $request->input('error_description', $request->input('error', 'unknown_error'));

            return $this->redirectToFrontend('error=' . urlencode($error));
        }

        $code = $request->input('code');
        if (empty($code)) {
            return $this->redirectToFrontend('error=missing_code');
        }

        // Exchange code for connected account ID
        $result = $this->connectService->exchangeOAuthCode($code);

        if (! $result['success']) {
            return $this->redirectToFrontend('error=' . urlencode($result['error'] ?? 'exchange_failed'));
        }

        $stripeUserId = $result['stripe_user_id'];

        // Persist the connected account ID (state token already cleared above)
        $this->settingService->set('stripe', 'connected_account_id', $stripeUserId);

        $this->auditService->log('stripe_connect.connected', null, [], ['account_id' => $stripeUserId]);

        return $this->redirectToFrontend('onboarding=complete&account_id=' . urlencode($stripeUserId));
    }

    private function redirectToFrontend(string $params): RedirectResponse
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return redirect("{$frontendUrl}/configuration/stripe?{$params}");
    }
}
