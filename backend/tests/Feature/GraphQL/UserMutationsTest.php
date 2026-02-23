<?php

use App\Models\Notification;

beforeEach(function () {
    config(['graphql.enabled' => true]);
});

describe('updateProfile mutation', function () {
    it('updates user name', function () {
        $user = createUser(['name' => 'Old Name']);
        $key = createApiKey($user);

        graphQL(
            'mutation { updateProfile(input: { name: "New Name" }) { user { name } emailVerificationSent } }',
            [],
            $key['plaintext']
        )->assertStatus(200)
            ->assertJsonPath('data.updateProfile.user.name', 'New Name')
            ->assertJsonPath('data.updateProfile.emailVerificationSent', false);

        expect($user->fresh()->name)->toBe('New Name');
    });

    it('returns VALIDATION_ERROR when email is taken', function () {
        $existing = createUser(['email' => 'taken@example.com']);
        $user = createUser();
        $key = createApiKey($user);

        $response = graphQL(
            'mutation { updateProfile(input: { email: "taken@example.com" }) { user { email } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('errors.0.extensions.code'))->toBe('VALIDATION_ERROR');
    });
});

describe('markNotificationAsRead mutation', function () {
    it('marks notification as read', function () {
        $user = createUser();
        $key = createApiKey($user);
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        graphQL(
            "mutation { markNotificationAsRead(id: \"{$notification->id}\") { id readAt } }",
            [],
            $key['plaintext']
        )->assertStatus(200)
            ->assertJsonPath('data.markNotificationAsRead.id', $notification->id);

        expect($notification->fresh()->read_at)->not->toBeNull();
    });

    it('returns NOT_FOUND for other users notifications', function () {
        $user = createUser();
        $other = createUser();
        $key = createApiKey($user);
        $notification = Notification::factory()->create(['user_id' => $other->id]);

        $response = graphQL(
            "mutation { markNotificationAsRead(id: \"{$notification->id}\") { id } }",
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('errors.0.extensions.code'))->toBe('NOT_FOUND');
    });
});

describe('deleteNotifications mutation', function () {
    it('deletes user notifications', function () {
        $user = createUser();
        $key = createApiKey($user);
        $n1 = Notification::factory()->create(['user_id' => $user->id]);
        $n2 = Notification::factory()->create(['user_id' => $user->id]);

        graphQL(
            "mutation { deleteNotifications(ids: [\"{$n1->id}\", \"{$n2->id}\"]) { deletedCount } }",
            [],
            $key['plaintext']
        )->assertStatus(200)
            ->assertJsonPath('data.deleteNotifications.deletedCount', 2);
    });

    it('does not delete other users notifications', function () {
        $user = createUser();
        $other = createUser();
        $key = createApiKey($user);
        $notification = Notification::factory()->create(['user_id' => $other->id]);

        graphQL(
            "mutation { deleteNotifications(ids: [\"{$notification->id}\"]) { deletedCount } }",
            [],
            $key['plaintext']
        )->assertStatus(200)
            ->assertJsonPath('data.deleteNotifications.deletedCount', 0);
    });
});
