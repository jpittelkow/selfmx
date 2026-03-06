<?php

use App\Models\User;
use App\Services\StorageService;

describe('FileManagerController', function () {
    describe('path traversal protection', function () {
        it('rejects .. in path', function () {
            $response = $this->actingAsAdmin()->getJson('/api/storage/files?path=../../etc/passwd');

            $response->assertStatus(422);
        });

        it('rejects .env path', function () {
            $response = $this->actingAsAdmin()->getJson('/api/storage/files?path=.env');

            $response->assertStatus(422);
        });

        it('rejects config path', function () {
            $response = $this->actingAsAdmin()->getJson('/api/storage/files?path=config');

            $response->assertStatus(422);
        });

        it('rejects .git path', function () {
            $response = $this->actingAsAdmin()->getJson('/api/storage/files?path=.git');

            $response->assertStatus(422);
        });

        it('rejects vendor path', function () {
            $response = $this->actingAsAdmin()->getJson('/api/storage/files?path=vendor');

            $response->assertStatus(422);
        });

        it('rejects null bytes in path', function () {
            $response = $this->actingAsAdmin()->getJson('/api/storage/files?path=test%00.txt');

            $response->assertStatus(422);
        });
    });

    describe('authorization', function () {
        it('returns 403 for non-admin user', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user, 'sanctum')->getJson('/api/storage/files');

            $response->assertStatus(403);
        });

        it('returns 401 when unauthenticated', function () {
            $response = $this->getJson('/api/storage/files');

            $response->assertStatus(401);
        });
    });

    describe('index', function () {
        it('lists files at root', function () {
            $this->mock(StorageService::class, function ($mock) {
                $mock->shouldReceive('listFiles')->andReturn([
                    'files' => [],
                    'total' => 0,
                    'page' => 1,
                    'per_page' => 25,
                ]);
            });

            $response = $this->actingAsAdmin()->getJson('/api/storage/files');

            $response->assertStatus(200);
        });
    });

    describe('upload', function () {
        it('validates files required', function () {
            $response = $this->actingAsAdmin()->postJson('/api/storage/files', []);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['files']);
        });
    });

    describe('rename', function () {
        it('rejects names with slashes', function () {
            $response = $this->actingAsAdmin()->putJson('/api/storage/files/test.txt/rename', [
                'name' => 'evil/name.txt',
            ]);

            $response->assertStatus(422);
        });

        it('rejects names with ..', function () {
            $response = $this->actingAsAdmin()->putJson('/api/storage/files/test.txt/rename', [
                'name' => '..secret',
            ]);

            $response->assertStatus(422);
        });
    });

    describe('move', function () {
        it('rejects invalid destination path', function () {
            $response = $this->actingAsAdmin()->putJson('/api/storage/files/test.txt/move', [
                'destination' => '../../etc',
            ]);

            $response->assertStatus(422);
        });
    });
});
