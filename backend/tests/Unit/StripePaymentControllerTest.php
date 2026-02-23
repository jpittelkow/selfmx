<?php

use App\Http\Controllers\Api\StripePaymentController;
use App\Models\Payment;
use App\Models\StripeCustomer;
use App\Services\SettingService;
use App\Services\Stripe\StripeService;

describe('StripePaymentController', function () {

    beforeEach(function () {
        $this->stripeService = $this->createMock(StripeService::class);
        $this->settingService = $this->createMock(SettingService::class);
        $this->controller = new StripePaymentController(
            $this->stripeService,
            $this->settingService,
        );
    });

    describe('index', function () {
        it('returns paginated user payments', function () {
            $user = createUser();
            $request = Illuminate\Http\Request::create('/api/payments', 'GET');
            $request->setUserResolver(fn () => $user);

            $response = $this->controller->index($request);
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data)->toHaveKey('data');
        });
    });

    describe('show', function () {
        it('returns payment when user owns it', function () {
            $user = createUser();
            $payment = Payment::factory()->create(['user_id' => $user->id]);

            $request = Illuminate\Http\Request::create('/api/payments/' . $payment->id, 'GET');
            $request->setUserResolver(fn () => $user);

            $response = $this->controller->show($request, $payment);
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data['payment']['id'])->toBe($payment->id);
        });

        it('returns 404 when user does not own payment and lacks permission', function () {
            $owner = createUser();
            $other = createUser();
            $payment = Payment::factory()->create(['user_id' => $owner->id]);

            $request = Illuminate\Http\Request::create('/api/payments/' . $payment->id, 'GET');
            $request->setUserResolver(fn () => $other);

            $response = $this->controller->show($request, $payment);

            expect($response->getStatusCode())->toBe(404);
        });
    });

    describe('createIntent', function () {
        it('returns 422 when no connected account configured', function () {
            $this->settingService
                ->method('get')
                ->with('stripe', 'connected_account_id')
                ->willReturn(null);

            $request = Illuminate\Http\Request::create('/api/payments/intent', 'POST', [
                'amount' => 1000,
            ]);
            $request->setUserResolver(fn () => createUser());

            $response = $this->controller->createIntent($request);
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(422);
            expect($data['message'])->toContain('No connected Stripe account');
        });

        it('creates payment intent and local payment record', function () {
            config(['stripe.currency' => 'usd']);
            config(['stripe.application_fee_percent' => 1.0]);

            $user = createUser();

            // Create local StripeCustomer record so foreign key works
            StripeCustomer::create([
                'user_id' => $user->id,
                'stripe_customer_id' => 'cus_123',
            ]);

            $this->settingService
                ->method('get')
                ->with('stripe', 'connected_account_id')
                ->willReturn('acct_connected_123');

            $this->stripeService
                ->method('createCustomer')
                ->willReturn(['success' => true, 'customer_id' => 'cus_123']);

            $this->stripeService
                ->method('createPaymentIntent')
                ->willReturn([
                    'success' => true,
                    'payment_intent_id' => 'pi_test_123',
                    'client_secret' => 'pi_test_123_secret',
                ]);

            $request = Illuminate\Http\Request::create('/api/payments/intent', 'POST', [
                'amount' => 1000,
                'description' => 'Test payment',
            ]);
            $request->setUserResolver(fn () => $user);

            $response = $this->controller->createIntent($request);
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(201);
            expect($data['client_secret'])->toBe('pi_test_123_secret');
            expect($data['payment_id'])->toBeGreaterThan(0);

            // Verify local Payment record was created
            $payment = Payment::find($data['payment_id']);
            expect($payment)->not->toBeNull();
            expect($payment->user_id)->toBe($user->id);
            expect($payment->stripe_payment_intent_id)->toBe('pi_test_123');
            expect($payment->amount)->toBe(1000);
            expect($payment->status)->toBe('requires_payment_method');
            expect($payment->application_fee_amount)->toBe(10); // 1% of 1000
        });

        it('returns 500 when customer creation fails', function () {
            $this->settingService
                ->method('get')
                ->with('stripe', 'connected_account_id')
                ->willReturn('acct_connected_123');

            $this->stripeService
                ->method('createCustomer')
                ->willReturn(['success' => false, 'error' => 'Stripe API error']);

            $request = Illuminate\Http\Request::create('/api/payments/intent', 'POST', [
                'amount' => 1000,
            ]);
            $request->setUserResolver(fn () => createUser());

            $response = $this->controller->createIntent($request);

            expect($response->getStatusCode())->toBe(500);
        });
    });

    describe('adminIndex', function () {
        it('returns paginated payments with user relation', function () {
            $user = createUser();
            Payment::factory()->count(3)->create(['user_id' => $user->id]);

            $request = Illuminate\Http\Request::create('/api/payments/admin', 'GET');
            $request->setUserResolver(fn () => createAdminUser());

            $response = $this->controller->adminIndex($request);
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data)->toHaveKey('data');
            expect(count($data['data']))->toBe(3);
        });
    });
});
