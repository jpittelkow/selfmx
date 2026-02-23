<?php

use App\Http\Controllers\Api\StripeConnectCallbackController;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeService;

describe('StripeConnectCallbackController', function () {

    beforeEach(function () {
        $this->stripeService = new StripeService();
        $this->connectService = new StripeConnectService($this->stripeService);
        $this->settingService = $this->createMock(SettingService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->controller = new StripeConnectCallbackController(
            $this->connectService,
            $this->settingService,
            $this->auditService,
        );
    });

    it('redirects with invalid_state error when state does not match', function () {
        config(['app.frontend_url' => 'http://localhost:3000']);

        $this->settingService
            ->method('get')
            ->with('stripe', 'connect_onboarding_state')
            ->willReturn('correct_state');

        $request = Illuminate\Http\Request::create('/api/stripe/connect/callback', 'GET', [
            'code' => 'ac_123',
            'state' => 'wrong_state',
        ]);

        $response = $this->controller->handle($request);

        expect($response->getTargetUrl())->toContain('error=invalid_state');
    });

    it('redirects with invalid_state error when state is empty', function () {
        config(['app.frontend_url' => 'http://localhost:3000']);

        $this->settingService
            ->method('get')
            ->with('stripe', 'connect_onboarding_state')
            ->willReturn('stored_state');

        $request = Illuminate\Http\Request::create('/api/stripe/connect/callback', 'GET', [
            'code' => 'ac_123',
        ]);

        $response = $this->controller->handle($request);

        expect($response->getTargetUrl())->toContain('error=invalid_state');
    });

    it('redirects with error when Stripe returns an error', function () {
        config(['app.frontend_url' => 'http://localhost:3000']);

        $this->settingService
            ->method('get')
            ->with('stripe', 'connect_onboarding_state')
            ->willReturn('valid_state');

        $request = Illuminate\Http\Request::create('/api/stripe/connect/callback', 'GET', [
            'error' => 'access_denied',
            'error_description' => 'The user denied access',
            'state' => 'valid_state',
        ]);

        $response = $this->controller->handle($request);

        expect($response->getTargetUrl())->toContain('error=');
        expect($response->getTargetUrl())->toContain('denied');
    });

    it('redirects with missing_code error when code is absent', function () {
        config(['app.frontend_url' => 'http://localhost:3000']);

        $this->settingService
            ->method('get')
            ->with('stripe', 'connect_onboarding_state')
            ->willReturn('valid_state');

        $request = Illuminate\Http\Request::create('/api/stripe/connect/callback', 'GET', [
            'state' => 'valid_state',
        ]);

        $response = $this->controller->handle($request);

        expect($response->getTargetUrl())->toContain('error=missing_code');
    });
});
