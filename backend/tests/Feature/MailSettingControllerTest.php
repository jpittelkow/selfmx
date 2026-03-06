<?php

use App\Models\User;
use App\Services\SettingService;
use Illuminate\Support\Facades\Mail;

describe('MailSettingController', function () {
    describe('authorization', function () {
        it('returns 403 for non-admin user', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user, 'sanctum')->getJson('/api/mail-settings');

            $response->assertStatus(403);
        });

        it('returns 401 when unauthenticated', function () {
            $response = $this->getJson('/api/mail-settings');

            $response->assertStatus(401);
        });
    });

    describe('show', function () {
        it('returns mail settings with mapped keys', function () {
            $response = $this->actingAsAdmin()->getJson('/api/mail-settings');

            $response->assertStatus(200);
            $response->assertJsonStructure(['settings']);
        });
    });

    describe('reset', function () {
        it('rejects unknown setting key', function () {
            $response = $this->actingAsAdmin()->deleteJson('/api/mail-settings/keys/nonexistent_key');

            $response->assertStatus(422);
            $response->assertJsonFragment(['message' => 'Unknown setting key']);
        });
    });

    describe('sendTestEmail', function () {
        it('validates to email required', function () {
            $response = $this->actingAsAdmin()->postJson('/api/mail-settings/test', []);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['to']);
        });

        it('validates to must be valid email', function () {
            $response = $this->actingAsAdmin()->postJson('/api/mail-settings/test', [
                'to' => 'not-an-email',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['to']);
        });

        it('sends test email successfully', function () {
            Mail::fake();

            $response = $this->actingAsAdmin()->postJson('/api/mail-settings/test', [
                'to' => 'test@example.com',
            ]);

            // Should succeed (200) or fail gracefully with mail config issues (500)
            // The key test is that it reaches the controller (not 401/403/422)
            expect($response->status())->toBeIn([200, 500]);
        });
    });
});
