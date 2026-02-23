<?php

use App\Models\User;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationOrchestrator;

describe('Per-type notification preferences', function () {

    describe('Orchestrator respects type preferences', function () {
        it('sends to database channel regardless of type preferences', function () {
            $user = User::factory()->create();
            $user->setSetting('notifications', 'type_preferences', [
                'test' => ['database' => false],
            ]);

            $orchestrator = app(NotificationOrchestrator::class);
            $results = $orchestrator->send(
                $user, 'test', 'Title', 'Message', [], ['database']
            );

            // database channel always sends (ignores type preferences)
            expect($results)->toHaveKey('database');
            expect($results['database']['success'])->toBeTrue();
        });

        it('skips channel when type preference is disabled', function () {
            $user = User::factory()->create();
            $user->setSetting('notifications', 'email_enabled', true);
            $user->setSetting('notifications', 'type_preferences', [
                'test' => ['email' => false],
            ]);

            config(['notifications.channels.email.enabled' => true]);

            $orchestrator = app(NotificationOrchestrator::class);
            $results = $orchestrator->send(
                $user, 'test', 'Title', 'Message', [], ['email']
            );

            // email should be skipped because type preference says false
            expect($results)->not->toHaveKey('email');
        });

        it('sends when no type preference override exists', function () {
            $user = User::factory()->create();
            $user->setSetting('notifications', 'type_preferences', []);

            $orchestrator = app(NotificationOrchestrator::class);
            $results = $orchestrator->send(
                $user, 'test', 'Title', 'Message', [], ['database']
            );

            expect($results)->toHaveKey('database');
            expect($results['database']['success'])->toBeTrue();
        });
    });

    describe('Type preferences API', function () {
        it('returns empty preferences by default', function () {
            $user = User::factory()->create();

            $this->actingAs($user)
                ->getJson('/api/user/notification-settings/type-preferences')
                ->assertOk()
                ->assertJson(['preferences' => []]);
        });

        it('updates a type preference', function () {
            $user = User::factory()->create();

            NotificationTemplate::firstOrCreate(
                ['type' => 'backup.completed', 'channel_group' => 'inapp'],
                ['title' => 'Test', 'body' => 'Test body', 'variables' => [], 'is_system' => true, 'is_active' => true]
            );

            $this->actingAs($user)
                ->putJson('/api/user/notification-settings/type-preferences', [
                    'type' => 'backup.completed',
                    'channel' => 'email',
                    'enabled' => false,
                ])
                ->assertOk();

            $prefs = $user->fresh()->getSetting('notifications', 'type_preferences', []);
            expect($prefs['backup.completed']['email'])->toBeFalse();
        });

        it('removes override when re-enabling', function () {
            $user = User::factory()->create();
            $user->setSetting('notifications', 'type_preferences', [
                'backup.completed' => ['email' => false],
            ]);

            NotificationTemplate::firstOrCreate(
                ['type' => 'backup.completed', 'channel_group' => 'inapp'],
                ['title' => 'Test', 'body' => 'Test body', 'variables' => [], 'is_system' => true, 'is_active' => true]
            );

            $this->actingAs($user)
                ->putJson('/api/user/notification-settings/type-preferences', [
                    'type' => 'backup.completed',
                    'channel' => 'email',
                    'enabled' => true,
                ])
                ->assertOk();

            // Preference should be cleaned up (absence = enabled)
            $prefs = $user->fresh()->getSetting('notifications', 'type_preferences', []);
            expect($prefs)->not->toHaveKey('backup.completed');
        });

        it('rejects unknown notification type', function () {
            $user = User::factory()->create();

            $this->actingAs($user)
                ->putJson('/api/user/notification-settings/type-preferences', [
                    'type' => 'nonexistent.type',
                    'channel' => 'email',
                    'enabled' => false,
                ])
                ->assertStatus(422);
        });

        it('rejects unknown channel', function () {
            $user = User::factory()->create();

            NotificationTemplate::firstOrCreate(
                ['type' => 'backup.completed', 'channel_group' => 'inapp'],
                ['title' => 'Test', 'body' => 'Test body', 'variables' => [], 'is_system' => true, 'is_active' => true]
            );

            $this->actingAs($user)
                ->putJson('/api/user/notification-settings/type-preferences', [
                    'type' => 'backup.completed',
                    'channel' => 'unknown_channel',
                    'enabled' => false,
                ])
                ->assertStatus(422);
        });
    });
});
