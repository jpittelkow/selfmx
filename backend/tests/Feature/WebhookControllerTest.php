<?php

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\UrlValidationService;
use App\Services\WebhookService;

describe('WebhookController', function () {
    describe('index', function () {
        it('lists webhooks for admin', function () {
            Webhook::create([
                'name' => 'Test Hook',
                'url' => 'https://example.com/hook',
                'events' => ['user.created'],
                'active' => true,
            ]);

            $response = $this->actingAsAdmin()->getJson('/api/webhooks');

            $response->assertStatus(200);
            $response->assertJsonStructure(['webhooks']);
        });

        it('hides secrets in response', function () {
            Webhook::create([
                'name' => 'Secret Hook',
                'url' => 'https://example.com/hook',
                'secret' => 'my-secret-key',
                'events' => ['user.created'],
                'active' => true,
            ]);

            $response = $this->actingAsAdmin()->getJson('/api/webhooks');

            $response->assertStatus(200);
            $webhooks = $response->json('webhooks');
            expect($webhooks[0])->not->toHaveKey('secret');
            expect($webhooks[0]['secret_set'])->toBeTrue();
        });

        it('returns 403 for non-admin user', function () {
            $user = \App\Models\User::factory()->create();
            $response = $this->actingAs($user, 'sanctum')->getJson('/api/webhooks');
            $response->assertStatus(403);
        });
    });

    describe('store', function () {
        it('creates a webhook', function () {
            $this->mock(UrlValidationService::class, function ($mock) {
                $mock->shouldReceive('validateUrl')->andReturn(true);
            });

            $response = $this->actingAsAdmin()->postJson('/api/webhooks', [
                'name' => 'New Hook',
                'url' => 'https://example.com/hook',
                'events' => ['user.created'],
            ]);

            $response->assertStatus(201);
            $this->assertDatabaseHas('webhooks', ['name' => 'New Hook']);
        });

        it('validates required fields', function () {
            $response = $this->actingAsAdmin()->postJson('/api/webhooks', []);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['name', 'url', 'events']);
        });

        it('blocks SSRF URLs', function () {
            $this->mock(UrlValidationService::class, function ($mock) {
                $mock->shouldReceive('validateUrl')->andReturn(false);
            });

            $response = $this->actingAsAdmin()->postJson('/api/webhooks', [
                'name' => 'Evil Hook',
                'url' => 'http://169.254.169.254/metadata',
                'events' => ['user.created'],
            ]);

            $response->assertStatus(422);
            $response->assertJsonFragment(['message' => 'Invalid webhook URL: URLs pointing to internal or private addresses are not allowed']);
        });
    });

    describe('update', function () {
        it('updates a webhook', function () {
            $this->mock(UrlValidationService::class, function ($mock) {
                $mock->shouldReceive('validateUrl')->andReturn(true);
            });

            $webhook = Webhook::create([
                'name' => 'Old Name',
                'url' => 'https://example.com/hook',
                'events' => ['user.created'],
                'active' => true,
            ]);

            $response = $this->actingAsAdmin()->putJson("/api/webhooks/{$webhook->id}", [
                'name' => 'New Name',
            ]);

            $response->assertStatus(200);
            expect($webhook->fresh()->name)->toBe('New Name');
        });

        it('validates SSRF on URL change', function () {
            $this->mock(UrlValidationService::class, function ($mock) {
                $mock->shouldReceive('validateUrl')->andReturn(false);
            });

            $webhook = Webhook::create([
                'name' => 'Hook',
                'url' => 'https://example.com/hook',
                'events' => ['user.created'],
                'active' => true,
            ]);

            $response = $this->actingAsAdmin()->putJson("/api/webhooks/{$webhook->id}", [
                'url' => 'http://10.0.0.1/internal',
            ]);

            $response->assertStatus(422);
        });
    });

    describe('destroy', function () {
        it('deletes a webhook', function () {
            $webhook = Webhook::create([
                'name' => 'Delete Me',
                'url' => 'https://example.com/hook',
                'events' => ['user.created'],
                'active' => true,
            ]);

            $response = $this->actingAsAdmin()->deleteJson("/api/webhooks/{$webhook->id}");

            $response->assertStatus(200);
            $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
        });
    });

    describe('deliveries', function () {
        it('returns paginated deliveries', function () {
            $webhook = Webhook::create([
                'name' => 'Hook',
                'url' => 'https://example.com/hook',
                'events' => ['user.created'],
                'active' => true,
            ]);

            WebhookDelivery::create([
                'webhook_id' => $webhook->id,
                'event' => 'user.created',
                'payload' => '{}',
                'response_code' => 200,
                'response_body' => 'OK',
                'success' => true,
            ]);

            $response = $this->actingAsAdmin()->getJson("/api/webhooks/{$webhook->id}/deliveries");

            $response->assertStatus(200);
        });
    });

    describe('test', function () {
        it('sends test webhook successfully', function () {
            $this->mock(WebhookService::class, function ($mock) {
                $mock->shouldReceive('sendTest')->andReturn([
                    'success' => true,
                    'message' => 'Test webhook sent successfully',
                    'status_code' => 200,
                ]);
            });

            $webhook = Webhook::create([
                'name' => 'Test Hook',
                'url' => 'https://example.com/hook',
                'events' => ['user.created'],
                'active' => true,
            ]);

            $response = $this->actingAsAdmin()->postJson("/api/webhooks/{$webhook->id}/test");

            $response->assertStatus(200);
        });

        it('returns error when test fails', function () {
            $this->mock(WebhookService::class, function ($mock) {
                $mock->shouldReceive('sendTest')->andReturn([
                    'success' => false,
                    'message' => 'Connection refused',
                ]);
            });

            $webhook = Webhook::create([
                'name' => 'Fail Hook',
                'url' => 'https://example.com/hook',
                'events' => ['user.created'],
                'active' => true,
            ]);

            $response = $this->actingAsAdmin()->postJson("/api/webhooks/{$webhook->id}/test");

            $response->assertStatus(500);
        });

        it('returns 422 when SSRF blocked', function () {
            $this->mock(WebhookService::class, function ($mock) {
                $mock->shouldReceive('sendTest')->andReturn([
                    'success' => false,
                    'message' => 'SSRF blocked',
                    'ssrf_blocked' => true,
                ]);
            });

            $webhook = Webhook::create([
                'name' => 'SSRF Hook',
                'url' => 'https://example.com/hook',
                'events' => ['user.created'],
                'active' => true,
            ]);

            $response = $this->actingAsAdmin()->postJson("/api/webhooks/{$webhook->id}/test");

            $response->assertStatus(422);
        });
    });
});
