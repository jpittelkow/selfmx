<?php

use App\Models\EmailDomain;
use App\Models\EmailProviderAccount;
use App\Models\User;
use App\Services\Email\DomainService;

beforeEach(function () {
    $this->domainService = app(DomainService::class);
});

describe('Backward Compatibility — Domains Without Provider Accounts', function () {
    describe('credential resolution fallback', function () {
        it('prefers account FK when provided', function () {
            $user = User::factory()->admin()->create();

            // Create account
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account',
                'credentials' => ['api_key' => 'account-key'],
            ]);

            // Create domain with account FK
            $domain = EmailDomain::create([
                'user_id' => $user->id,
                'name' => 'with-account.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => $account->id,
            ]);

            // Resolve credentials
            $credentials = $this->domainService->getCredentialsForDomain($domain);

            expect($credentials['api_key'])->toBe('account-key');
        });

        it('uses domain provider_config when account FK is null', function () {
            $user = User::factory()->admin()->create();

            // Create domain with domain-specific config but no account
            $domain = EmailDomain::create([
                'user_id' => $user->id,
                'name' => 'custom.example.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => null,
                'provider_config' => ['api_key' => 'domain-key'], // Domain override
            ]);

            $credentials = $this->domainService->getCredentialsForDomain($domain);

            expect($credentials['api_key'])->toBe('domain-key');
        });

        it('merges account credentials with domain overrides', function () {
            $user = User::factory()->admin()->create();

            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account',
                'credentials' => [
                    'api_key' => 'account-key',
                    'region' => 'us',
                ],
            ]);

            $domain = EmailDomain::create([
                'user_id' => $user->id,
                'name' => 'merged.example.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => $account->id,
                'provider_config' => [
                    'region' => 'eu', // Override region only
                ],
            ]);

            $credentials = $this->domainService->getCredentialsForDomain($domain);

            expect($credentials['api_key'])->toBe('account-key');
            expect($credentials['region'])->toBe('eu');
        });

        it('returns fallback when no account', function () {
            $user = User::factory()->admin()->create();

            // No account, no domain config — will use SettingService which may return defaults
            $domain = EmailDomain::create([
                'user_id' => $user->id,
                'name' => 'fallback.example.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => null,
            ]);

            // With no domain config and no account, it falls back to SettingService
            // which may return default empty values from settings-schema
            $credentials = $this->domainService->getCredentialsForDomain($domain);

            // Should be a dict (possibly with schema defaults) not null
            expect($credentials)->toBeArray();
        });
    });

    describe('mixed migration state', function () {
        it('handles some domains with accounts, some without', function () {
            $user = User::factory()->admin()->create();

            // Create account
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account',
                'credentials' => ['api_key' => 'account-key'],
            ]);

            // Domain with account
            $domainWithAccount = EmailDomain::create([
                'user_id' => $user->id,
                'name' => 'with-account.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => $account->id,
            ]);

            // Domain without account (pre-migration)
            $domainWithoutAccount = EmailDomain::create([
                'user_id' => $user->id,
                'name' => 'without-account.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => null,
                'provider_config' => ['api_key' => 'legacy-key'],
            ]);

            $credsWithAccount = $this->domainService->getCredentialsForDomain($domainWithAccount);
            $credsWithoutAccount = $this->domainService->getCredentialsForDomain($domainWithoutAccount);

            expect($credsWithAccount['api_key'])->toBe('account-key');
            expect($credsWithoutAccount['api_key'])->toBe('legacy-key');
        });

        it('allows gradual migration — domains can be linked to accounts as needed', function () {
            $user = User::factory()->admin()->create();

            // Domain using domain config (pre-migration)
            $domain = EmailDomain::create([
                'user_id' => $user->id,
                'name' => 'gradual.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => null,
                'provider_config' => ['api_key' => 'domain-specific-key'],
            ]);

            // Verify it uses domain config
            $credsBefore = $this->domainService->getCredentialsForDomain($domain);
            expect($credsBefore['api_key'])->toBe('domain-specific-key');

            // Post-migration: create account and link domain
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account',
                'credentials' => ['api_key' => 'account-key'],
            ]);

            $domain->update(['email_provider_account_id' => $account->id]);

            // Now it merges account + domain_config. Domain config overrides account creds.
            // To fully migrate, domain_config should be cleared or migrated to the account
            $credsAfter = $this->domainService->getCredentialsForDomain($domain);
            expect($credsAfter['api_key'])->toBe('domain-specific-key'); // domain_config takes precedence
        });
    });

    describe('provider account deletion safety', function () {
        it('unlinked domains are not affected by account deletion', function () {
            $user = User::factory()->admin()->create();

            // Create account
            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account',
                'credentials' => ['api_key' => 'account-key'],
            ]);

            // Domain not linked to account (using domain config)
            $domain = EmailDomain::create([
                'user_id' => $user->id,
                'name' => 'unlinked.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => null,
                'provider_config' => ['api_key' => 'domain-key'],
            ]);

            // Delete account
            $account->delete();

            // Domain should still work via domain config
            $credentials = $this->domainService->getCredentialsForDomain($domain);
            expect($credentials['api_key'])->toBe('domain-key');
        });

        it('linked domains continue working if account exists', function () {
            $user = User::factory()->admin()->create();

            $account = EmailProviderAccount::create([
                'user_id' => $user->id,
                'provider' => 'mailgun',
                'name' => 'Account',
                'credentials' => ['api_key' => 'account-key'],
            ]);

            $domain = EmailDomain::create([
                'user_id' => $user->id,
                'name' => 'linked.com',
                'provider' => 'mailgun',
                'email_provider_account_id' => $account->id,
            ]);

            // Verify domain works before deletion
            $creds = $this->domainService->getCredentialsForDomain($domain);
            expect($creds['api_key'])->toBe('account-key');

            // Account still exists, domain still works
            expect($account->exists)->toBeTrue();
            $credsAfter = $this->domainService->getCredentialsForDomain($domain);
            expect($credsAfter['api_key'])->toBe('account-key');
        });
    });
});
