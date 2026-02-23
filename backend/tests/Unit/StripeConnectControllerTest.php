<?php

use App\Http\Controllers\Api\StripeConnectController;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeService;

describe('StripeConnectController', function () {

    beforeEach(function () {
        $this->stripeService = new StripeService();
        $this->connectService = new StripeConnectService($this->stripeService);
        $this->settingService = $this->createMock(SettingService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->controller = new StripeConnectController(
            $this->connectService,
            $this->settingService,
            $this->auditService,
        );
    });

    describe('status', function () {
        it('returns connected false when no account stored', function () {
            $this->settingService
                ->method('get')
                ->with('stripe', 'connected_account_id')
                ->willReturn(null);

            $response = $this->controller->status();
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data['connected'])->toBeFalse();
        });
    });

    describe('createOAuthLink', function () {
        it('returns 422 when platform client id is not configured', function () {
            config(['stripe.platform_client_id' => null]);

            $request = Illuminate\Http\Request::create('/api/stripe/connect/oauth-link', 'POST');
            $request->setUserResolver(fn () => createAdminUser());

            $response = $this->controller->createOAuthLink($request);

            expect($response->getStatusCode())->toBe(422);
        });

        it('returns oauth url when configured', function () {
            config(['stripe.platform_client_id' => 'ca_test123', 'app.url' => 'http://localhost']);

            $this->settingService->method('set');

            $request = Illuminate\Http\Request::create('/api/stripe/connect/oauth-link', 'POST');
            $request->setUserResolver(fn () => createAdminUser());

            $response = $this->controller->createOAuthLink($request);
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data['url'])->toContain('connect.stripe.com/oauth/authorize');
            expect($data['url'])->toContain('ca_test123');
            expect($data['url'])->toContain('redirect_uri=');
        });
    });

    describe('createAccountLink', function () {
        it('returns 422 when no account connected', function () {
            $this->settingService
                ->method('get')
                ->with('stripe', 'connected_account_id')
                ->willReturn(null);

            $response = $this->controller->createAccountLink();

            expect($response->getStatusCode())->toBe(422);
        });
    });

    describe('createLoginLink', function () {
        it('returns 422 when no account connected', function () {
            $this->settingService
                ->method('get')
                ->with('stripe', 'connected_account_id')
                ->willReturn(null);

            $response = $this->controller->createLoginLink();

            expect($response->getStatusCode())->toBe(422);
        });
    });

    describe('disconnect', function () {
        it('returns 422 when no account connected', function () {
            $this->settingService
                ->method('get')
                ->with('stripe', 'connected_account_id')
                ->willReturn(null);

            $request = Illuminate\Http\Request::create('/api/stripe/connect/disconnect', 'DELETE');
            $request->setUserResolver(fn () => createAdminUser());

            $response = $this->controller->disconnect($request);

            expect($response->getStatusCode())->toBe(422);
        });
    });
});
