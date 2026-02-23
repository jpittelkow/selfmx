<?php

use App\Models\Notification;

beforeEach(function () {
    config(['graphql.enabled' => true]);
});

describe('me query', function () {
    it('returns authenticated user profile', function () {
        $user = createUser();
        $key = createApiKey($user);

        graphQL('{ me { id name email isAdmin } }', [], $key['plaintext'])
            ->assertStatus(200)
            ->assertJsonPath('data.me.id', (string) $user->id)
            ->assertJsonPath('data.me.name', $user->name)
            ->assertJsonPath('data.me.email', $user->email)
            ->assertJsonPath('data.me.isAdmin', false);
    });

    it('returns admin flag for admin users', function () {
        $user = createAdminUser();
        $key = createApiKey($user);

        graphQL('{ me { isAdmin } }', [], $key['plaintext'])
            ->assertJsonPath('data.me.isAdmin', true);
    });
});

describe('myNotifications query', function () {
    it('returns user notifications paginated', function () {
        $user = createUser();
        $key = createApiKey($user);

        Notification::factory()->count(3)->create(['user_id' => $user->id]);

        $response = graphQL(
            '{ myNotifications(first: 10, page: 1) { data { id title } paginatorInfo { total } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('data.myNotifications.paginatorInfo.total'))->toBe(3);
        expect($response->json('data.myNotifications.data'))->toHaveCount(3);
    });

    it('filters unread notifications', function () {
        $user = createUser();
        $key = createApiKey($user);

        Notification::factory()->count(2)->create(['user_id' => $user->id]);
        Notification::factory()->create(['user_id' => $user->id, 'read_at' => now()]);

        $response = graphQL(
            '{ myNotifications(first: 10, page: 1, unreadOnly: true) { paginatorInfo { total } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('data.myNotifications.paginatorInfo.total'))->toBe(2);
    });

    it('does not return other users notifications', function () {
        $user = createUser();
        $other = createUser();
        $key = createApiKey($user);

        Notification::factory()->count(3)->create(['user_id' => $other->id]);

        $response = graphQL(
            '{ myNotifications(first: 10, page: 1) { paginatorInfo { total } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('data.myNotifications.paginatorInfo.total'))->toBe(0);
    });
});

describe('myApiKeys query', function () {
    it('returns user api keys without token hash', function () {
        $user = createUser();
        $key = createApiKey($user, 'My Key');

        $response = graphQL(
            '{ myApiKeys { id name keyPrefix status } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        $keys = $response->json('data.myApiKeys');
        expect($keys)->toHaveCount(1);
        expect($keys[0]['name'])->toBe('My Key');
        expect($keys[0]['keyPrefix'])->toStartWith('sk_');
        expect($keys[0]['status'])->toBe('active');
    });
});

describe('myNotificationSettings query', function () {
    it('returns notification settings structure', function () {
        $user = createUser();
        $key = createApiKey($user);

        $response = graphQL(
            '{ myNotificationSettings { channels { id enabled } typePreferences } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('data.myNotificationSettings.channels'))->toBeArray();
    });
});
