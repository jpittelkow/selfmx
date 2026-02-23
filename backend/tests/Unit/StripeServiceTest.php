<?php

use App\Models\StripeCustomer;
use App\Services\Stripe\StripeService;

/**
 * Helper: inject a mock StripeClient into the service's private $client property.
 */
function injectMockClient(StripeService $service, object $mockClient): void
{
    $ref = new ReflectionProperty($service, 'client');
    $ref->setAccessible(true);
    $ref->setValue($service, $mockClient);
}

/**
 * Helper: create a StripeService with Stripe enabled and a mock client injected.
 */
function createEnabledServiceWithMock(): array
{
    config(['stripe.secret_key' => 'sk_test_123']);
    config(['stripe.currency' => 'usd']);
    config(['stripe.application_fee_percent' => 1.0]);

    $service = new StripeService();
    $mockClient = Mockery::mock(\Stripe\StripeClient::class)->makePartial();
    injectMockClient($service, $mockClient);

    return [$service, $mockClient];
}

describe('StripeService', function () {

    beforeEach(function () {
        $this->service = new StripeService();
    });

    describe('isEnabled', function () {
        it('returns false when secret key is missing', function () {
            config(['stripe.secret_key' => null]);

            expect($this->service->isEnabled())->toBeFalse();
        });

        it('returns false when secret key is empty string', function () {
            config(['stripe.secret_key' => '']);

            expect($this->service->isEnabled())->toBeFalse();
        });

        it('returns true when secret key is present', function () {
            config(['stripe.secret_key' => 'sk_test_123']);

            expect($this->service->isEnabled())->toBeTrue();
        });
    });

    describe('createPaymentIntent', function () {
        it('returns error when amount is zero', function () {
            config(['stripe.secret_key' => 'sk_test_123']);

            $result = $this->service->createPaymentIntent([
                'amount' => 0,
                'connected_account_id' => 'acct_123',
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('Amount must be a positive integer');
        });

        it('returns error when amount is negative', function () {
            config(['stripe.secret_key' => 'sk_test_123']);

            $result = $this->service->createPaymentIntent([
                'amount' => -500,
                'connected_account_id' => 'acct_123',
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('Amount must be a positive integer');
        });

        it('returns error when connected account id is missing', function () {
            config(['stripe.secret_key' => 'sk_test_123']);

            $result = $this->service->createPaymentIntent([
                'amount' => 1000,
                'connected_account_id' => '',
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('Connected account ID is required');
        });
    });

    describe('testConnection (mocked client)', function () {
        it('returns success with account id on successful connection', function () {
            [$service, $mockClient] = createEnabledServiceWithMock();

            $mockAccounts = Mockery::mock();
            $mockAccounts->shouldReceive('retrieve')
                ->with('self')
                ->once()
                ->andReturn(\Stripe\Account::constructFrom(['id' => 'acct_platform_123']));
            $mockClient->accounts = $mockAccounts;

            $result = $service->testConnection();

            expect($result['success'])->toBeTrue();
            expect($result['account_id'])->toBe('acct_platform_123');
        });

        it('returns error when stripe API throws', function () {
            [$service, $mockClient] = createEnabledServiceWithMock();

            $mockAccounts = Mockery::mock();
            $mockAccounts->shouldReceive('retrieve')
                ->with('self')
                ->once()
                ->andThrow(new \Stripe\Exception\AuthenticationException('Invalid API Key'));
            $mockClient->accounts = $mockAccounts;

            $result = $service->testConnection();

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('Invalid API Key');
        });
    });

    describe('createCustomer (mocked client)', function () {
        it('creates a new stripe customer and persists it', function () {
            [$service, $mockClient] = createEnabledServiceWithMock();

            $user = createUser(['email' => 'stripe@example.com', 'name' => 'Stripe User']);

            $mockCustomers = Mockery::mock();
            $mockCustomers->shouldReceive('create')
                ->once()
                ->with(Mockery::on(function ($params) use ($user) {
                    return $params['email'] === 'stripe@example.com'
                        && $params['name'] === 'Stripe User'
                        && $params['metadata']['user_id'] === $user->id;
                }))
                ->andReturn(\Stripe\Customer::constructFrom([
                    'id' => 'cus_mock_123',
                    'email' => 'stripe@example.com',
                ]));
            $mockClient->customers = $mockCustomers;

            $result = $service->createCustomer($user);

            expect($result['success'])->toBeTrue();
            expect($result['customer_id'])->toBe('cus_mock_123');

            $this->assertDatabaseHas('stripe_customers', [
                'user_id' => $user->id,
                'stripe_customer_id' => 'cus_mock_123',
            ]);
        });

        it('returns existing customer without calling stripe API', function () {
            [$service, $mockClient] = createEnabledServiceWithMock();

            $user = createUser();
            StripeCustomer::create([
                'user_id' => $user->id,
                'stripe_customer_id' => 'cus_existing_456',
            ]);

            $result = $service->createCustomer($user);

            expect($result['success'])->toBeTrue();
            expect($result['customer_id'])->toBe('cus_existing_456');
        });
    });

    describe('createPaymentIntent (mocked client)', function () {
        it('creates a payment intent with destination charge and application fee', function () {
            [$service, $mockClient] = createEnabledServiceWithMock();

            $mockPaymentIntents = Mockery::mock();
            $mockPaymentIntents->shouldReceive('create')
                ->once()
                ->with(Mockery::on(function ($params) {
                    return $params['amount'] === 5000
                        && $params['currency'] === 'usd'
                        && $params['application_fee_amount'] === 50  // 1% of 5000
                        && $params['transfer_data']['destination'] === 'acct_connected_123';
                }))
                ->andReturn(\Stripe\PaymentIntent::constructFrom([
                    'id' => 'pi_mock_789',
                    'client_secret' => 'pi_mock_789_secret_abc',
                ]));
            $mockClient->paymentIntents = $mockPaymentIntents;

            $result = $service->createPaymentIntent([
                'amount' => 5000,
                'connected_account_id' => 'acct_connected_123',
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['payment_intent_id'])->toBe('pi_mock_789');
            expect($result['client_secret'])->toBe('pi_mock_789_secret_abc');
        });

        it('includes optional params when provided', function () {
            [$service, $mockClient] = createEnabledServiceWithMock();

            $mockPaymentIntents = Mockery::mock();
            $mockPaymentIntents->shouldReceive('create')
                ->once()
                ->with(Mockery::on(function ($params) {
                    return $params['customer'] === 'cus_123'
                        && $params['description'] === 'Test payment'
                        && $params['metadata']['order_id'] === 'order_1';
                }))
                ->andReturn(\Stripe\PaymentIntent::constructFrom([
                    'id' => 'pi_mock_opt',
                    'client_secret' => 'pi_mock_opt_secret',
                ]));
            $mockClient->paymentIntents = $mockPaymentIntents;

            $result = $service->createPaymentIntent([
                'amount' => 2000,
                'connected_account_id' => 'acct_123',
                'customer_id' => 'cus_123',
                'description' => 'Test payment',
                'metadata' => ['order_id' => 'order_1'],
            ]);

            expect($result['success'])->toBeTrue();
        });
    });

    describe('refund (mocked client)', function () {
        it('creates a full refund for a payment intent', function () {
            [$service, $mockClient] = createEnabledServiceWithMock();

            $mockRefunds = Mockery::mock();
            $mockRefunds->shouldReceive('create')
                ->once()
                ->with(Mockery::on(function ($params) {
                    return $params['payment_intent'] === 'pi_to_refund'
                        && ! isset($params['amount']);
                }))
                ->andReturn(\Stripe\Refund::constructFrom([
                    'id' => 're_mock_001',
                ]));
            $mockClient->refunds = $mockRefunds;

            $result = $service->refund('pi_to_refund');

            expect($result['success'])->toBeTrue();
            expect($result['refund_id'])->toBe('re_mock_001');
        });

        it('creates a partial refund with specified amount', function () {
            [$service, $mockClient] = createEnabledServiceWithMock();

            $mockRefunds = Mockery::mock();
            $mockRefunds->shouldReceive('create')
                ->once()
                ->with(Mockery::on(function ($params) {
                    return $params['payment_intent'] === 'pi_partial'
                        && $params['amount'] === 500;
                }))
                ->andReturn(\Stripe\Refund::constructFrom([
                    'id' => 're_mock_partial',
                ]));
            $mockClient->refunds = $mockRefunds;

            $result = $service->refund('pi_partial', 500);

            expect($result['success'])->toBeTrue();
            expect($result['refund_id'])->toBe('re_mock_partial');
        });
    });
});
