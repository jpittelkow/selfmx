<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('ProfileController', function () {
    describe('show', function () {
        it('returns the authenticated user profile', function () {
            $user = createUser(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

            $response = $this->actingAs($user, 'sanctum')->getJson('/api/profile');

            $response->assertStatus(200);
            $response->assertJsonPath('user.name', 'Jane Doe');
            $response->assertJsonPath('user.email', 'jane@example.com');
        });

        it('returns 401 for unauthenticated request', function () {
            $response = $this->getJson('/api/profile');

            $response->assertStatus(401);
        });
    });

    describe('update', function () {
        it('updates user name', function () {
            $user = createUser(['name' => 'Old Name']);

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/profile', ['name' => 'New Name']);

            $response->assertStatus(200);
            expect($user->fresh()->name)->toBe('New Name');
        });

        it('updates user email and sends verification', function () {
            $user = createUser(['email' => 'old@example.com', 'email_verified_at' => now()]);

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/profile', ['email' => 'new@example.com']);

            $response->assertStatus(200);
            $response->assertJsonPath('email_verification_sent', true);
            expect($user->fresh()->email)->toBe('new@example.com');
            expect($user->fresh()->email_verified_at)->toBeNull();
        });

        it('validates email uniqueness', function () {
            createUser(['email' => 'taken@example.com']);
            $user = createUser(['email' => 'mine@example.com']);

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/profile', ['email' => 'taken@example.com']);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['email']);
        });
    });

    describe('updatePassword', function () {
        it('updates password with valid current password', function () {
            $user = createUser(['password' => 'oldpassword']);

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/profile/password', [
                    'current_password' => 'oldpassword',
                    'password' => 'NewPass123!',
                    'password_confirmation' => 'NewPass123!',
                ]);

            $response->assertStatus(200);
        });

        it('rejects wrong current password', function () {
            $user = createUser(['password' => 'correctpassword']);

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/profile/password', [
                    'current_password' => 'wrongpassword',
                    'password' => 'NewPass123!',
                    'password_confirmation' => 'NewPass123!',
                ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['current_password']);
        });

        it('requires password confirmation', function () {
            $user = createUser(['password' => 'oldpassword']);

            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/profile/password', [
                    'current_password' => 'oldpassword',
                    'password' => 'NewPass123!',
                ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['password']);
        });
    });

    describe('uploadAvatar', function () {
        it('uploads an avatar image', function () {
            Storage::fake('public');
            $user = createUser();
            $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/profile/avatar', ['avatar' => $file]);

            $response->assertStatus(200);
            $response->assertJsonStructure(['avatar_url']);
            expect($user->fresh()->avatar)->not->toBeNull();
        });

        it('rejects non-image files', function () {
            Storage::fake('public');
            $user = createUser();
            $file = UploadedFile::fake()->create('document.pdf', 100);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/profile/avatar', ['avatar' => $file]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['avatar']);
        });

        it('rejects files over 2MB', function () {
            Storage::fake('public');
            $user = createUser();
            $file = UploadedFile::fake()->image('avatar.jpg')->size(3000);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/profile/avatar', ['avatar' => $file]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['avatar']);
        });
    });

    describe('deleteAvatar', function () {
        it('deletes the user avatar', function () {
            Storage::fake('public');
            $user = createUser(['avatar' => '/storage/avatars/test.jpg']);

            $response = $this->actingAs($user, 'sanctum')
                ->deleteJson('/api/profile/avatar');

            $response->assertStatus(200);
            expect($user->fresh()->avatar)->toBeNull();
        });

        it('returns 404 when no avatar exists', function () {
            $user = createUser(['avatar' => null]);

            $response = $this->actingAs($user, 'sanctum')
                ->deleteJson('/api/profile/avatar');

            $response->assertStatus(404);
        });
    });

    describe('destroy', function () {
        it('deletes user account with correct password', function () {
            $user = createUser(['password' => 'mypassword']);

            $response = $this->actingAs($user, 'sanctum')
                ->deleteJson('/api/profile', ['password' => 'mypassword']);

            $response->assertStatus(200);
            expect(User::find($user->id))->toBeNull();
        });

        it('rejects wrong password', function () {
            $user = createUser(['password' => 'mypassword']);

            $response = $this->actingAs($user, 'sanctum')
                ->deleteJson('/api/profile', ['password' => 'wrongpassword']);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['password']);
        });
    });
});
