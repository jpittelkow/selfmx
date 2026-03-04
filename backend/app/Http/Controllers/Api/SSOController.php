<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\Auth\SSOService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SSOController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private SSOService $ssoService,
        private AuditService $auditService
    ) {}

    /**
     * Get available SSO providers.
     */
    public function providers(): JsonResponse
    {
        return $this->dataResponse([
            'providers' => $this->ssoService->getAvailableProviders(),
            'sso_enabled' => $this->ssoService->isEnabled(),
        ]);
    }

    /**
     * Redirect to SSO provider.
     */
    public function redirect(string $provider): RedirectResponse|JsonResponse
    {
        if (!$this->ssoService->isValidProvider($provider)) {
            return $this->errorResponse('Invalid or disabled SSO provider', 400);
        }

        return redirect($this->ssoService->getRedirectUrl($provider));
    }

    /**
     * Handle SSO callback.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        if (!$this->ssoService->isValidProvider($provider)) {
            return $this->redirectToFrontend('error=invalid_provider');
        }

        // Validate OAuth state token for CSRF protection
        $stateValidation = $this->ssoService->validateStateToken(
            $provider,
            $request->input('state')
        );

        if (!$stateValidation['valid']) {
            return $this->redirectToFrontend('error=' . ($stateValidation['error'] ?? 'invalid_state'));
        }

        $result = $this->ssoService->handleCallback($provider);

        if (!$result['success']) {
            return $this->redirectToFrontend('error=' . $result['error']);
        }

        // Login the user
        Auth::login($result['user'], true);

        $this->auditService->logAuth('sso_login', $result['user'], ['provider' => $provider]);

        $params = 'success=true';
        if ($result['action'] === 'linked') {
            $params .= '&linked=true';
        } elseif ($result['action'] === 'registered') {
            $params .= '&registered=true';
        }

        return $this->redirectToFrontend($params);
    }

    /**
     * Link SSO provider to authenticated user.
     */
    public function link(Request $request, string $provider): JsonResponse
    {
        if (!$this->ssoService->isValidProvider($provider)) {
            return $this->errorResponse('Invalid or disabled SSO provider', 400);
        }

        $user = $request->user();

        // Check if already linked
        if ($user->socialAccounts()->where('provider', $provider)->exists()) {
            return $this->errorResponse('Provider already linked', 400);
        }

        // Return redirect URL for frontend to use
        $redirectUrl = $this->ssoService->getRedirectUrl($provider, 'link:' . $user->id);

        return $this->dataResponse([
            'redirect_url' => $redirectUrl,
        ]);
    }

    /**
     * Unlink SSO provider from authenticated user.
     */
    public function unlink(Request $request, string $provider): JsonResponse
    {
        try {
            $this->ssoService->unlinkProvider($request->user(), $provider);

            return $this->successResponse('Provider unlinked successfully');
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Get linked social accounts for the authenticated user.
     */
    public function linkedAccounts(Request $request): JsonResponse
    {
        $accounts = $this->ssoService->getLinkedAccounts($request->user());

        return $this->dataResponse([
            'accounts' => $accounts,
        ]);
    }

    /**
     * Redirect to frontend with query parameters.
     */
    private function redirectToFrontend(string $params): RedirectResponse
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        return redirect("{$frontendUrl}/auth/callback?{$params}");
    }
}
