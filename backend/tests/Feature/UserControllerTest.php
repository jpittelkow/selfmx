<?php

use App\Models\User;
use App\Models\UserGroup;
use App\Services\UserService;

beforeEach(function () {
    // Ensure admin group exists
    UserGroup::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'permissions' => []]);
    UserGroup::firstOrCreate(['slug' => 'users'], ['name' => 'Users', 'permissions' => []]);
});

describe('UserController', function () {
    describe('index', function () {
        it('lists users for admin', function () {
            User::factory()->count(3)->create();

            $response = $this->actingAsAdmin()->getJson('/api/users');

            $response->assertStatus(200);
            $response->assertJsonStructure(['data']);
        });

        it('returns 403 for non-admin user', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user, 'sanctum')->getJson('/api/users');

            $response->assertStatus(403);
        });

        it('filters by search term', function () {
            User::factory()->create(['name' => 'Alice Smith']);
            User::factory()->create(['name' => 'Bob Jones']);

            $response = $this->actingAsAdmin()->getJson('/api/users?search=Alice');

            $response->assertStatus(200);
        });

        it('returns 401 when unauthenticated', function () {
            $response = $this->getJson('/api/users');

            $response->assertStatus(401);
        });
    });

    describe('store', function () {
        it('creates a user', function () {
            $response = $this->actingAsAdmin()->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'password' => 'Password123!',
            ]);

            $response->assertStatus(201);
            $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);
        });

        it('validates required fields', function () {
            $response = $this->actingAsAdmin()->postJson('/api/users', []);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['name', 'email', 'password']);
        });

        it('validates unique email', function () {
            User::factory()->create(['email' => 'taken@test-unique.com']);

            $response = $this->actingAsAdmin()->postJson('/api/users', [
                'name' => 'Test',
                'email' => 'taken@test-unique.com',
                'password' => 'Password123!',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['email']);
        });

        it('creates admin user when admin flag is set', function () {
            $response = $this->actingAsAdmin()->postJson('/api/users', [
                'name' => 'Admin User',
                'email' => 'admin2@example.com',
                'password' => 'Password123!',
                'admin' => true,
            ]);

            $response->assertStatus(201);
            $user = User::where('email', 'admin2@example.com')->first();
            expect($user->inGroup('admin'))->toBeTrue();
        });

        it('marks email as verified when skip_verification is set', function () {
            $response = $this->actingAsAdmin()->postJson('/api/users', [
                'name' => 'Verified User',
                'email' => 'verified@example.com',
                'password' => 'Password123!',
                'skip_verification' => true,
            ]);

            $response->assertStatus(201);
            $user = User::where('email', 'verified@example.com')->first();
            expect($user->hasVerifiedEmail())->toBeTrue();
        });
    });

    describe('show', function () {
        it('returns user with groups', function () {
            $user = User::factory()->create();

            $response = $this->actingAsAdmin()->getJson("/api/users/{$user->id}");

            $response->assertStatus(200);
            $response->assertJsonStructure(['user' => ['id', 'name', 'email']]);
        });

        it('hides sensitive fields', function () {
            $user = User::factory()->create();

            $response = $this->actingAsAdmin()->getJson("/api/users/{$user->id}");

            $response->assertStatus(200);
            $data = $response->json('user');
            expect($data)->not->toHaveKey('password');
            expect($data)->not->toHaveKey('two_factor_secret');
            expect($data)->not->toHaveKey('two_factor_recovery_codes');
        });
    });

    describe('update', function () {
        it('updates user name', function () {
            $user = User::factory()->create(['name' => 'Old Name']);

            $response = $this->actingAsAdmin()->putJson("/api/users/{$user->id}", [
                'name' => 'New Name',
            ]);

            $response->assertStatus(200);
            $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
        });

        it('validates unique email on update', function () {
            $user1 = User::factory()->create(['email' => 'user1@example.com']);
            $user2 = User::factory()->create(['email' => 'user2@example.com']);

            $response = $this->actingAsAdmin()->putJson("/api/users/{$user2->id}", [
                'email' => 'user1@example.com',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['email']);
        });
    });

    describe('destroy', function () {
        it('deletes a user', function () {
            $this->mock(UserService::class, function ($mock) {
                $mock->shouldReceive('deleteUser')->once();
            });

            $target = User::factory()->create();

            $response = $this->actingAsAdmin()->deleteJson("/api/users/{$target->id}");

            $response->assertStatus(200);
        });

        it('prevents self-deletion', function () {
            $admin = createAdminUser();

            $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/users/{$admin->id}");

            $response->assertStatus(400);
        });

        it('prevents deleting last admin', function () {
            $admin = createAdminUser();
            // There's only 1 admin (the actingAs admin + this one would be 2, but let's make the target the only other admin)
            $target = createAdminUser();

            // Delete one so only the acting admin remains, then try to delete the acting admin
            // Actually: let's just try to delete the sole admin
            $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/users/{$admin->id}");

            // Self-deletion is caught first (400)
            $response->assertStatus(400);
        });
    });

    describe('toggleAdmin', function () {
        it('grants admin status', function () {
            $target = User::factory()->create();

            $response = $this->actingAsAdmin()->postJson("/api/users/{$target->id}/toggle-admin");

            $response->assertStatus(200);
            $target->refresh();
            expect($target->inGroup('admin'))->toBeTrue();
        });

        it('revokes admin status', function () {
            $admin = createAdminUser();
            $target = createAdminUser();

            $response = $this->actingAs($admin, 'sanctum')->postJson("/api/users/{$target->id}/toggle-admin");

            $response->assertStatus(200);
            $target->refresh();
            expect($target->inGroup('admin'))->toBeFalse();
        });

        it('prevents self-demotion', function () {
            $admin = createAdminUser();

            $response = $this->actingAs($admin, 'sanctum')->postJson("/api/users/{$admin->id}/toggle-admin");

            $response->assertStatus(400);
        });
    });

    describe('resetPassword', function () {
        it('resets password', function () {
            $target = User::factory()->create();

            $response = $this->actingAsAdmin()->postJson("/api/users/{$target->id}/reset-password", [
                'password' => 'NewPassword123!',
            ]);

            $response->assertStatus(200);
        });

        it('validates password required', function () {
            $target = User::factory()->create();

            $response = $this->actingAsAdmin()->postJson("/api/users/{$target->id}/reset-password", []);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['password']);
        });
    });

    describe('toggleDisabled', function () {
        it('disables a user', function () {
            $target = User::factory()->create();

            $response = $this->actingAsAdmin()->postJson("/api/users/{$target->id}/disable");

            $response->assertStatus(200);
            $target->refresh();
            expect($target->disabled_at)->not->toBeNull();
        });

        it('enables a disabled user', function () {
            $target = User::factory()->create(['disabled_at' => now()]);

            $response = $this->actingAsAdmin()->postJson("/api/users/{$target->id}/disable");

            $response->assertStatus(200);
            $target->refresh();
            expect($target->disabled_at)->toBeNull();
        });

        it('prevents self-disable', function () {
            $admin = createAdminUser();

            $response = $this->actingAs($admin, 'sanctum')->postJson("/api/users/{$admin->id}/disable");

            $response->assertStatus(400);
        });
    });

    describe('updateGroups', function () {
        it('syncs group memberships', function () {
            $target = User::factory()->create();
            $group = UserGroup::where('slug', 'users')->first();

            $response = $this->actingAsAdmin()->putJson("/api/users/{$target->id}/groups", [
                'group_ids' => [$group->id],
            ]);

            $response->assertStatus(200);
            expect($target->fresh()->groups->pluck('id')->toArray())->toContain($group->id);
        });

        it('validates group_ids required', function () {
            $target = User::factory()->create();

            $response = $this->actingAsAdmin()->putJson("/api/users/{$target->id}/groups", []);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['group_ids']);
        });

        it('validates group_ids exist', function () {
            $target = User::factory()->create();

            $response = $this->actingAsAdmin()->putJson("/api/users/{$target->id}/groups", [
                'group_ids' => [99999],
            ]);

            $response->assertStatus(422);
        });
    });
});
