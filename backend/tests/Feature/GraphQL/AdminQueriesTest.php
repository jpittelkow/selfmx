<?php

use App\Models\AuditLog;

beforeEach(function () {
    config(['graphql.enabled' => true]);
});

describe('auditLogs query', function () {
    it('returns audit logs for admin user', function () {
        $admin = createAdminUser();
        $key = createApiKey($admin);

        AuditLog::factory()->count(3)->create();

        $response = graphQL(
            '{ auditLogs(first: 10, page: 1) { data { id action severity } paginatorInfo { total } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('data.auditLogs.paginatorInfo.total'))->toBe(3);
        expect($response->json('data.auditLogs.data'))->toHaveCount(3);
    });

    it('returns FORBIDDEN for non-admin user', function () {
        $user = createUser();
        $key = createApiKey($user);

        $response = graphQL(
            '{ auditLogs(first: 10, page: 1) { paginatorInfo { total } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('errors'))->not->toBeNull();
    });

    it('filters audit logs by action', function () {
        $admin = createAdminUser();
        $key = createApiKey($admin);

        AuditLog::factory()->create(['action' => 'user.login']);
        AuditLog::factory()->count(2)->create(['action' => 'settings.updated']);

        $response = graphQL(
            '{ auditLogs(first: 10, page: 1, filters: { action: "user.login" }) { paginatorInfo { total } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('data.auditLogs.paginatorInfo.total'))->toBe(1);
    });

    it('paginates results', function () {
        $admin = createAdminUser();
        $key = createApiKey($admin);

        AuditLog::factory()->count(5)->create();

        $response = graphQL(
            '{ auditLogs(first: 2, page: 1) { data { id } paginatorInfo { total hasMorePages } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('data.auditLogs.data'))->toHaveCount(2);
        expect($response->json('data.auditLogs.paginatorInfo.total'))->toBe(5);
        expect($response->json('data.auditLogs.paginatorInfo.hasMorePages'))->toBeTrue();
    });
});

describe('users query', function () {
    it('returns users list for admin', function () {
        $admin = createAdminUser();
        $key = createApiKey($admin);
        createUser();
        createUser();

        $response = graphQL(
            '{ users(first: 10, page: 1) { data { id name email } paginatorInfo { total } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        // admin + 2 regular users = 3
        expect($response->json('data.users.paginatorInfo.total'))->toBeGreaterThanOrEqual(3);
    });

    it('returns FORBIDDEN for non-admin', function () {
        $user = createUser();
        $key = createApiKey($user);

        $response = graphQL(
            '{ users(first: 10, page: 1) { data { id } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('errors'))->not->toBeNull();
    });

    it('searches users by name', function () {
        $admin = createAdminUser();
        $key = createApiKey($admin);
        createUser(['name' => 'Alice Johnson']);
        createUser(['name' => 'Bob Smith']);

        $response = graphQL(
            '{ users(first: 10, page: 1, search: "Alice") { data { name } } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        $names = collect($response->json('data.users.data'))->pluck('name');
        expect($names)->toContain('Alice Johnson');
        expect($names)->not->toContain('Bob Smith');
    });
});

describe('userGroups query', function () {
    it('returns groups for admin', function () {
        $admin = createAdminUser();
        $key = createApiKey($admin);

        $response = graphQL(
            '{ userGroups { id name slug memberCount permissions } }',
            [],
            $key['plaintext']
        )->assertStatus(200);

        expect($response->json('data.userGroups'))->toBeArray();
    });
});
