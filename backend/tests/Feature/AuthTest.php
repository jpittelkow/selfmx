<?php

use App\Models\User;
use Illuminate\Support\Facades\Password;

describe('Authentication', function () {
    
    describe('Registration', function () {
        it('can register a new user', function () {
            $response = $this->postJson('/api/auth/register', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

            $response->assertStatus(201)
                ->assertJsonStructure([
                    'user' => ['id', 'name', 'email'],
                    'message',
                ]);

            $this->assertDatabaseHas('users', [
                'email' => 'test@example.com',
            ]);
        });

        it('validates required fields on registration', function () {
            $response = $this->postJson('/api/auth/register', []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['name', 'email', 'password']);
        });

        it('prevents duplicate email registration', function () {
            User::factory()->create(['email' => 'test@example.com']);

            $response = $this->postJson('/api/auth/register', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
        });
    });

    describe('Login', function () {
        it('can login with valid credentials', function () {
            $user = User::factory()->create([
                'password' => bcrypt('Password123!'),
            ]);

            $response = $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'user' => ['id', 'name', 'email'],
                ]);
        });

        it('fails with invalid credentials', function () {
            $user = User::factory()->create([
                'password' => bcrypt('Password123!'),
            ]);

            $response = $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'WrongPassword123!',
            ]);

            $response->assertStatus(401);
        });

        it('rejects login when account is disabled', function () {
            $user = User::factory()->create([
                'password' => bcrypt('Password123!'),
                'disabled_at' => now(),
            ]);

            $response = $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

            $response->assertStatus(403)
                ->assertJson(['message' => 'This account has been disabled. Please contact your administrator.']);
        });
    });

    describe('Logout', function () {
        it('can logout authenticated user', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/auth/logout');

            $response->assertStatus(200)
                ->assertJson(['message' => 'Logged out successfully']);
        });
    });

    describe('Current User', function () {
        it('returns authenticated user data', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/auth/user');

            $response->assertStatus(200)
                ->assertJson([
                    'user' => [
                        'id' => $user->id,
                        'email' => $user->email,
                    ],
                ]);
        });

        it('returns 401 for unauthenticated request', function () {
            $response = $this->getJson('/api/auth/user');

            $response->assertStatus(401);
        });
    });

    describe('Password Reset', function () {
        it('returns 503 when email is not configured', function () {
            $this->mock(\App\Services\EmailConfigService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(false);
            });

            $response = $this->postJson('/api/auth/forgot-password', [
                'email' => 'user@example.com',
            ]);

            $response->assertStatus(503)
                ->assertJsonFragment(['message' => 'Password reset is not available. Please contact your administrator.']);
        });

        it('returns success message when forgot-password is requested and email is configured', function () {
            $this->mock(\App\Services\EmailConfigService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
            });

            User::factory()->create(['email' => 'user@example.com']);

            $response = $this->postJson('/api/auth/forgot-password', [
                'email' => 'user@example.com',
            ]);

            $response->assertStatus(200)
                ->assertJsonFragment(['message' => 'If an account exists with this email, a password reset link has been sent.']);
        });

        it('resets password with valid token', function () {
            $user = User::factory()->create(['email' => 'user@example.com']);
            $token = Password::broker()->createToken($user);

            $response = $this->postJson('/api/auth/reset-password', [
                'email' => $user->email,
                'token' => $token,
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]);

            $response->assertStatus(200)
                ->assertJson(['message' => 'Password reset successful']);
        });

        it('returns 400 for invalid reset token', function () {
            $user = User::factory()->create(['email' => 'user@example.com']);

            $response = $this->postJson('/api/auth/reset-password', [
                'email' => $user->email,
                'token' => 'invalid-token',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]);

            $response->assertStatus(400)
                ->assertJsonFragment(['message' => 'Password reset failed']);
        });
    });

    describe('Email Verification', function () {
        it('verifies email with valid id and hash', function () {
            $user = User::factory()->create([
                'email_verified_at' => null,
                'email' => 'user@example.com',
            ]);

            $hash = hash_hmac('sha256', $user->getEmailForVerification(), config('app.key'));

            $response = $this->postJson('/api/auth/verify-email', [
                'id' => $user->id,
                'hash' => $hash,
            ]);

            $response->assertStatus(200)
                ->assertJson(['message' => 'Email verified successfully']);

            $user->refresh();
            expect($user->hasVerifiedEmail())->toBeTrue();
        });

        it('returns 400 for invalid verification hash', function () {
            $user = User::factory()->create(['email_verified_at' => null]);

            $response = $this->postJson('/api/auth/verify-email', [
                'id' => $user->id,
                'hash' => 'invalid-hash',
            ]);

            $response->assertStatus(400)
                ->assertJsonFragment(['message' => 'Invalid verification link']);
        });

        it('returns success when email already verified', function () {
            $user = User::factory()->create([
                'email_verified_at' => now(),
                'email' => 'user@example.com',
            ]);
            $hash = hash_hmac('sha256', $user->getEmailForVerification(), config('app.key'));

            $response = $this->postJson('/api/auth/verify-email', [
                'id' => $user->id,
                'hash' => $hash,
            ]);

            $response->assertStatus(200)
                ->assertJson(['message' => 'Email already verified']);
        });
    });

    describe('2FA Required Mode', function () {
        it('returns 403 with requires_2fa_setup when 2FA is required and user has not set up 2FA', function () {
            $this->mock(\App\Services\SettingService::class, function ($mock) {
                $mock->shouldReceive('get')
                    ->with('auth', 'two_factor_mode', 'optional')
                    ->andReturn('required');
            });

            $user = User::factory()->create([
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
            ]);

            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/auth/user');

            $response->assertStatus(403)
                ->assertJson([
                    'message' => 'Two-factor authentication is required. Please set up 2FA in Security settings.',
                    'requires_2fa_setup' => true,
                ]);
        });
    });
});
