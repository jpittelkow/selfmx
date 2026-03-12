<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\RateLimiter;

abstract class TestCase extends BaseTestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        // Force in-memory SQLite for tests. The docker-compose.yml sets
        // DB_DATABASE as a process-level env var pointing at the real database,
        // which phpunit.xml cannot override. We must set it before the app
        // boots so RefreshDatabase migrations target :memory: not the real DB.
        putenv('DB_DATABASE=:memory:');
        $_ENV['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_DATABASE'] = ':memory:';

        parent::setUp();

        // Force Scout to use the collection driver in tests so that
        // tests don't require a running Meilisearch instance.
        // The docker-compose.yml sets SCOUT_DRIVER=meilisearch as a
        // process-level env var, which phpunit.xml cannot override.
        $this->app['config']->set('scout.driver', 'collection');

        // Disable rate limiting middleware in tests to prevent 429 errors
        $this->withoutMiddleware(ThrottleRequests::class);
        
        // Clear cache to reset any rate limiter state
        Cache::flush();
    }

    /**
     * Create an authenticated user for testing.
     */
    protected function actingAsUser(array $attributes = []): self
    {
        $user = \App\Models\User::factory()->create($attributes);
        return $this->actingAs($user, 'sanctum');
    }

    /**
     * Create an authenticated admin user for testing (in admin group).
     */
    protected function actingAsAdmin(array $attributes = []): self
    {
        $user = \App\Models\User::factory()->admin()->create($attributes);
        return $this->actingAs($user, 'sanctum');
    }
}
