<?php

use App\Models\SystemSetting;

describe('SystemSettingController', function () {
    describe('index', function () {
        it('returns all system settings for admin', function () {
            $response = $this->actingAsAdmin()->getJson('/api/system-settings');

            $response->assertStatus(200);
            $response->assertJsonStructure(['settings']);
        });

        it('includes app_url from env in general group', function () {
            $response = $this->actingAsAdmin()->getJson('/api/system-settings');

            $response->assertStatus(200);
            $settings = $response->json('settings');
            expect($settings['general'])->toHaveKey('app_url');
        });

        it('returns 403 for non-admin user', function () {
            $user = createUser();

            $response = $this->actingAs($user, 'sanctum')->getJson('/api/system-settings');

            $response->assertStatus(403);
        });
    });

    describe('publicSettings', function () {
        it('returns public settings without auth', function () {
            $response = $this->getJson('/api/system-settings/public');

            $response->assertStatus(200);
            $response->assertJsonStructure([
                'settings',
                'features',
            ]);
        });

        it('includes feature flags', function () {
            $response = $this->getJson('/api/system-settings/public');

            $response->assertStatus(200);
            $features = $response->json('features');
            expect($features)->toHaveKey('email_configured');
            expect($features)->toHaveKey('two_factor_mode');
            expect($features)->toHaveKey('search_enabled');
        });
    });

    describe('show', function () {
        it('returns settings for a specific group', function () {
            $response = $this->actingAsAdmin()->getJson('/api/system-settings/general');

            $response->assertStatus(200);
            $response->assertJsonStructure(['group', 'settings']);
            $response->assertJsonPath('group', 'general');
        });

        it('includes app_url for general group', function () {
            $response = $this->actingAsAdmin()->getJson('/api/system-settings/general');

            $response->assertStatus(200);
            $settings = $response->json('settings');
            expect($settings)->toHaveKey('app_url');
        });
    });

    describe('update', function () {
        it('saves system settings', function () {
            $response = $this->actingAsAdmin()->putJson('/api/system-settings', [
                'settings' => [
                    ['group' => 'general', 'key' => 'app_name', 'value' => 'Test App'],
                ],
            ]);

            $response->assertStatus(200);
        });

        it('ignores app_url updates', function () {
            $originalUrl = config('app.url');

            $response = $this->actingAsAdmin()->putJson('/api/system-settings', [
                'settings' => [
                    ['group' => 'general', 'key' => 'app_url', 'value' => 'https://evil.com'],
                ],
            ]);

            $response->assertStatus(200);
            expect(config('app.url'))->toBe($originalUrl);
        });

        it('validates settings array is required', function () {
            $response = $this->actingAsAdmin()->putJson('/api/system-settings', []);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['settings']);
        });

        it('validates setting group is required', function () {
            $response = $this->actingAsAdmin()->putJson('/api/system-settings', [
                'settings' => [
                    ['key' => 'foo', 'value' => 'bar'],
                ],
            ]);

            $response->assertStatus(422);
        });

        it('returns 403 for non-admin', function () {
            $user = createUser();

            $response = $this->actingAs($user, 'sanctum')->putJson('/api/system-settings', [
                'settings' => [
                    ['group' => 'general', 'key' => 'app_name', 'value' => 'Hack'],
                ],
            ]);

            $response->assertStatus(403);
        });
    });
});
