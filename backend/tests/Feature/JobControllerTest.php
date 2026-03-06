<?php

use App\Models\User;
use App\Services\ScheduledTaskService;
use Illuminate\Support\Facades\DB;

describe('JobController', function () {
    describe('authorization', function () {
        it('returns 403 for non-admin user', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user, 'sanctum')->getJson('/api/jobs/scheduled');

            $response->assertStatus(403);
        });

        it('returns 401 when unauthenticated', function () {
            $response = $this->getJson('/api/jobs/scheduled');

            $response->assertStatus(401);
        });
    });

    describe('run', function () {
        it('rejects non-triggerable commands', function () {
            $this->mock(ScheduledTaskService::class, function ($mock) {
                $mock->shouldReceive('isTriggerable')->andReturn(false);
            });

            $response = $this->actingAsAdmin()->postJson('/api/jobs/run/some:dangerous-command');

            $response->assertStatus(403);
        });

        it('runs a triggerable command', function () {
            $this->mock(ScheduledTaskService::class, function ($mock) {
                $mock->shouldReceive('isTriggerable')->andReturn(true);
                $mock->shouldReceive('run')->andReturn([
                    'success' => true,
                    'output' => 'Command completed',
                    'duration_ms' => 150,
                    'exit_code' => 0,
                ]);
            });

            $response = $this->actingAsAdmin()->postJson('/api/jobs/run/cache:clear');

            $response->assertStatus(200);
            $response->assertJsonFragment(['success' => true]);
        });

        it('returns 422 when command fails', function () {
            $this->mock(ScheduledTaskService::class, function ($mock) {
                $mock->shouldReceive('isTriggerable')->andReturn(true);
                $mock->shouldReceive('run')->andReturn([
                    'success' => false,
                    'output' => 'Command failed',
                    'duration_ms' => 50,
                    'exit_code' => 1,
                ]);
            });

            $response = $this->actingAsAdmin()->postJson('/api/jobs/run/migrate');

            $response->assertStatus(422);
        });
    });

    describe('queueStatus', function () {
        it('returns queue stats', function () {
            $response = $this->actingAsAdmin()->getJson('/api/jobs/queue');

            $response->assertStatus(200);
            $response->assertJsonStructure(['pending', 'failed']);
        });
    });

    describe('failedJobs', function () {
        it('returns paginated list', function () {
            $response = $this->actingAsAdmin()->getJson('/api/jobs/failed');

            $response->assertStatus(200);
        });
    });

    describe('retryJob', function () {
        it('returns 404 for missing job', function () {
            $response = $this->actingAsAdmin()->postJson('/api/jobs/failed/99999/retry');

            $response->assertStatus(404);
        });
    });

    describe('deleteJob', function () {
        it('returns 404 for missing job', function () {
            $response = $this->actingAsAdmin()->deleteJson('/api/jobs/failed/99999');

            $response->assertStatus(404);
        });
    });
});
