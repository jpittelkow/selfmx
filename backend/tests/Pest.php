<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createUser(array $attributes = []): \App\Models\User
{
    return \App\Models\User::factory()->create($attributes);
}

function createAdminUser(array $attributes = []): \App\Models\User
{
    return \App\Models\User::factory()->admin()->create($attributes);
}

/*
|--------------------------------------------------------------------------
| Search Test Helpers
|--------------------------------------------------------------------------
*/

function waitForSearch(): void
{
    if (config('scout.driver') === 'meilisearch') {
        usleep(500_000); // 500ms for Meilisearch to process indexing
    }
}

/*
|--------------------------------------------------------------------------
| GraphQL Test Helpers
|--------------------------------------------------------------------------
*/

function createApiKey(\App\Models\User $user, string $name = 'Test Key'): array
{
    return app(\App\Services\ApiKeyService::class)->create($user, $name);
}

function graphQL(string $query, array $variables = [], ?string $bearerToken = null): \Illuminate\Testing\TestResponse
{
    $headers = [];
    if ($bearerToken) {
        $headers['Authorization'] = 'Bearer ' . $bearerToken;
    }

    $response = test()->postJson('/api/graphql', [
        'query' => $query,
        'variables' => $variables,
    ], $headers);

    return $response;
}

/*
|--------------------------------------------------------------------------
| Stripe Webhook Test Helpers
|--------------------------------------------------------------------------
*/

function buildFakeStripeEvent(string $eventId, string $type, array $object): \Stripe\Event
{
    return \Stripe\Event::constructFrom([
        'id' => $eventId,
        'type' => $type,
        'data' => ['object' => $object],
    ]);
}

function buildFakePaymentIntentSucceededEvent(string $eventId, string $piId): \Stripe\Event
{
    return buildFakeStripeEvent($eventId, 'payment_intent.succeeded', [
        'id' => $piId,
        'object' => 'payment_intent',
        'amount' => 1000,
        'status' => 'succeeded',
        'last_payment_error' => null,
    ]);
}

function buildFakePaymentIntentFailedEvent(string $eventId, string $piId): \Stripe\Event
{
    return buildFakeStripeEvent($eventId, 'payment_intent.payment_failed', [
        'id' => $piId,
        'object' => 'payment_intent',
        'amount' => 1000,
        'status' => 'requires_payment_method',
        'last_payment_error' => ['message' => 'Your card was declined.'],
    ]);
}

function buildFakeChargeRefundedEvent(string $eventId, string $chargeId, ?string $piId, bool $fullyRefunded = true): \Stripe\Event
{
    return buildFakeStripeEvent($eventId, 'charge.refunded', [
        'id' => $chargeId,
        'object' => 'charge',
        'payment_intent' => $piId,
        'amount_refunded' => 1000,
        'refunded' => $fullyRefunded,
    ]);
}

function buildFakeAccountUpdatedEvent(string $eventId, string $accountId): \Stripe\Event
{
    return buildFakeStripeEvent($eventId, 'account.updated', [
        'id' => $accountId,
        'object' => 'account',
        'type' => 'standard',
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
    ]);
}

function buildFakeAccountDeauthorizedEvent(string $eventId, string $accountId): \Stripe\Event
{
    return buildFakeStripeEvent($eventId, 'account.application.deauthorized', [
        'id' => $accountId,
        'object' => 'account',
    ]);
}
