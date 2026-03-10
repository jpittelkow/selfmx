<?php

use App\Models\EmailDomain;
use App\Models\EmailProviderAccount;
use App\Models\User;
use App\Services\Email\MailgunProvider;
use App\Services\Email\ProviderAccountService;

beforeEach(function () {
    $this->service = app(ProviderAccountService::class);
    $this->adminUser = User::factory()->admin()->create();
});

// ─── fetchProviderDomains ────────────────────────────────────────────────────

describe('fetchProviderDomains', function () {
    it('returns domains from provider with import status', function () {
        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'mailgun',
            'name' => 'Test Mailgun',
            'credentials' => ['api_key' => 'test-key', 'region' => 'us'],
            'is_default' => true,
        ]);

        // Create an existing domain so we can test "already_imported" flag
        EmailDomain::create([
            'user_id' => $this->adminUser->id,
            'name' => 'existing.example.com',
            'provider' => 'mailgun',
            'email_provider_account_id' => $account->id,
        ]);

        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andReturn([
            'domains' => [
                ['name' => 'existing.example.com', 'state' => 'active', 'created_at' => '2026-01-01', 'type' => null, 'is_disabled' => false],
                ['name' => 'new.example.com', 'state' => 'active', 'created_at' => '2026-02-01', 'type' => null, 'is_disabled' => false],
                ['name' => 'unverified.example.com', 'state' => 'unverified', 'created_at' => '2026-03-01', 'type' => null, 'is_disabled' => false],
            ],
            'total' => 3,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $result = $this->service->fetchProviderDomains($account);

        expect($result['imported'])->toBe(1);
        expect($result['available'])->toBe(2);
        expect($result['domains'])->toHaveCount(3);

        $existing = collect($result['domains'])->firstWhere('name', 'existing.example.com');
        expect($existing['already_imported'])->toBeTrue();

        $new = collect($result['domains'])->firstWhere('name', 'new.example.com');
        expect($new['already_imported'])->toBeFalse();
    });

    it('returns empty for providers without domain listing', function () {
        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'smtp2go',
            'name' => 'Test SMTP2GO',
            'credentials' => ['api_key' => 'test-key'],
            'is_default' => true,
        ]);

        $result = $this->service->fetchProviderDomains($account);

        expect($result['domains'])->toBe([]);
        expect($result['imported'])->toBe(0);
        expect($result['available'])->toBe(0);
    });
});

// ─── importDomainsFromProvider ───────────────────────────────────────────────

describe('importDomainsFromProvider', function () {
    it('imports active domains from provider', function () {
        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'mailgun',
            'name' => 'Test Mailgun',
            'credentials' => ['api_key' => 'test-key', 'region' => 'us'],
            'is_default' => true,
        ]);

        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andReturn([
            'domains' => [
                ['name' => 'active.example.com', 'state' => 'active', 'created_at' => '2026-01-01', 'is_disabled' => false],
                ['name' => 'another.example.com', 'state' => 'active', 'created_at' => '2026-02-01', 'is_disabled' => false],
                ['name' => 'unverified.example.com', 'state' => 'unverified', 'created_at' => '2026-03-01', 'is_disabled' => false],
                ['name' => 'disabled.example.com', 'state' => 'active', 'created_at' => '2026-03-01', 'is_disabled' => true],
            ],
            'total' => 4,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $result = $this->service->importDomainsFromProvider($account);

        expect($result['imported'])->toHaveCount(2);
        expect($result['skipped'])->toBeEmpty();
        expect($result['errors'])->toBeEmpty();

        // Verify domains were created in DB
        $this->assertDatabaseHas('email_domains', [
            'name' => 'active.example.com',
            'provider' => 'mailgun',
            'email_provider_account_id' => $account->id,
            'user_id' => $this->adminUser->id,
            'is_verified' => true,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('email_domains', [
            'name' => 'another.example.com',
            'provider' => 'mailgun',
            'email_provider_account_id' => $account->id,
        ]);

        // Unverified and disabled should NOT be imported
        $this->assertDatabaseMissing('email_domains', ['name' => 'unverified.example.com']);
        $this->assertDatabaseMissing('email_domains', ['name' => 'disabled.example.com']);
    });

    it('skips already existing domains', function () {
        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'mailgun',
            'name' => 'Test Mailgun',
            'credentials' => ['api_key' => 'test-key', 'region' => 'us'],
            'is_default' => true,
        ]);

        EmailDomain::create([
            'user_id' => $this->adminUser->id,
            'name' => 'existing.example.com',
            'provider' => 'mailgun',
        ]);

        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andReturn([
            'domains' => [
                ['name' => 'existing.example.com', 'state' => 'active', 'created_at' => '2026-01-01', 'is_disabled' => false],
                ['name' => 'new.example.com', 'state' => 'active', 'created_at' => '2026-02-01', 'is_disabled' => false],
            ],
            'total' => 2,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $result = $this->service->importDomainsFromProvider($account);

        expect($result['imported'])->toHaveCount(1);
        expect($result['skipped'])->toBe(['existing.example.com']);

        // Only one domain should exist, not a duplicate
        expect(EmailDomain::where('name', 'existing.example.com')->count())->toBe(1);
    });

    it('imports only specified domains when names provided', function () {
        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'mailgun',
            'name' => 'Test Mailgun',
            'credentials' => ['api_key' => 'test-key', 'region' => 'us'],
            'is_default' => true,
        ]);

        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andReturn([
            'domains' => [
                ['name' => 'one.example.com', 'state' => 'active', 'created_at' => '2026-01-01', 'is_disabled' => false],
                ['name' => 'two.example.com', 'state' => 'active', 'created_at' => '2026-02-01', 'is_disabled' => false],
                ['name' => 'three.example.com', 'state' => 'active', 'created_at' => '2026-03-01', 'is_disabled' => false],
            ],
            'total' => 3,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $result = $this->service->importDomainsFromProvider($account, ['two.example.com']);

        expect($result['imported'])->toHaveCount(1);
        expect($result['imported'][0]->name)->toBe('two.example.com');

        $this->assertDatabaseMissing('email_domains', ['name' => 'one.example.com']);
        $this->assertDatabaseMissing('email_domains', ['name' => 'three.example.com']);
    });

    it('returns error for providers without domain listing', function () {
        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'smtp2go',
            'name' => 'Test SMTP2GO',
            'credentials' => ['api_key' => 'test-key'],
            'is_default' => true,
        ]);

        $result = $this->service->importDomainsFromProvider($account);

        expect($result['imported'])->toBeEmpty();
        expect($result['errors'])->toHaveCount(1);
        expect($result['errors'][0])->toContain('does not support domain listing');
    });
});

// ─── API Endpoints ───────────────────────────────────────────────────────────

describe('API: Provider Account Domain Import', function () {
    it('auto-imports active domains on account creation', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andReturn([
            'domains' => [
                ['name' => 'auto.example.com', 'state' => 'active', 'created_at' => '2026-01-01', 'is_disabled' => false],
            ],
            'total' => 1,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/email/provider-accounts', [
                'provider' => 'mailgun',
                'name' => 'Auto Import Test',
                'credentials' => ['api_key' => 'test-key', 'region' => 'us'],
            ]);

        $response->assertStatus(201);
        $response->assertJsonCount(1, 'imported_domains');
        $response->assertJsonPath('imported_domains.0.name', 'auto.example.com');
        $response->assertJsonPath('imported_domains.0.is_verified', true);

        $this->assertDatabaseHas('email_domains', [
            'name' => 'auto.example.com',
            'user_id' => $this->adminUser->id,
            'is_verified' => true,
        ]);
    });

    it('lists provider domains with import status', function () {
        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'mailgun',
            'name' => 'Test Mailgun',
            'credentials' => ['api_key' => 'test-key', 'region' => 'us'],
            'is_default' => true,
        ]);

        EmailDomain::create([
            'user_id' => $this->adminUser->id,
            'name' => 'existing.example.com',
            'provider' => 'mailgun',
            'email_provider_account_id' => $account->id,
        ]);

        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andReturn([
            'domains' => [
                ['name' => 'existing.example.com', 'state' => 'active', 'created_at' => '2026-01-01', 'is_disabled' => false],
                ['name' => 'new.example.com', 'state' => 'active', 'created_at' => '2026-02-01', 'is_disabled' => false],
            ],
            'total' => 2,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/email/provider-accounts/{$account->id}/domains");

        $response->assertOk();
        $response->assertJsonPath('imported', 1);
        $response->assertJsonPath('available', 1);
        $response->assertJsonCount(2, 'domains');
    });

    it('imports specific domains on demand', function () {
        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'mailgun',
            'name' => 'Test Mailgun',
            'credentials' => ['api_key' => 'test-key', 'region' => 'us'],
            'is_default' => true,
        ]);

        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andReturn([
            'domains' => [
                ['name' => 'pick-me.example.com', 'state' => 'active', 'created_at' => '2026-01-01', 'is_disabled' => false],
                ['name' => 'skip-me.example.com', 'state' => 'active', 'created_at' => '2026-02-01', 'is_disabled' => false],
            ],
            'total' => 2,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/email/provider-accounts/{$account->id}/import-domains", [
                'domains' => ['pick-me.example.com'],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'imported_domains');
        $response->assertJsonPath('imported_domains.0.name', 'pick-me.example.com');

        $this->assertDatabaseHas('email_domains', ['name' => 'pick-me.example.com']);
        $this->assertDatabaseMissing('email_domains', ['name' => 'skip-me.example.com']);
    });

    it('still creates account when auto-import fails', function () {
        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andThrow(new \RuntimeException('API connection failed'));
        $this->app->instance(MailgunProvider::class, $mock);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/email/provider-accounts', [
                'provider' => 'mailgun',
                'name' => 'Broken API Test',
                'credentials' => ['api_key' => 'bad-key', 'region' => 'us'],
            ]);

        $response->assertStatus(201);
        $response->assertJsonCount(0, 'imported_domains');
        $response->assertJsonPath('import_errors.0', fn ($v) => str_contains($v, 'Auto-import failed'));

        $this->assertDatabaseHas('email_provider_accounts', [
            'name' => 'Broken API Test',
        ]);
    });

    it('skips domains already owned by another user', function () {
        $otherUser = User::factory()->admin()->create();
        EmailDomain::create([
            'user_id' => $otherUser->id,
            'name' => 'taken.example.com',
            'provider' => 'mailgun',
        ]);

        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'mailgun',
            'name' => 'Test Mailgun',
            'credentials' => ['api_key' => 'test-key', 'region' => 'us'],
            'is_default' => true,
        ]);

        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andReturn([
            'domains' => [
                ['name' => 'taken.example.com', 'state' => 'active', 'created_at' => '2026-01-01', 'is_disabled' => false],
                ['name' => 'free.example.com', 'state' => 'active', 'created_at' => '2026-02-01', 'is_disabled' => false],
            ],
            'total' => 2,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $result = $this->service->importDomainsFromProvider($account);

        expect($result['imported'])->toHaveCount(1);
        expect($result['imported'][0]->name)->toBe('free.example.com');
        expect($result['skipped'])->toBe(['taken.example.com']);
    });

    it('does not import disabled domains even when explicitly requested', function () {
        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'mailgun',
            'name' => 'Test Mailgun',
            'credentials' => ['api_key' => 'test-key', 'region' => 'us'],
            'is_default' => true,
        ]);

        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andReturn([
            'domains' => [
                ['name' => 'disabled.example.com', 'state' => 'active', 'created_at' => '2026-01-01', 'is_disabled' => true],
                ['name' => 'active.example.com', 'state' => 'active', 'created_at' => '2026-02-01', 'is_disabled' => false],
            ],
            'total' => 2,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $result = $this->service->importDomainsFromProvider($account, ['disabled.example.com', 'active.example.com']);

        expect($result['imported'])->toHaveCount(1);
        expect($result['imported'][0]->name)->toBe('active.example.com');
        $this->assertDatabaseMissing('email_domains', ['name' => 'disabled.example.com']);
    });

    it('sets is_active false for unverified domains', function () {
        $account = EmailProviderAccount::create([
            'user_id' => $this->adminUser->id,
            'provider' => 'mailgun',
            'name' => 'Test Mailgun',
            'credentials' => ['api_key' => 'test-key', 'region' => 'us'],
            'is_default' => true,
        ]);

        $mock = Mockery::mock(MailgunProvider::class)->makePartial();
        $mock->shouldReceive('listProviderDomains')->once()->andReturn([
            'domains' => [
                ['name' => 'unverified.example.com', 'state' => 'unverified', 'created_at' => '2026-01-01', 'is_disabled' => false],
            ],
            'total' => 1,
        ]);
        $this->app->instance(MailgunProvider::class, $mock);

        $result = $this->service->importDomainsFromProvider($account, ['unverified.example.com']);

        expect($result['imported'])->toHaveCount(1);

        $this->assertDatabaseHas('email_domains', [
            'name' => 'unverified.example.com',
            'is_verified' => false,
            'is_active' => false,
        ]);
    });

    it('prevents access to other users provider accounts', function () {
        $otherUser = User::factory()->admin()->create();
        $account = EmailProviderAccount::create([
            'user_id' => $otherUser->id,
            'provider' => 'mailgun',
            'name' => 'Other User Account',
            'credentials' => ['api_key' => 'test-key'],
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/email/provider-accounts/{$account->id}/domains");

        $response->assertStatus(404);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/email/provider-accounts/{$account->id}/import-domains");

        $response->assertStatus(404);
    });
});
