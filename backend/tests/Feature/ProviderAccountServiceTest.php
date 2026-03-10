<?php

use App\Models\AuditLog;
use App\Models\EmailDomain;
use App\Models\EmailProviderAccount;
use App\Models\User;
use App\Services\Email\ProviderAccountService;

beforeEach(function () {
    $this->service = app(ProviderAccountService::class);
});

describe('ProviderAccountService', function () {
    describe('createAccount', function () {
        it('creates a new provider account', function () {
            $user = User::factory()->admin()->create();

            $account = $this->service->createAccount(
                $user,
                'mailgun',
                'My Mailgun',
                ['api_key' => 'test-key', 'region' => 'us']
            );

            expect($account)->toBeInstanceOf(EmailProviderAccount::class);
            $this->assertDatabaseHas('email_provider_accounts', [
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'My Mailgun',
            ]);
        });

        it('sets first account as default', function () {
            $user = User::factory()->admin()->create();

            $account = $this->service->createAccount(
                $user,
                'mailgun',
                'First Account',
                ['api_key' => 'key1']
            );

            expect($account->is_default)->toBeTrue();
        });

        it('does not set subsequent accounts as default', function () {
            $user = User::factory()->admin()->create();

            $account1 = $this->service->createAccount(
                $user,
                'mailgun',
                'First',
                ['api_key' => 'key1']
            );

            $account2 = $this->service->createAccount(
                $user,
                'mailgun',
                'Second',
                ['api_key' => 'key2']
            );

            expect($account1->is_default)->toBeTrue();
            expect($account2->is_default)->toBeFalse();
        });

        it('logs audit event', function () {
            $user = User::factory()->admin()->create();

            $this->actingAs($user, 'sanctum');

            $this->service->createAccount(
                $user,
                'mailgun',
                'Test Account',
                ['api_key' => 'key123']
            );

            $log = AuditLog::where('action', 'email_provider_account.created')->first();
            expect($log)->not->toBeNull();
            expect($log->new_values)->toHaveKey('name', 'Test Account');
            expect($log->new_values)->toHaveKey('provider', 'mailgun');
        });

        it('allows different providers per user', function () {
            $user = User::factory()->admin()->create();

            $mailgun = $this->service->createAccount(
                $user,
                'mailgun',
                'My Mailgun',
                ['api_key' => 'mg-key']
            );

            $ses = $this->service->createAccount(
                $user,
                'ses',
                'My SES',
                ['access_key_id' => 'aws-key', 'secret_access_key' => 'aws-secret']
            );

            expect($mailgun->provider)->toBe('mailgun');
            expect($ses->provider)->toBe('ses');
            expect($mailgun->is_default)->toBeTrue();
            expect($ses->is_default)->toBeTrue(); // First of its kind
        });
    });

    describe('updateAccount', function () {
        it('updates account name', function () {
            $user = User::factory()->admin()->create();
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Old Name',
                'credentials' => ['api_key' => 'key'],
            ]);

            $this->service->updateAccount($account, ['name' => 'New Name']);

            $this->assertDatabaseHas('email_provider_accounts', [
                'id' => $account->id,
                'name' => 'New Name',
            ]);
        });

        it('updates credentials', function () {
            $user = User::factory()->admin()->create();
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Test',
                'credentials' => ['api_key' => 'old-key'],
            ]);

            $this->service->updateAccount($account, [
                'credentials' => ['api_key' => 'new-key', 'region' => 'eu'],
            ]);

            $account->refresh();
            expect($account->credentials)->toBe(['api_key' => 'new-key', 'region' => 'eu']);
        });

        it('redacts credentials in audit log', function () {
            $user = User::factory()->admin()->create();
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Test',
                'credentials' => ['api_key' => 'secret-key'],
            ]);

            $this->service->updateAccount($account, [
                'credentials' => ['api_key' => 'new-secret'],
            ]);

            $log = AuditLog::where('action', 'email_provider_account.updated')->first();
            expect($log->old_values['credentials'])->toBe('[redacted]');
            expect($log->new_values['credentials'])->toBe('[redacted]');
        });
    });

    describe('deleteAccount', function () {
        it('deletes account with no linked domains', function () {
            $user = User::factory()->admin()->create();
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'To Delete',
                'credentials' => ['api_key' => 'key'],
            ]);

            $this->service->deleteAccount($account);

            $this->assertDatabaseMissing('email_provider_accounts', ['id' => $account->id]);
        });

        it('blocks deletion when domains are linked', function () {
            $user = User::factory()->admin()->create();
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Has Domains',
                'credentials' => ['api_key' => 'key'],
            ]);

            EmailDomain::create([
                'user_id' => $user->id,
                'name' => 'example.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => $account->id,
            ]);

            expect(fn () => $this->service->deleteAccount($account))->toThrow(
                \Symfony\Component\HttpKernel\Exception\HttpException::class
            );

            $this->assertDatabaseHas('email_provider_accounts', ['id' => $account->id]);
        });

        it('promotes next account when deleting default', function () {
            $user = User::factory()->admin()->create();

            $account1 = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account 1',
                'credentials' => ['api_key' => 'key1'],
                'is_default' => true,
            ]);

            $account2 = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account 2',
                'credentials' => ['api_key' => 'key2'],
                'is_default' => false,
            ]);

            $this->service->deleteAccount($account1);

            $account2->refresh();
            expect($account2->is_default)->toBeTrue();
        });

        it('does not promote inactive accounts', function () {
            $user = User::factory()->admin()->create();

            $account1 = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account 1',
                'credentials' => ['api_key' => 'key1'],
                'is_default' => true,
            ]);

            $account2 = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account 2',
                'credentials' => ['api_key' => 'key2'],
                'is_default' => false,
                'is_active' => false,
            ]);

            $account3 = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account 3',
                'credentials' => ['api_key' => 'key3'],
                'is_default' => false,
                'is_active' => true,
            ]);

            $this->service->deleteAccount($account1);

            $account3->refresh();
            expect($account3->is_default)->toBeTrue();
        });

        it('logs audit event', function () {
            $user = User::factory()->admin()->create();
            $this->actingAs($user, 'sanctum');

            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'To Delete',
                'credentials' => ['api_key' => 'key'],
            ]);

            $this->service->deleteAccount($account);

            $log = AuditLog::where('action', 'email_provider_account.deleted')->first();
            expect($log)->not->toBeNull();
        });
    });

    describe('testConnection', function () {
        it('returns error for provider without adapter', function () {
            $user = User::factory()->admin()->create();
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'unknown_provider', // Truly unsupported
                'name' => 'Unknown',
                'credentials' => ['api_key' => 'key'],
            ]);

            $result = $this->service->testConnection($account);

            expect($result['healthy'])->toBeFalse();
            expect($result)->toHaveKey('error');
            expect($result)->toHaveKey('latency_ms');
        });

        it('records health check timestamp', function () {
            $user = User::factory()->admin()->create();
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Test',
                'credentials' => ['api_key' => 'invalid-key'],
            ]);

            expect($account->last_health_check)->toBeNull();

            $this->service->testConnection($account);

            $account->refresh();
            expect($account->last_health_check)->not->toBeNull();
        });
    });

    describe('setDefault', function () {
        it('sets account as default', function () {
            $user = User::factory()->admin()->create();

            $account1 = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account 1',
                'credentials' => ['api_key' => 'key1'],
                'is_default' => true,
            ]);

            $account2 = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account 2',
                'credentials' => ['api_key' => 'key2'],
                'is_default' => false,
            ]);

            $this->service->setDefault($account2);

            $account1->refresh();
            $account2->refresh();
            expect($account1->is_default)->toBeFalse();
            expect($account2->is_default)->toBeTrue();
        });

        it('logs audit event', function () {
            $user = User::factory()->admin()->create();
            $this->actingAs($user, 'sanctum');

            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Test',
                'credentials' => ['api_key' => 'key'],
            ]);

            $this->service->setDefault($account);

            $log = AuditLog::where('action', 'email_provider_account.set_default')->first();
            expect($log)->not->toBeNull();
        });
    });

    describe('getDefaultAccount', function () {
        it('returns default account for provider', function () {
            $user1 = User::factory()->admin()->create();
            $user2 = User::factory()->admin()->create();

            $default = EmailProviderAccount::create([
                'user_id' => $user1->id,
                'provider' => 'mailgun',
                'name' => 'Default',
                'credentials' => ['api_key' => 'key1'],
                'is_default' => true,
            ]);

            $notDefault = EmailProviderAccount::create([
                'user_id' => $user1->id,
                'provider' => 'mailgun',
                'name' => 'Not Default',
                'credentials' => ['api_key' => 'key2'],
                'is_default' => false,
            ]);

            $otherProvider = EmailProviderAccount::create([
                'user_id' => $user1->id,
                'provider' => 'ses',
                'name' => 'SES',
                'credentials' => ['access_key_id' => 'aws'],
                'is_default' => true,
            ]);

            $result = $this->service->getDefaultAccount($user1, 'mailgun');

            expect($result->id)->toBe($default->id);
            expect($result->id)->not->toBe($notDefault->id);
            expect($result->id)->not->toBe($otherProvider->id);
        });

        it('returns null if no default', function () {
            $user = User::factory()->admin()->create();
            $result = $this->service->getDefaultAccount($user, 'mailgun');

            expect($result)->toBeNull();
        });

        it('ignores inactive default accounts', function () {
            $user = User::factory()->admin()->create();

            $inactive = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Inactive',
                'credentials' => ['api_key' => 'key1'],
                'is_default' => true,
                'is_active' => false,
            ]);

            $result = $this->service->getDefaultAccount($user, 'mailgun');

            expect($result)->toBeNull();
        });
    });
});
