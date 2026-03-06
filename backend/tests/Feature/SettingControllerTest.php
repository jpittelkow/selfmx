<?php

use App\Models\User;

describe('SettingController', function () {
    describe('index', function () {
        it('returns user settings for admin', function () {
            $response = $this->actingAsAdmin()->getJson('/api/settings');

            $response->assertStatus(200);
            $response->assertJsonStructure(['settings']);
        });

        it('returns 403 for non-admin user', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user, 'sanctum')->getJson('/api/settings');

            $response->assertStatus(403);
        });
    });

    describe('show', function () {
        it('returns settings for a specific group', function () {
            $response = $this->actingAsAdmin()->getJson('/api/settings/general');

            $response->assertStatus(200);
            $response->assertJsonStructure(['group', 'settings']);
            $response->assertJsonFragment(['group' => 'general']);
        });
    });

    describe('update', function () {
        it('saves settings', function () {
            $response = $this->actingAsAdmin()->putJson('/api/settings', [
                'settings' => [
                    ['key' => 'theme', 'value' => 'dark', 'group' => 'general'],
                ],
            ]);

            $response->assertStatus(200);
        });

        it('validates settings array required', function () {
            $response = $this->actingAsAdmin()->putJson('/api/settings', []);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['settings']);
        });

        it('validates setting key required', function () {
            $response = $this->actingAsAdmin()->putJson('/api/settings', [
                'settings' => [
                    ['value' => 'dark'],
                ],
            ]);

            $response->assertStatus(422);
        });
    });

    describe('updateGroup', function () {
        it('saves settings for a group', function () {
            $response = $this->actingAsAdmin()->putJson('/api/settings/general', [
                'settings' => ['theme' => 'dark'],
            ]);

            $response->assertStatus(200);
            $response->assertJsonFragment(['group' => 'general']);
        });
    });

    describe('user scoping', function () {
        it('users cannot see other users settings', function () {
            $admin = createAdminUser();

            // Admin sets a setting
            $this->actingAs($admin, 'sanctum')->putJson('/api/settings', [
                'settings' => [
                    ['key' => 'secret_pref', 'value' => 'admin_value', 'group' => 'general'],
                ],
            ]);

            // Different admin reads settings — should not see the first admin's settings
            $admin2 = createAdminUser();
            $response = $this->actingAs($admin2, 'sanctum')->getJson('/api/settings');

            $response->assertStatus(200);
            // The second admin should get their own (empty) settings, not admin1's
            $settings = $response->json('settings');
            $generalSettings = $settings['general'] ?? [];
            expect($generalSettings)->not->toHaveKey('secret_pref');
        });
    });
});
