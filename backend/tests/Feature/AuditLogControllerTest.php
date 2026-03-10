<?php

use App\Models\AuditLog;
use App\Services\AuditService;

describe('AuditLogController', function () {
    describe('index', function () {
        it('returns paginated audit logs for admin', function () {
            // Create some audit log entries
            $admin = createAdminUser();
            app(AuditService::class)->log('test.action', $admin);

            $response = $this->actingAs($admin, 'sanctum')->getJson('/api/audit-logs');

            $response->assertStatus(200);
            $response->assertJsonStructure(['data']);
        });

        it('returns 403 for non-admin user', function () {
            $user = createUser();

            $response = $this->actingAs($user, 'sanctum')->getJson('/api/audit-logs');

            $response->assertStatus(403);
        });

        it('supports per_page parameter', function () {
            $admin = createAdminUser();
            // Create multiple entries
            $auditService = app(AuditService::class);
            for ($i = 0; $i < 5; $i++) {
                $auditService->log("test.action_{$i}", $admin);
            }

            $response = $this->actingAs($admin, 'sanctum')
                ->getJson('/api/audit-logs?per_page=2');

            $response->assertStatus(200);
            expect(count($response->json('data')))->toBeLessThanOrEqual(2);
        });

        it('filters by action', function () {
            $admin = createAdminUser();
            $auditService = app(AuditService::class);
            $auditService->log('user.created', $admin);
            $auditService->log('user.deleted', $admin);

            $response = $this->actingAs($admin, 'sanctum')
                ->getJson('/api/audit-logs?action=user.created');

            $response->assertStatus(200);
        });
    });

    describe('export', function () {
        it('returns CSV download for admin', function () {
            $admin = createAdminUser();
            app(AuditService::class)->log('test.export', $admin);

            $response = $this->actingAs($admin, 'sanctum')
                ->get('/api/audit-logs/export');

            $response->assertStatus(200);
            $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        });

        it('returns 403 for non-admin', function () {
            $user = createUser();

            $response = $this->actingAs($user, 'sanctum')
                ->get('/api/audit-logs/export');

            $response->assertStatus(403);
        });
    });

    describe('stats', function () {
        it('returns audit log statistics for admin', function () {
            $admin = createAdminUser();

            $response = $this->actingAs($admin, 'sanctum')
                ->getJson('/api/audit-logs/stats');

            $response->assertStatus(200);
        });

        it('accepts date range parameters', function () {
            $admin = createAdminUser();

            $response = $this->actingAs($admin, 'sanctum')
                ->getJson('/api/audit-logs/stats?date_from=2026-01-01&date_to=2026-12-31');

            $response->assertStatus(200);
        });

        it('returns 403 for non-admin', function () {
            $user = createUser();

            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/audit-logs/stats');

            $response->assertStatus(403);
        });
    });
});
