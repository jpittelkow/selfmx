<?php

use App\Models\EmailDomain;
use App\Models\EmailProviderAccount;
use App\Models\User;
use App\Models\UserGroup;

beforeEach(function () {
    UserGroup::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'permissions' => []]);
    UserGroup::firstOrCreate(['slug' => 'users'], ['name' => 'Users', 'permissions' => []]);
});

describe('EmailProviderAccountController', function () {
    describe('index', function () {
        it('lists provider accounts for admin', function () {
            $admin = User::factory()->admin()->create();

            EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Production Mailgun',
                'credentials' => ['api_key' => 'test-key'],
                'is_default' => true,
            ]);

            $response = $this->actingAs($admin, 'sanctum')->getJson('/api/email/provider-accounts');

            $response->assertStatus(200);
            $response->assertJsonStructure(['accounts']);
            $response->assertJsonCount(1, 'accounts');
            $response->assertJsonFragment(['name' => 'Production Mailgun']);
        });

        it('does not expose credentials in listing', function () {
            $admin = User::factory()->admin()->create();

            EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Test Account',
                'credentials' => ['api_key' => 'secret-key-123'],
            ]);

            $response = $this->actingAs($admin, 'sanctum')->getJson('/api/email/provider-accounts');

            $response->assertStatus(200);
            $response->assertJsonMissing(['api_key' => 'secret-key-123']);
        });

        it('returns 403 for non-admin user', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user, 'sanctum')->getJson('/api/email/provider-accounts');

            $response->assertStatus(403);
        });
    });

    describe('store', function () {
        it('creates a provider account', function () {
            $response = $this->actingAsAdmin()->postJson('/api/email/provider-accounts', [
                'provider' => 'mailgun',
                'name' => 'My Mailgun',
                'credentials' => ['api_key' => 'key-abc123', 'region' => 'us'],
            ]);

            $response->assertStatus(201);
            $this->assertDatabaseHas('email_provider_accounts', [
                'provider' => 'mailgun',
                'name' => 'My Mailgun',
            ]);
        });

        it('sets first account as default', function () {
            $response = $this->actingAsAdmin()->postJson('/api/email/provider-accounts', [
                'provider' => 'postmark',
                'name' => 'Postmark Account',
                'credentials' => ['server_token' => 'token-123'],
            ]);

            $response->assertStatus(201);
            $account = EmailProviderAccount::where('name', 'Postmark Account')->first();
            expect($account->is_default)->toBeTrue();
        });

        it('validates required fields', function () {
            $response = $this->actingAsAdmin()->postJson('/api/email/provider-accounts', []);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['provider', 'name', 'credentials']);
        });

        it('validates provider is supported', function () {
            $response = $this->actingAsAdmin()->postJson('/api/email/provider-accounts', [
                'provider' => 'invalid_provider',
                'name' => 'Test',
                'credentials' => ['key' => 'value'],
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['provider']);
        });

        it('validates unique name', function () {
            $admin = User::factory()->admin()->create();

            EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Existing Name',
                'credentials' => ['api_key' => 'key'],
            ]);

            $response = $this->actingAs($admin, 'sanctum')->postJson('/api/email/provider-accounts', [
                'provider' => 'mailgun',
                'name' => 'Existing Name',
                'credentials' => ['api_key' => 'other-key'],
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['name']);
        });
    });

    describe('show', function () {
        it('returns account details', function () {
            $admin = User::factory()->admin()->create();

            $account = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'My Mailgun',
                'credentials' => ['api_key' => 'key-123', 'region' => 'us'],
            ]);

            $response = $this->actingAs($admin, 'sanctum')->getJson("/api/email/provider-accounts/{$account->id}");

            $response->assertStatus(200);
            $response->assertJsonStructure(['account', 'credential_fields', 'has_credentials']);
            $response->assertJsonFragment(['name' => 'My Mailgun']);
        });
    });

    describe('update', function () {
        it('updates account name', function () {
            $admin = User::factory()->admin()->create();

            $account = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Old Name',
                'credentials' => ['api_key' => 'key'],
            ]);

            $response = $this->actingAs($admin, 'sanctum')->putJson("/api/email/provider-accounts/{$account->id}", [
                'name' => 'New Name',
            ]);

            $response->assertStatus(200);
            $this->assertDatabaseHas('email_provider_accounts', [
                'id' => $account->id,
                'name' => 'New Name',
            ]);
        });
    });

    describe('destroy', function () {
        it('deletes account with no linked domains', function () {
            $admin = User::factory()->admin()->create();

            $account = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'To Delete',
                'credentials' => ['api_key' => 'key'],
            ]);

            $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/email/provider-accounts/{$account->id}");

            $response->assertStatus(200);
            $this->assertDatabaseMissing('email_provider_accounts', ['id' => $account->id]);
        });

        it('blocks deletion when domains are linked', function () {
            $admin = User::factory()->admin()->create();

            $account = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Has Domains',
                'credentials' => ['api_key' => 'key'],
            ]);

            EmailDomain::create([
                'user_id' => $admin->id,
                'name' => 'example.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => $account->id,
            ]);

            $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/email/provider-accounts/{$account->id}");

            $response->assertStatus(422);
            $this->assertDatabaseHas('email_provider_accounts', ['id' => $account->id]);
        });
    });

    describe('setDefault', function () {
        it('sets account as default and unsets previous', function () {
            $admin = User::factory()->admin()->create();

            $account1 = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Account 1',
                'credentials' => ['api_key' => 'key1'],
                'is_default' => true,
            ]);

            $account2 = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Account 2',
                'credentials' => ['api_key' => 'key2'],
                'is_default' => false,
            ]);

            $response = $this->actingAs($admin, 'sanctum')->postJson("/api/email/provider-accounts/{$account2->id}/default");

            $response->assertStatus(200);
            expect($account1->fresh()->is_default)->toBeFalse();
            expect($account2->fresh()->is_default)->toBeTrue();
        });
    });
});

describe('EmailDomainController with provider accounts', function () {
    beforeEach(function () {
        UserGroup::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'permissions' => []]);
    });

    it('lists domains with provider account info', function () {
        $admin = User::factory()->admin()->create();

        $account = EmailProviderAccount::create([
            'user_id' => $admin->id,
            'provider' => 'mailgun',
            'name' => 'My Mailgun',
            'credentials' => ['api_key' => 'key'],
            'is_default' => true,
        ]);

        EmailDomain::create([
            'user_id' => $admin->id,
            'name' => 'test.com',
            'provider' => 'mailgun',
            'email_provider_account_id' => $account->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/email/domains');

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'test.com']);
    });

    it('filters domains by account_id', function () {
        $admin = User::factory()->admin()->create();

        $account1 = EmailProviderAccount::create([
            'user_id' => $admin->id,
            'provider' => 'mailgun',
            'name' => 'Account 1',
            'credentials' => ['api_key' => 'key1'],
        ]);

        $account2 = EmailProviderAccount::create([
            'user_id' => $admin->id,
            'provider' => 'postmark',
            'name' => 'Account 2',
            'credentials' => ['server_token' => 'token'],
        ]);

        EmailDomain::create([
            'user_id' => $admin->id,
            'name' => 'domain1.com',
            'provider' => 'mailgun',
            'email_provider_account_id' => $account1->id,
        ]);

        EmailDomain::create([
            'user_id' => $admin->id,
            'name' => 'domain2.com',
            'provider' => 'postmark',
            'email_provider_account_id' => $account2->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/email/domains?account_id={$account1->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'domains');
        $response->assertJsonFragment(['name' => 'domain1.com']);
    });
});
