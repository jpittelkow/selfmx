<?php

use App\Models\EmailDomain;
use App\Models\EmailProviderAccount;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $settingService = app(SettingService::class);

        // Find the first admin user to own the migrated accounts
        $adminUser = User::whereHas('groups', fn ($q) => $q->where('slug', 'admin'))->first();
        if (! $adminUser) {
            $adminUser = User::first();
        }
        if (! $adminUser) {
            // No users yet — nothing to migrate
            return;
        }

        $providerConfigs = [
            'mailgun' => [
                'credential_keys' => ['api_key', 'region', 'webhook_signing_key'],
                'required_key' => 'api_key',
            ],
            'ses' => [
                'credential_keys' => ['access_key_id', 'secret_access_key', 'region', 'configuration_set'],
                'required_key' => 'access_key_id',
            ],
            'postmark' => [
                'credential_keys' => ['server_token'],
                'required_key' => 'server_token',
            ],
        ];

        DB::transaction(function () use ($settingService, $adminUser, $providerConfigs) {
            foreach ($providerConfigs as $provider => $config) {
                $settings = $settingService->getGroup($provider);
                $requiredValue = $settings[$config['required_key']] ?? '';

                // Skip if no credentials configured
                if (empty($requiredValue)) {
                    continue;
                }

                // Build credentials array
                $credentials = [];
                foreach ($config['credential_keys'] as $key) {
                    if (isset($settings[$key]) && $settings[$key] !== '') {
                        $credentials[$key] = $settings[$key];
                    }
                }

                // Create the default account
                $account = EmailProviderAccount::create([
                    'user_id' => $adminUser->id,
                    'provider' => $provider,
                    'name' => ucfirst($provider) . ' (Default)',
                    'credentials' => $credentials,
                    'is_default' => true,
                    'is_active' => true,
                ]);

                // Link all domains of this provider to the new account
                EmailDomain::where('provider', $provider)
                    ->whereNull('email_provider_account_id')
                    ->update(['email_provider_account_id' => $account->id]);

                Log::info("Migrated {$provider} settings to provider account #{$account->id}, linked domains.");
            }

            // Handle domains with custom provider_config (per-domain credentials)
            $domainsWithConfig = EmailDomain::whereNotNull('provider_config')
                ->whereNotNull('email_provider_account_id')
                ->get();

            foreach ($domainsWithConfig as $domain) {
                $domainConfig = $domain->provider_config;
                if (empty($domainConfig) || ! is_array($domainConfig)) {
                    continue;
                }

                // Check if domain has its own api_key that differs from the account
                $account = $domain->providerAccount;
                if (! $account) {
                    continue;
                }

                $accountKey = $account->credentials[$providerConfigs[$domain->provider]['required_key'] ?? 'api_key'] ?? '';
                $domainKey = $domainConfig[$providerConfigs[$domain->provider]['required_key'] ?? 'api_key'] ?? '';

                if (! empty($domainKey) && $domainKey !== $accountKey) {
                    // Create a separate account for this domain
                    $newAccount = EmailProviderAccount::create([
                        'user_id' => $adminUser->id,
                        'provider' => $domain->provider,
                        'name' => ucfirst($domain->provider) . ' (' . $domain->name . ')',
                        'credentials' => $domainConfig,
                        'is_default' => false,
                        'is_active' => true,
                    ]);

                    $domain->update(['email_provider_account_id' => $newAccount->id]);
                    Log::info("Created separate provider account #{$newAccount->id} for domain {$domain->name}");
                }
            }

            // Clear SendGrid domain assignments (SendGrid is being removed)
            EmailDomain::where('provider', 'sendgrid')
                ->update(['email_provider_account_id' => null]);
        });
    }

    public function down(): void
    {
        // Remove all migrated accounts (domains will have FK set to null via onDelete)
        EmailProviderAccount::query()->delete();
    }
};
