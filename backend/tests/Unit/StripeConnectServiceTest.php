<?php

use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeService;

/**
 * Helper: create a StripeConnectService with Stripe enabled and a mock client injected.
 */
function createEnabledConnectServiceWithMock(): array
{
    config(['stripe.secret_key' => 'sk_test_123']);
    config(['stripe.platform_client_id' => 'ca_test_platform']);
    config(['app.url' => 'https://app.example.com']);

    $stripeService = new StripeService();
    $mockClient = Mockery::mock(\Stripe\StripeClient::class)->makePartial();

    $ref = new ReflectionProperty($stripeService, 'client');
    $ref->setAccessible(true);
    $ref->setValue($stripeService, $mockClient);

    $connectService = new StripeConnectService($stripeService);

    return [$connectService, $mockClient];
}

describe('StripeConnectService', function () {

    beforeEach(function () {
        $this->stripeService = new StripeService();
        $this->service = new StripeConnectService($this->stripeService);
    });

    describe('createAccount (mocked client)', function () {
        it('creates a standard connected account and returns the account id', function () {
            [$service, $mockClient] = createEnabledConnectServiceWithMock();

            $user = createUser(['email' => 'merchant@example.com']);

            $mockAccounts = Mockery::mock();
            $mockAccounts->shouldReceive('create')
                ->once()
                ->with(Mockery::on(function ($params) use ($user) {
                    return $params['type'] === 'standard'
                        && $params['email'] === 'merchant@example.com'
                        && $params['metadata']['user_id'] === $user->id;
                }))
                ->andReturn(\Stripe\Account::constructFrom([
                    'id' => 'acct_new_123',
                ]));
            $mockClient->accounts = $mockAccounts;

            $result = $service->createAccount($user);

            expect($result['success'])->toBeTrue();
            expect($result['account_id'])->toBe('acct_new_123');
        });
    });

    describe('createAccountLink (mocked client)', function () {
        it('creates an onboarding account link', function () {
            [$service, $mockClient] = createEnabledConnectServiceWithMock();

            $mockAccountLinks = Mockery::mock();
            $mockAccountLinks->shouldReceive('create')
                ->once()
                ->with(Mockery::on(function ($params) {
                    return $params['account'] === 'acct_link_test'
                        && $params['type'] === 'account_onboarding'
                        && str_contains($params['refresh_url'], '/configuration/stripe')
                        && str_contains($params['return_url'], '/configuration/stripe');
                }))
                ->andReturn(\Stripe\AccountLink::constructFrom([
                    'url' => 'https://connect.stripe.com/setup/abc123',
                ]));
            $mockClient->accountLinks = $mockAccountLinks;

            $result = $service->createAccountLink('acct_link_test');

            expect($result['success'])->toBeTrue();
            expect($result['url'])->toBe('https://connect.stripe.com/setup/abc123');
        });
    });

    describe('getDashboardUrl (Standard account)', function () {
        it('returns the Stripe Dashboard URL for Standard connected accounts', function () {
            [$service] = createEnabledConnectServiceWithMock();

            $result = $service->getDashboardUrl('acct_test');

            expect($result['success'])->toBeTrue();
            expect($result['url'])->toBe('https://dashboard.stripe.com');
        });
    });

    describe('getAccountStatus (mocked client)', function () {
        it('returns active status when charges and payouts are enabled', function () {
            [$service, $mockClient] = createEnabledConnectServiceWithMock();

            $mockAccounts = Mockery::mock();
            $mockAccounts->shouldReceive('retrieve')
                ->once()
                ->with('acct_active')
                ->andReturn(\Stripe\Account::constructFrom([
                    'id' => 'acct_active',
                    'charges_enabled' => true,
                    'payouts_enabled' => true,
                    'details_submitted' => true,
                ]));
            $mockClient->accounts = $mockAccounts;

            $result = $service->getAccountStatus('acct_active');

            expect($result['success'])->toBeTrue();
            expect($result['status'])->toBe('active');
            expect($result['charges_enabled'])->toBeTrue();
            expect($result['payouts_enabled'])->toBeTrue();
            expect($result['details_submitted'])->toBeTrue();
        });

        it('returns pending_verification when details submitted but not yet enabled', function () {
            [$service, $mockClient] = createEnabledConnectServiceWithMock();

            $mockAccounts = Mockery::mock();
            $mockAccounts->shouldReceive('retrieve')
                ->once()
                ->with('acct_pending')
                ->andReturn(\Stripe\Account::constructFrom([
                    'id' => 'acct_pending',
                    'charges_enabled' => false,
                    'payouts_enabled' => false,
                    'details_submitted' => true,
                ]));
            $mockClient->accounts = $mockAccounts;

            $result = $service->getAccountStatus('acct_pending');

            expect($result['success'])->toBeTrue();
            expect($result['status'])->toBe('pending_verification');
        });

        it('returns pending when details not yet submitted', function () {
            [$service, $mockClient] = createEnabledConnectServiceWithMock();

            $mockAccounts = Mockery::mock();
            $mockAccounts->shouldReceive('retrieve')
                ->once()
                ->with('acct_new')
                ->andReturn(\Stripe\Account::constructFrom([
                    'id' => 'acct_new',
                    'charges_enabled' => false,
                    'payouts_enabled' => false,
                    'details_submitted' => false,
                ]));
            $mockClient->accounts = $mockAccounts;

            $result = $service->getAccountStatus('acct_new');

            expect($result['success'])->toBeTrue();
            expect($result['status'])->toBe('pending');
        });
    });

    describe('exchangeOAuthCode (mocked client)', function () {
        it('exchanges an auth code and returns the connected account id', function () {
            [$service, $mockClient] = createEnabledConnectServiceWithMock();

            $mockOAuth = Mockery::mock();
            $mockOAuth->shouldReceive('token')
                ->once()
                ->with(Mockery::on(function ($params) {
                    return $params['grant_type'] === 'authorization_code'
                        && $params['code'] === 'ac_test_code';
                }))
                ->andReturn((object) ['stripe_user_id' => 'acct_oauth_123']);
            $mockClient->oauth = $mockOAuth;

            $result = $service->exchangeOAuthCode('ac_test_code');

            expect($result['success'])->toBeTrue();
            expect($result['stripe_user_id'])->toBe('acct_oauth_123');
        });
    });

    describe('disconnectAccount (mocked client)', function () {
        it('deauthorizes the connected account via OAuth', function () {
            [$service, $mockClient] = createEnabledConnectServiceWithMock();

            $mockOAuth = Mockery::mock();
            $mockOAuth->shouldReceive('deauthorize')
                ->once()
                ->with(Mockery::on(function ($params) {
                    return $params['client_id'] === 'ca_test_platform'
                        && $params['stripe_user_id'] === 'acct_disconnect';
                }))
                ->andReturn((object) ['stripe_user_id' => 'acct_disconnect']);
            $mockClient->oauth = $mockOAuth;

            $result = $service->disconnectAccount('acct_disconnect');

            expect($result['success'])->toBeTrue();
        });
    });
});
