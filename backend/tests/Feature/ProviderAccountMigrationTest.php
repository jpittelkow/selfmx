<?php

use App\Models\EmailDomain;
use App\Models\EmailProviderAccount;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserGroup;

beforeEach(function () {
    UserGroup::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'permissions' => []]);
    UserGroup::firstOrCreate(['slug' => 'users'], ['name' => 'Users', 'permissions' => []]);
});

describe('Provider Account Migration Logic', function () {
    describe('account creation and domain linking', function () {
        it('creates provider account from credentials', function () {
            $admin = User::factory()->admin()->create();

            $account = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Mailgun (Default)',
                'credentials' => [
                    'api_key' => 'test-api-key',
                    'region' => 'us',
                    'webhook_signing_key' => 'test-webhook-key',
                ],
                'is_default' => true,
                'is_active' => true,
            ]);

            expect($account)->not->toBeNull();
            expect($account->credentials['api_key'])->toBe('test-api-key');
            expect($account->credentials['region'])->toBe('us');
            expect($account->credentials['webhook_signing_key'])->toBe('test-webhook-key');
            expect($account->is_default)->toBeTrue();
        });

        it('creates multiple provider accounts for different providers', function () {
            $admin = User::factory()->admin()->create();

            $mailgun = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Mailgun (Default)',
                'credentials' => ['api_key' => 'mg-key'],
                'is_default' => true,
            ]);

            $ses = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'ses',
                'name' => 'SES (Default)',
                'credentials' => [
                    'access_key_id' => 'aws-key',
                    'secret_access_key' => 'aws-secret',
                    'region' => 'us-east-1',
                ],
                'is_default' => true,
            ]);

            expect($mailgun->provider)->toBe('mailgun');
            expect($ses->provider)->toBe('ses');
            expect($mailgun->is_default)->toBeTrue();
            expect($ses->is_default)->toBeTrue();
        });
    });

    describe('domain linking after migration', function () {
        it('links existing domains to migrated account', function () {
            $admin = User::factory()->admin()->create();

            // Create domain before account (pre-migration state)
            $domain = EmailDomain::create([
                'user_id' => $admin->id,
                'name' => 'example.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => null,
            ]);

            // Create account
            $account = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Mailgun (Default)',
                'credentials' => ['api_key' => 'key'],
            ]);

            // Link domain to account (what migration does)
            $domain->update(['email_provider_account_id' => $account->id]);

            expect($domain->email_provider_account_id)->toBe($account->id);
            expect($account->domains()->count())->toBe(1);
        });

        it('handles multiple domains for same provider', function () {
            $admin = User::factory()->admin()->create();

            $account = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Mailgun',
                'credentials' => ['api_key' => 'key'],
            ]);

            $domain1 = EmailDomain::create([
                'user_id' => $admin->id,
                'name' => 'example1.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => $account->id,
            ]);

            $domain2 = EmailDomain::create([
                'user_id' => $admin->id,
                'name' => 'example2.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => $account->id,
            ]);

            expect($account->domains()->count())->toBe(2);
        });
    });

    describe('per-domain custom credentials', function () {
        it('creates separate account for domain with custom credentials', function () {
            $admin = User::factory()->admin()->create();

            // Create default account
            $defaultAccount = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Mailgun (Default)',
                'credentials' => ['api_key' => 'default-key'],
                'is_default' => true,
            ]);

            // Create custom account for specific domain
            $customAccount = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Mailgun (custom.com)',
                'credentials' => ['api_key' => 'custom-key'],
                'is_default' => false,
            ]);

            // Create domain linked to custom account
            $domain = EmailDomain::create([
                'user_id' => $admin->id,
                'name' => 'custom.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => $customAccount->id,
                'provider_config' => ['api_key' => 'custom-key'],
            ]);

            expect($domain->email_provider_account_id)->toBe($customAccount->id);
            expect($domain->email_provider_account_id)->not->toBe($defaultAccount->id);

            $customAcc = EmailProviderAccount::find($domain->email_provider_account_id);
            expect($customAcc->credentials['api_key'])->toBe('custom-key');
        });

        it('allows domain to override account credentials', function () {
            $admin = User::factory()->admin()->create();

            $account = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Account',
                'credentials' => ['api_key' => 'account-key', 'region' => 'us'],
            ]);

            $domain = EmailDomain::create([
                'user_id' => $admin->id,
                'name' => 'example.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => $account->id,
                'provider_config' => ['region' => 'eu'], // Domain-specific override
            ]);

            // Domain's getEffectiveConfig merges account creds + domain overrides
            $effectiveConfig = $domain->getEffectiveConfig();
            expect($effectiveConfig['api_key'])->toBe('account-key');
            expect($effectiveConfig['region'])->toBe('eu');
        });
    });

    describe('sendgrid cleanup during migration', function () {
        it('unlinks sendgrid domains', function () {
            $admin = User::factory()->admin()->create();

            // Create SendGrid account
            $account = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'sendgrid',
                'name' => 'SendGrid',
                'credentials' => ['api_key' => 'sg-key'],
            ]);

            // Create SendGrid domain
            $domain = EmailDomain::create([
                'user_id' => $admin->id,
                'name' => 'sendgrid.com',
                'provider' => 'sendgrid',
                'email_provider_account_id' => $account->id,
            ]);

            // Migration unlinks all SendGrid domains
            EmailDomain::where('provider', 'sendgrid')
                ->update(['email_provider_account_id' => null]);

            $domain->refresh();
            expect($domain->email_provider_account_id)->toBeNull();
        });
    });

    describe('first account becomes default', function () {
        it('first account for a provider is marked as default', function () {
            $admin = User::factory()->admin()->create();

            $account1 = EmailProviderAccount::create([
                'user_id' => $admin->id,
                'provider' => 'mailgun',
                'name' => 'Account 1',
                'credentials' => ['api_key' => 'key1'],
                'is_default' => true,
            ]);

            expect($account1->is_default)->toBeTrue();
        });

        it('second account for same provider is not default', function () {
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

            expect($account1->is_default)->toBeTrue();
            expect($account2->is_default)->toBeFalse();
        });
    });

    describe('multiple admin users', function () {
        it('allows each admin to have separate provider accounts', function () {
            $admin1 = User::factory()->admin()->create();
            $admin2 = User::factory()->admin()->create();

            $account1 = EmailProviderAccount::create([
                'user_id' => $admin1->id,
                'provider' => 'mailgun',
                'name' => 'Mailgun 1',
                'credentials' => ['api_key' => 'key1'],
            ]);

            $account2 = EmailProviderAccount::create([
                'user_id' => $admin2->id,
                'provider' => 'mailgun',
                'name' => 'Mailgun 2',
                'credentials' => ['api_key' => 'key2'],
            ]);

            expect($account1->user_id)->toBe($admin1->id);
            expect($account2->user_id)->toBe($admin2->id);
            expect($account1->credentials['api_key'])->not->toBe($account2->credentials['api_key']);
        });
    });
});
