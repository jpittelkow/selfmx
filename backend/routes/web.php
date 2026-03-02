<?php

use App\Http\Controllers\Api\SSOController;
use App\Http\Controllers\Api\StripeConnectCallbackController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| selfmx uses a separate Next.js frontend, so web routes are minimal.
| API routes are defined in routes/api.php
|
*/

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'version' => config('version.version'),
        'api' => url('/api'),
    ]);
});

// Broadcasting authentication (for Laravel Echo)
// This route is used by the frontend to authenticate private channel subscriptions
Broadcast::routes(['middleware' => ['auth:sanctum']]);

/*
|--------------------------------------------------------------------------
| SSO Browser Navigation Routes
|--------------------------------------------------------------------------
|
| These routes handle browser redirects for OAuth SSO authentication.
| They MUST use the 'web' middleware group (not 'api') because:
|
|   1. The redirect route stores a CSRF state token (in cache) and the
|      callback route logs the user in via Auth::login() (session-based).
|   2. The callback from the OAuth provider (e.g. accounts.google.com)
|      has a Referer from the external provider, NOT from the app frontend.
|   3. Sanctum's EnsureFrontendRequestsAreStateful only activates session
|      middleware when the Referer matches a stateful domain — which fails
|      for external OAuth callbacks.
|   4. Without EncryptCookies, the session cookie set during the redirect
|      step can't be decrypted on the callback step, causing state_not_found.
|   5. The 'web' middleware group ALWAYS includes EncryptCookies + StartSession,
|      ensuring the session cookie works on both legs of the OAuth flow.
|
| These routes use the '/api/auth' prefix to match the existing frontend URLs
| and the nginx proxy configuration (which routes /api/* to PHP-FPM).
|
| DO NOT move these routes back to routes/api.php — the OAuth callback will
| break with a state_not_found error for all SSO providers.
|
| See: docs/journal/2026-02-06-sso-session-persistence-fix.md
*/
Route::prefix('api/auth')->middleware('throttle:10,1')->group(function () {
    // Regex constraint excludes 'providers' to avoid shadowing the
    // GET /api/auth/sso/providers JSON endpoint defined in routes/api.php.
    Route::get('/sso/{provider}', [SSOController::class, 'redirect'])
        ->where('provider', '^(?!providers$).+');
    Route::get('/callback/{provider}', [SSOController::class, 'callback']);
});

/*
|--------------------------------------------------------------------------
| Stripe Connect OAuth Callback
|--------------------------------------------------------------------------
|
| This route handles the browser redirect from Stripe after the fork operator
| authorizes the Connect integration. It uses 'web' middleware (not 'api')
| for the same reasons as SSO callbacks above: it receives an external redirect
| and must produce a RedirectResponse to the frontend.
|
| The route is NOT auth-protected because Stripe redirects the browser here
| after the external OAuth flow. CSRF is handled via a state parameter stored
| in settings (not session-based, since the session may expire during onboarding).
|
*/
Route::get('/api/stripe/connect/callback', [StripeConnectCallbackController::class, 'handle'])
    ->middleware('throttle:10,1');
