<?php

use App\Http\Controllers\Api\StripeSettingController;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\Stripe\StripeService;

describe('StripeSettingController', function () {

    beforeEach(function () {
        $this->stripeService = $this->createMock(StripeService::class);
        $this->settingService = $this->createMock(SettingService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->controller = new StripeSettingController(
            $this->stripeService,
            $this->settingService,
            $this->auditService,
        );
    });

    describe('show', function () {
        it('returns settings with masked encrypted fields', function () {
            $this->settingService
                ->method('getGroup')
                ->with('stripe')
                ->willReturn([
                    'enabled' => true,
                    'secret_key' => 'sk_test_real_key',
                    'publishable_key' => 'pk_test_123',
                    'webhook_secret' => 'whsec_real_secret',
                    'platform_account_id' => 'acct_123',
                    'platform_client_id' => 'ca_123',
                    'application_fee_percent' => 1.0,
                    'currency' => 'usd',
                    'mode' => 'test',
                    'connected_account_id' => 'acct_connected',
                    'connect_onboarding_state' => 'some_state',
                ]);

            $response = $this->controller->show();
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data['settings']['secret_key'])->toBe('••••••••');
            expect($data['settings']['webhook_secret'])->toBe('••••••••');
            expect($data['settings']['publishable_key'])->toBe('pk_test_123');
            // Connect keys should be excluded
            expect(array_key_exists('connected_account_id', $data['settings']))->toBeFalse();
            expect(array_key_exists('connect_onboarding_state', $data['settings']))->toBeFalse();
        });

        it('returns null for empty encrypted fields instead of mask', function () {
            $this->settingService
                ->method('getGroup')
                ->with('stripe')
                ->willReturn([
                    'enabled' => false,
                    'secret_key' => null,
                    'publishable_key' => null,
                    'webhook_secret' => '',
                    'currency' => 'usd',
                    'mode' => 'test',
                ]);

            $response = $this->controller->show();
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data['settings']['secret_key'])->toBeNull();
            expect($data['settings']['webhook_secret'])->toBe('');
        });
    });

    describe('update', function () {
        it('updates settings and logs audit', function () {
            $oldSettings = ['currency' => 'usd', 'mode' => 'test'];
            $this->settingService
                ->method('getGroup')
                ->with('stripe')
                ->willReturn($oldSettings);

            $this->settingService
                ->expects($this->exactly(2))
                ->method('set');

            $this->auditService
                ->expects($this->once())
                ->method('logSettings');

            $request = Illuminate\Http\Request::create('/api/stripe/settings', 'PUT', [
                'currency' => 'eur',
                'mode' => 'live',
            ]);
            $request->setUserResolver(fn () => createAdminUser());

            $response = $this->controller->update($request);

            expect($response->getStatusCode())->toBe(200);
        });

        it('skips masked placeholder values', function () {
            $this->settingService
                ->method('getGroup')
                ->willReturn(['secret_key' => 'sk_real']);

            // Should only set currency, not the masked secret_key
            $this->settingService
                ->expects($this->once())
                ->method('set')
                ->with('stripe', 'currency', 'eur', $this->anything());

            $request = Illuminate\Http\Request::create('/api/stripe/settings', 'PUT', [
                'secret_key' => '••••••••',
                'currency' => 'eur',
            ]);
            $request->setUserResolver(fn () => createAdminUser());

            $this->controller->update($request);
        });
    });

    describe('testConnection', function () {
        it('returns success with account id', function () {
            $this->stripeService
                ->method('testConnection')
                ->willReturn(['success' => true, 'account_id' => 'acct_123']);

            $response = $this->controller->testConnection();
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data['message'])->toBe('Connection successful');
            expect($data['account_id'])->toBe('acct_123');
        });

        it('returns error on failure', function () {
            $this->stripeService
                ->method('testConnection')
                ->willReturn(['success' => false, 'error' => 'Invalid API key']);

            $response = $this->controller->testConnection();
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(400);
            expect($data['message'])->toContain('Invalid API key');
        });
    });

    describe('reset', function () {
        it('returns 422 for unknown setting key', function () {
            config(['settings-schema.stripe' => ['currency' => ['default' => 'usd']]]);

            $request = Illuminate\Http\Request::create('/api/stripe/settings/keys/nonexistent', 'DELETE');
            $request->setUserResolver(fn () => createAdminUser());

            $response = $this->controller->reset($request, 'nonexistent');

            expect($response->getStatusCode())->toBe(422);
        });

        it('resets a valid key', function () {
            config(['settings-schema.stripe' => ['currency' => ['default' => 'usd']]]);

            $this->settingService
                ->expects($this->once())
                ->method('reset')
                ->with('stripe', 'currency');

            $request = Illuminate\Http\Request::create('/api/stripe/settings/keys/currency', 'DELETE');
            $request->setUserResolver(fn () => createAdminUser());

            $response = $this->controller->reset($request, 'currency');

            expect($response->getStatusCode())->toBe(200);
        });
    });
});
