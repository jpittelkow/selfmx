<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StripeConnectController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private StripeConnectService $connectService,
        private SettingService $settingService,
        private AuditService $auditService,
    ) {}

    /**
     * Get connected account status.
     */
    public function status(): JsonResponse
    {

        $accountId = $this->settingService->get('stripe', 'connected_account_id');

        if (empty($accountId)) {
            return $this->dataResponse(['connected' => false]);
        }

        $result = $this->connectService->getAccountStatus($accountId);

        if (! $result['success']) {
            return $this->errorResponse($result['error'] ?? 'Failed to retrieve account status', 500);
        }

        return $this->dataResponse([
            'connected' => true,
            'account_id' => $accountId,
            'status' => $result['status'],
            'details_submitted' => $result['details_submitted'],
            'charges_enabled' => $result['charges_enabled'],
            'payouts_enabled' => $result['payouts_enabled'],
        ]);
    }

    /**
     * Generate a Stripe OAuth authorization URL for Connect onboarding.
     */
    public function createOAuthLink(Request $request): JsonResponse
    {

        $clientId = config('stripe.platform_client_id');
        if (empty($clientId)) {
            return $this->errorResponse('Stripe platform client ID is not configured', 422);
        }

        $state = Str::random(40);
        $this->settingService->set('stripe', 'connect_onboarding_state', $state, $request->user()->id);

        $appUrl = rtrim(config('app.url'), '/');
        $callbackUrl = $appUrl . '/api/stripe/connect/callback';

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'scope' => 'read_write',
            'state' => $state,
            'redirect_uri' => $callbackUrl,
        ]);

        $oauthUrl = 'https://connect.stripe.com/oauth/authorize?' . $query;

        return $this->dataResponse(['url' => $oauthUrl]);
    }

    /**
     * Create an account link for an already-connected but incomplete account.
     */
    public function createAccountLink(): JsonResponse
    {

        $accountId = $this->settingService->get('stripe', 'connected_account_id');
        if (empty($accountId)) {
            return $this->errorResponse('No connected account found', 422);
        }

        $result = $this->connectService->createAccountLink($accountId, 'account_update');

        if (! $result['success']) {
            return $this->errorResponse($result['error'] ?? 'Failed to create account link', 500);
        }

        return $this->dataResponse(['url' => $result['url']]);
    }

    /**
     * Get the Stripe Dashboard URL for the connected Standard account.
     */
    public function createLoginLink(): JsonResponse
    {

        $accountId = $this->settingService->get('stripe', 'connected_account_id');
        if (empty($accountId)) {
            return $this->errorResponse('No connected account found', 422);
        }

        $result = $this->connectService->getDashboardUrl($accountId);

        if (! $result['success']) {
            return $this->errorResponse($result['error'] ?? 'Failed to get dashboard URL', 500);
        }

        return $this->dataResponse(['url' => $result['url']]);
    }

    /**
     * Disconnect the connected Stripe account.
     */
    public function disconnect(Request $request): JsonResponse
    {

        $accountId = $this->settingService->get('stripe', 'connected_account_id');
        if (empty($accountId)) {
            return $this->errorResponse('No connected account found', 422);
        }

        $result = $this->connectService->disconnectAccount($accountId);

        if (! $result['success']) {
            return $this->errorResponse($result['error'] ?? 'Failed to disconnect account', 500);
        }

        $userId = $request->user()->id;
        $this->settingService->set('stripe', 'connected_account_id', null, $userId);
        $this->settingService->set('stripe', 'connect_onboarding_state', null, $userId);

        $this->auditService->log('stripe_connect.disconnected', null, [], ['account_id' => $accountId], $userId);

        return $this->successResponse('Stripe account disconnected successfully');
    }
}
