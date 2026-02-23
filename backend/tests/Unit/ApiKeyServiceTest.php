<?php

use App\Models\ApiToken;
use App\Services\ApiKeyService;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\UsageTrackingService;

function createApiKeyService(): ApiKeyService
{
    return app(ApiKeyService::class);
}

describe('ApiKeyService', function () {

    describe('create', function () {
        it('generates a key with sk_ prefix', function () {
            $user = createUser();
            $service = createApiKeyService();

            $result = $service->create($user, 'Test Key');

            expect($result['plaintext'])->toStartWith('sk_');
            expect(strlen($result['plaintext']))->toBe(67); // sk_ + 64 chars
        });

        it('stores SHA-256 hash, not plaintext', function () {
            $user = createUser();
            $service = createApiKeyService();

            $result = $service->create($user, 'Test Key');

            $token = ApiToken::find($result['token']->id);
            expect($token->token)->toBe(hash('sha256', $result['plaintext']));
            expect($token->token)->not->toBe($result['plaintext']);
        });

        it('stores key_prefix as first 11 chars of plaintext', function () {
            $user = createUser();
            $service = createApiKeyService();

            $result = $service->create($user, 'Test Key');

            expect($result['token']->key_prefix)->toBe(substr($result['plaintext'], 0, 11));
            expect($result['token']->key_prefix)->toStartWith('sk_');
        });

        it('enforces max keys per user', function () {
            $user = createUser();
            $service = createApiKeyService();

            // Default max is 5
            for ($i = 0; $i < 5; $i++) {
                $service->create($user, "Key {$i}");
            }

            expect(fn () => $service->create($user, 'Key 6'))
                ->toThrow(\RuntimeException::class, 'Maximum of 5 active API keys');
        });

        it('sets expiration date when provided', function () {
            $user = createUser();
            $service = createApiKeyService();
            $expiresAt = now()->addDays(30);

            $result = $service->create($user, 'Expiring Key', $expiresAt);

            expect($result['token']->expires_at->toDateString())->toBe($expiresAt->toDateString());
        });
    });

    describe('validate', function () {
        it('returns token for valid key', function () {
            $user = createUser();
            $service = createApiKeyService();

            $result = $service->create($user, 'Valid Key');
            $validated = $service->validate($result['plaintext']);

            expect($validated)->not->toBeNull();
            expect($validated->id)->toBe($result['token']->id);
        });

        it('returns null for invalid key', function () {
            $service = createApiKeyService();

            expect($service->validate('sk_invalid_key_that_does_not_exist'))->toBeNull();
        });

        it('returns null for non-sk_ prefixed key', function () {
            $service = createApiKeyService();

            expect($service->validate('not_an_sk_key'))->toBeNull();
        });

        it('returns null for expired key', function () {
            $user = createUser();
            $service = createApiKeyService();

            $result = $service->create($user, 'Expired Key');
            // Set expires_at to past to simulate expiration
            $result['token']->update(['expires_at' => now()->subDay()]);

            expect($service->validate($result['plaintext']))->toBeNull();
        });

        it('returns null for revoked key', function () {
            $user = createUser();
            $service = createApiKeyService();

            $result = $service->create($user, 'Revoked Key');
            $service->revoke($result['token']);

            expect($service->validate($result['plaintext']))->toBeNull();
        });

        it('updates last_used_at on validation', function () {
            $user = createUser();
            $service = createApiKeyService();

            $result = $service->create($user, 'Usage Key');
            expect($result['token']->last_used_at)->toBeNull();

            $service->validate($result['plaintext']);

            $result['token']->refresh();
            expect($result['token']->last_used_at)->not->toBeNull();
        });
    });

    describe('revoke', function () {
        it('sets revoked_at and soft-deletes', function () {
            $user = createUser();
            $service = createApiKeyService();

            $result = $service->create($user, 'Revokable Key');
            $service->revoke($result['token']);

            $token = ApiToken::withTrashed()->find($result['token']->id);
            expect($token->revoked_at)->not->toBeNull();
            expect($token->trashed())->toBeTrue();
        });
    });

    describe('rotate', function () {
        it('creates a new key linked to the old one', function () {
            $user = createUser();
            $service = createApiKeyService();

            $original = $service->create($user, 'Original Key');
            $rotated = $service->rotate($original['token']);

            expect($rotated['plaintext'])->toStartWith('sk_');
            expect($rotated['plaintext'])->not->toBe($original['plaintext']);
            expect($rotated['token']->rotated_from_id)->toBe($original['token']->id);
            expect($rotated['token']->name)->toBe('Original Key');
        });

        it('keeps the old key active during grace period', function () {
            $user = createUser();
            $service = createApiKeyService();

            $original = $service->create($user, 'Original Key');
            $service->rotate($original['token']);

            // Old key should still be valid
            $validated = $service->validate($original['plaintext']);
            expect($validated)->not->toBeNull();
        });
    });

    describe('pruneExpired', function () {
        it('soft-deletes expired keys', function () {
            $user = createUser();
            $service = createApiKeyService();

            $result = $service->create($user, 'Expiring Key', now()->subDay());
            $result['token']->update(['expires_at' => now()->subDay()]);

            $count = $service->pruneExpired();

            expect($count)->toBeGreaterThanOrEqual(1);
            expect(ApiToken::find($result['token']->id))->toBeNull();
            expect(ApiToken::withTrashed()->find($result['token']->id))->not->toBeNull();
        });

        it('auto-revokes rotated keys past grace period', function () {
            $user = createUser();
            $service = createApiKeyService();

            $original = $service->create($user, 'Old Key');
            $service->rotate($original['token']);

            // Move the original key's creation to beyond grace period
            $original['token']->update(['created_at' => now()->subDays(10)]);

            $count = $service->pruneExpired();

            $token = ApiToken::withTrashed()->find($original['token']->id);
            expect($token->revoked_at)->not->toBeNull();
        });
    });
});

describe('ApiKeyController', function () {

    describe('index', function () {
        it('lists only sk_ prefixed keys for the user', function () {
            $user = createUser();
            $service = createApiKeyService();

            $service->create($user, 'Key 1');
            $service->create($user, 'Key 2');

            $response = $this->actingAs($user)->getJson('/api/user/api-keys');

            $response->assertOk();
            $response->assertJsonCount(2, 'keys');
            $response->assertJsonStructure(['keys' => [['id', 'name', 'key_prefix', 'status']]]);
        });

        it('does not leak plaintext keys', function () {
            $user = createUser();
            $service = createApiKeyService();
            $result = $service->create($user, 'Secret Key');

            $response = $this->actingAs($user)->getJson('/api/user/api-keys');

            $response->assertOk();
            $json = $response->json();
            $content = json_encode($json);
            expect($content)->not->toContain($result['plaintext']);
        });

        it('does not show keys from other users', function () {
            $user1 = createUser();
            $user2 = createUser();
            $service = createApiKeyService();

            $service->create($user1, 'User 1 Key');
            $service->create($user2, 'User 2 Key');

            $response = $this->actingAs($user1)->getJson('/api/user/api-keys');

            $response->assertOk();
            $response->assertJsonCount(1, 'keys');
        });
    });

    describe('store', function () {
        it('creates a key and returns plaintext once', function () {
            $user = createUser();

            $response = $this->actingAs($user)->postJson('/api/user/api-keys', [
                'name' => 'New Key',
            ]);

            $response->assertCreated();
            $response->assertJsonStructure(['key', 'api_key' => ['id', 'name', 'key_prefix']]);
            expect($response->json('key'))->toStartWith('sk_');
        });

        it('validates name is required', function () {
            $user = createUser();

            $response = $this->actingAs($user)->postJson('/api/user/api-keys', []);

            $response->assertUnprocessable();
        });
    });

    describe('destroy', function () {
        it('revokes a key', function () {
            $user = createUser();
            $service = createApiKeyService();
            $result = $service->create($user, 'Deletable Key');

            $response = $this->actingAs($user)->deleteJson("/api/user/api-keys/{$result['token']->id}");

            $response->assertOk();
            expect($service->validate($result['plaintext']))->toBeNull();
        });

        it('returns 404 for another users key', function () {
            $user1 = createUser();
            $user2 = createUser();
            $service = createApiKeyService();
            $result = $service->create($user2, 'Other Key');

            $response = $this->actingAs($user1)->deleteJson("/api/user/api-keys/{$result['token']->id}");

            $response->assertNotFound();
        });
    });

    describe('rotate', function () {
        it('creates a replacement key', function () {
            $user = createUser();
            $service = createApiKeyService();
            $result = $service->create($user, 'Rotatable Key');

            $response = $this->actingAs($user)->postJson("/api/user/api-keys/{$result['token']->id}/rotate");

            $response->assertOk();
            $response->assertJsonStructure(['key', 'api_key' => ['id', 'name', 'key_prefix']]);
            expect($response->json('key'))->toStartWith('sk_');
            expect($response->json('key'))->not->toBe($result['plaintext']);
        });
    });
});
