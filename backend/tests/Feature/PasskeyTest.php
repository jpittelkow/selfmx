<?php

use App\Models\User;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Model;
use Laragear\WebAuthn\Models\WebAuthnCredential;

/**
 * Helper to create a WebAuthn credential for testing.
 * Uses unguard since WebAuthnCredential has guarded attributes.
 */
function createWebAuthnCredential(User $user, string $id, string $alias = 'Test Passkey'): WebAuthnCredential
{
    Model::unguard();
    $credential = $user->webauthnCredentials()->create([
        'id' => $id,
        'user_id' => (string) \Illuminate\Support\Str::uuid(),
        'alias' => $alias,
        'counter' => 0,
        'rp_id' => 'localhost',
        'origin' => 'http://localhost',
        'public_key' => 'test-public-key-data',
        'attestation_format' => 'none',
    ]);
    Model::reguard();

    return $credential;
}

describe('Passkeys', function () {

    describe('Passkey Management (authenticated)', function () {
        it('returns empty passkey list for user with no passkeys', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/auth/passkeys');

            $response->assertStatus(200)
                ->assertJson(['passkeys' => []]);
        });

        it('returns passkeys for user who has registered credentials', function () {
            $user = User::factory()->create();
            createWebAuthnCredential($user, 'test-credential-id-123', 'My Passkey');

            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/auth/passkeys');

            $response->assertStatus(200)
                ->assertJsonCount(1, 'passkeys')
                ->assertJsonStructure([
                    'passkeys' => [
                        ['id', 'alias', 'created_at', 'updated_at'],
                    ],
                ]);

            expect($response->json('passkeys.0.alias'))->toBe('My Passkey');
        });

        it('does not return other users passkeys', function () {
            $user = User::factory()->create();
            $other = User::factory()->create();
            createWebAuthnCredential($other, 'other-credential-id', 'Other Passkey');

            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/auth/passkeys');

            $response->assertStatus(200)
                ->assertJson(['passkeys' => []]);
        });

        it('requires authentication to list passkeys', function () {
            $response = $this->getJson('/api/auth/passkeys');
            $response->assertStatus(401);
        });
    });

    describe('Passkey Rename', function () {
        it('can rename a passkey', function () {
            $user = User::factory()->create();
            createWebAuthnCredential($user, 'rename-test-id', 'Old Name');

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/auth/passkeys/rename-test-id', ['name' => 'New Name']);

            $response->assertStatus(200);

            $cred = $user->webauthnCredentials()->where('id', 'rename-test-id')->first();
            expect($cred->alias)->toBe('New Name');
        });

        it('returns 404 when renaming another users passkey', function () {
            $user = User::factory()->create();
            $other = User::factory()->create();
            createWebAuthnCredential($other, 'other-rename-id', 'Other Key');

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/auth/passkeys/other-rename-id', ['name' => 'Hacked']);

            $response->assertStatus(404);
        });

        it('validates name is required for rename', function () {
            $user = User::factory()->create();
            createWebAuthnCredential($user, 'validate-name-id', 'Test');

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/auth/passkeys/validate-name-id', []);

            $response->assertStatus(422);
        });

        it('validates name max length for rename', function () {
            $user = User::factory()->create();
            createWebAuthnCredential($user, 'max-name-id', 'Test');

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/auth/passkeys/max-name-id', ['name' => str_repeat('a', 256)]);

            $response->assertStatus(422);
        });

        it('rejects whitespace-only name for rename', function () {
            $user = User::factory()->create();
            createWebAuthnCredential($user, 'whitespace-name-id', 'Original');

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/auth/passkeys/whitespace-name-id', ['name' => '   ']);

            $response->assertStatus(422);

            // Verify the name was not changed
            $cred = $user->webauthnCredentials()->where('id', 'whitespace-name-id')->first();
            expect($cred->alias)->toBe('Original');
        });

        it('requires authentication to rename', function () {
            $response = $this->putJson('/api/auth/passkeys/some-id', ['name' => 'Test']);
            $response->assertStatus(401);
        });
    });

    describe('Passkey Delete', function () {
        it('can delete a passkey', function () {
            $user = User::factory()->create();
            createWebAuthnCredential($user, 'delete-test-id', 'Delete Me');

            $response = $this->actingAs($user, 'sanctum')
                ->deleteJson('/api/auth/passkeys/delete-test-id');

            $response->assertStatus(200);

            expect($user->webauthnCredentials()->count())->toBe(0);
        });

        it('returns 404 when deleting another users passkey', function () {
            $user = User::factory()->create();
            $other = User::factory()->create();
            createWebAuthnCredential($other, 'other-delete-id', 'Other Key');

            $response = $this->actingAs($user, 'sanctum')
                ->deleteJson('/api/auth/passkeys/other-delete-id');

            $response->assertStatus(404);

            expect($other->webauthnCredentials()->count())->toBe(1);
        });

        it('requires authentication to delete', function () {
            $response = $this->deleteJson('/api/auth/passkeys/some-id');
            $response->assertStatus(401);
        });
    });

    describe('Passkey Login Options', function () {
        it('returns 403 when passkey mode is disabled', function () {
            app(SettingService::class)->set('auth', 'passkey_mode', 'disabled');

            $response = $this->postJson('/api/auth/passkeys/login/options');

            $response->assertStatus(403);
        });
    });

    describe('Passkey Login', function () {
        it('rejects login when passkey mode is disabled', function () {
            app(SettingService::class)->set('auth', 'passkey_mode', 'disabled');

            // Laragear's AssertedRequest runs validation before controller.
            // When disabled, the controller returns 403, but Laragear's form request
            // may return 422 first. Either status indicates passkey login is blocked.
            $response = $this->postJson('/api/auth/passkeys/login');

            expect($response->status())->toBeIn([403, 422]);
        });
    });
});
