<?php

namespace App\Providers;

use App\Services\SettingService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConfigServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services: inject database settings into Laravel config.
     */
    public function boot(): void
    {
        if (!$this->databaseReady()) {
            return;
        }

        $settings = $this->app->make(SettingService::class)->all();

        if (isset($settings['mail'])) {
            $this->injectMailConfig($settings['mail']);
        }

        if (isset($settings['notifications'])) {
            $this->injectNotificationsConfig($settings['notifications']);
        }

        if (isset($settings['llm'])) {
            $this->injectLLMConfig($settings['llm']);
        }

        if (isset($settings['sso'])) {
            $this->injectSSOConfig($settings['sso']);
        }

        if (isset($settings['backup'])) {
            $this->injectBackupConfig($settings['backup']);
        }

        if (isset($settings['logging'])) {
            $this->injectLoggingConfig($settings['logging']);
        }

        if (isset($settings['search'])) {
            $this->injectSearchConfig($settings['search']);
        }

        if (isset($settings['novu'])) {
            $this->injectNovuConfig($settings['novu']);
        }

        if (isset($settings['stripe'])) {
            $this->injectStripeConfig($settings['stripe']);
        }

        if (isset($settings['graphql'])) {
            $this->injectGraphQLConfig($settings['graphql']);
        }
    }

    /**
     * Inject GraphQL API settings into config.
     */
    private function injectGraphQLConfig(array $settings): void
    {
        if (array_key_exists('enabled', $settings)) {
            config(['graphql.enabled' => filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)]);
        }
        if (array_key_exists('max_keys_per_user', $settings)) {
            $v = (int) ($settings['max_keys_per_user'] ?? 5);
            config(['graphql.max_keys_per_user' => max(1, min(100, $v))]);
        }
        if (array_key_exists('default_rate_limit', $settings)) {
            $v = (int) ($settings['default_rate_limit'] ?? 60);
            config(['graphql.default_rate_limit' => max(1, min(10000, $v))]);
        }
        if (array_key_exists('introspection_enabled', $settings)) {
            config(['graphql.introspection_enabled' => filter_var($settings['introspection_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)]);
        }
        if (array_key_exists('max_query_depth', $settings)) {
            $v = (int) ($settings['max_query_depth'] ?? 12);
            config(['graphql.max_query_depth' => max(1, min(50, $v))]);
        }
        if (array_key_exists('max_query_complexity', $settings)) {
            $v = (int) ($settings['max_query_complexity'] ?? 200);
            config(['graphql.max_query_complexity' => max(1, min(10000, $v))]);
        }
        if (array_key_exists('max_result_size', $settings)) {
            $v = (int) ($settings['max_result_size'] ?? 100);
            config(['graphql.max_result_size' => max(1, min(1000, $v))]);
        }
        if (array_key_exists('key_rotation_grace_days', $settings)) {
            $v = (int) ($settings['key_rotation_grace_days'] ?? 7);
            config(['graphql.key_rotation_grace_days' => max(0, min(90, $v))]);
        }
        if (array_key_exists('cors_allowed_origins', $settings)) {
            config(['graphql.cors_allowed_origins' => $settings['cors_allowed_origins'] ?? config('app.frontend_url', 'http://localhost:3000')]);
        }
    }

    /**
     * Inject Stripe Connect settings into config.
     */
    private function injectStripeConfig(array $settings): void
    {
        if (array_key_exists('secret_key', $settings)) {
            config(['stripe.secret_key' => $settings['secret_key'] ?? null]);
        }
        if (array_key_exists('publishable_key', $settings)) {
            config(['stripe.publishable_key' => $settings['publishable_key'] ?? null]);
        }
        if (array_key_exists('webhook_secret', $settings)) {
            config(['stripe.webhook_secret' => $settings['webhook_secret'] ?? null]);
        }
        if (array_key_exists('platform_account_id', $settings)) {
            config(['stripe.platform_account_id' => $settings['platform_account_id'] ?? null]);
        }
        if (array_key_exists('platform_client_id', $settings)) {
            config(['stripe.platform_client_id' => $settings['platform_client_id'] ?? null]);
        }
        if (array_key_exists('application_fee_percent', $settings)) {
            $v = (float) ($settings['application_fee_percent'] ?? 1.0);
            config(['stripe.application_fee_percent' => max(0, min(100, $v))]);
        }
        if (array_key_exists('currency', $settings)) {
            config(['stripe.currency' => $settings['currency'] ?? 'usd']);
        }
        if (array_key_exists('mode', $settings)) {
            config(['stripe.mode' => $settings['mode'] ?? 'test']);
        }
        if (array_key_exists('deployment_role', $settings)) {
            config(['stripe.deployment_role' => $settings['deployment_role'] ?? 'fork']);
        }
        if (array_key_exists('connected_account_id', $settings)) {
            config(['stripe.connected_account_id' => $settings['connected_account_id'] ?? null]);
        }
    }

    /**
     * Inject Novu settings into config.
     */
    private function injectNovuConfig(array $settings): void
    {
        if (array_key_exists('enabled', $settings)) {
            config(['novu.enabled' => filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)]);
        }
        if (array_key_exists('api_key', $settings)) {
            config(['novu.api_key' => trim((string) ($settings['api_key'] ?? ''))]);
        }
        if (array_key_exists('app_identifier', $settings)) {
            config(['novu.app_identifier' => $settings['app_identifier'] ?? '']);
        }
        if (array_key_exists('api_url', $settings) && ! empty(trim((string) ($settings['api_url'] ?? '')))) {
            config(['novu.api_url' => rtrim((string) $settings['api_url'], '/')]);
        }
        if (array_key_exists('socket_url', $settings) && ! empty(trim((string) ($settings['socket_url'] ?? '')))) {
            config(['novu.socket_url' => rtrim((string) $settings['socket_url'], '/')]);
        }
        if (array_key_exists('workflow_map', $settings) && ! empty($settings['workflow_map'])) {
            $decoded = is_string($settings['workflow_map'])
                ? json_decode($settings['workflow_map'], true)
                : $settings['workflow_map'];
            if (is_array($decoded)) {
                config(['novu.workflow_map' => array_merge(config('novu.workflow_map', []), $decoded)]);
            }
        }
    }

    /**
     * Inject search settings into config.
     */
    private function injectSearchConfig(array $settings): void
    {
        if (array_key_exists('enabled', $settings)) {
            config(['search.enabled' => filter_var($settings['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)]);
        }
        if (array_key_exists('use_embedded', $settings)) {
            config(['search.use_embedded' => filter_var($settings['use_embedded'] ?? true, FILTER_VALIDATE_BOOLEAN)]);
        }
        if (array_key_exists('host', $settings) && ! empty(trim((string) ($settings['host'] ?? '')))) {
            config(['search.host' => rtrim((string) $settings['host'], '/')]);
            config(['scout.meilisearch.host' => rtrim((string) $settings['host'], '/')]);
        }
        if (array_key_exists('api_key', $settings)) {
            config(['search.api_key' => $settings['api_key'] ?? null]);
            config(['scout.meilisearch.key' => $settings['api_key'] ?? config('scout.meilisearch.key')]);
        }
        if (array_key_exists('results_per_page', $settings)) {
            $v = (int) ($settings['results_per_page'] ?? 15);
            config(['search.results_per_page' => max(5, min(50, $v))]);
        }
        if (array_key_exists('suggestions_limit', $settings)) {
            $v = (int) ($settings['suggestions_limit'] ?? 5);
            config(['search.suggestions_limit' => max(3, min(10, $v))]);
        }
        if (array_key_exists('min_query_length', $settings)) {
            $v = (int) ($settings['min_query_length'] ?? 2);
            config(['search.min_query_length' => max(1, min(5, $v))]);
        }
    }

    /**
     * Inject log retention settings into config.
     */
    private function injectLoggingConfig(array $settings): void
    {
        if (array_key_exists('app_retention_days', $settings)) {
            $v = (int) ($settings['app_retention_days'] ?? config('logging.retention.app_days', 90));
            config(['logging.retention.app_days' => max(1, min(365, $v))]);
        }
        if (array_key_exists('audit_retention_days', $settings)) {
            $v = (int) ($settings['audit_retention_days'] ?? config('logging.retention.audit_days', 365));
            config(['logging.retention.audit_days' => max(30, min(730, $v))]);
        }
    }

    /**
     * Check if database is available, system_settings table exists, and migration has run (is_encrypted column).
     */
    private function databaseReady(): bool
    {
        try {
            DB::connection()->getPdo();
            return Schema::hasTable('system_settings')
                && Schema::hasColumn('system_settings', 'is_encrypted');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Inject mail settings into Laravel config.
     */
    private function injectMailConfig(array $settings): void
    {
        if (isset($settings['mailer'])) {
            config(['mail.default' => $settings['mailer']]);
        }

        config([
            'mail.mailers.smtp.host' => $settings['smtp_host'] ?? config('mail.mailers.smtp.host'),
            'mail.mailers.smtp.port' => $settings['smtp_port'] ?? config('mail.mailers.smtp.port'),
            'mail.mailers.smtp.encryption' => $settings['smtp_encryption'] ?? config('mail.mailers.smtp.encryption'),
            'mail.mailers.smtp.username' => $settings['smtp_username'] ?? config('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password' => $settings['smtp_password'] ?? config('mail.mailers.smtp.password'),
        ]);

        if (!empty($settings['mailgun_domain'])) {
            config(['mail.mailers.mailgun.domain' => $settings['mailgun_domain']]);
        }
        if (isset($settings['mailgun_secret'])) {
            config(['mail.mailers.mailgun.secret' => $settings['mailgun_secret']]);
        }
        if (isset($settings['sendgrid_api_key'])) {
            config(['mail.mailers.sendgrid.api_key' => $settings['sendgrid_api_key']]);
        }
        if (isset($settings['ses_key'])) {
            config(['mail.mailers.ses.key' => $settings['ses_key']]);
        }
        if (isset($settings['ses_secret'])) {
            config(['mail.mailers.ses.secret' => $settings['ses_secret']]);
        }
        if (!empty($settings['ses_region'])) {
            config(['mail.mailers.ses.region' => $settings['ses_region']]);
        }
        if (isset($settings['postmark_token'])) {
            config(['mail.mailers.postmark.token' => $settings['postmark_token']]);
        }

        if (isset($settings['from_address'])) {
            config(['mail.from.address' => $settings['from_address']]);
        }
        if (isset($settings['from_name'])) {
            config(['mail.from.name' => $settings['from_name']]);
        }
    }

    /**
     * Inject notification channel settings into Laravel config.
     */
    private function injectNotificationsConfig(array $settings): void
    {
        // Telegram
        if (array_key_exists('telegram_bot_token', $settings)) {
            config(['notifications.channels.telegram.bot_token' => $settings['telegram_bot_token']]);
            config(['notifications.channels.telegram.enabled' => !empty($settings['telegram_bot_token'])]);
        }
        // Discord
        if (array_key_exists('discord_webhook_url', $settings)) {
            config(['notifications.channels.discord.webhook_url' => $settings['discord_webhook_url']]);
            config(['notifications.channels.discord.enabled' => !empty($settings['discord_webhook_url'])]);
        }
        if (array_key_exists('discord_bot_name', $settings)) {
            config(['notifications.channels.discord.username' => $settings['discord_bot_name'] ?? config('notifications.channels.discord.username')]);
        }
        if (array_key_exists('discord_avatar_url', $settings)) {
            config(['notifications.channels.discord.avatar_url' => $settings['discord_avatar_url']]);
        }
        // Slack
        if (array_key_exists('slack_webhook_url', $settings)) {
            config(['notifications.channels.slack.webhook_url' => $settings['slack_webhook_url']]);
            config(['notifications.channels.slack.enabled' => !empty($settings['slack_webhook_url'])]);
        }
        if (array_key_exists('slack_bot_name', $settings)) {
            config(['notifications.channels.slack.username' => $settings['slack_bot_name'] ?? config('notifications.channels.slack.username')]);
        }
        if (array_key_exists('slack_icon', $settings)) {
            config(['notifications.channels.slack.icon' => $settings['slack_icon'] ?? config('notifications.channels.slack.icon')]);
        }
        // Signal
        if (array_key_exists('signal_cli_path', $settings)) {
            config(['notifications.channels.signal.cli_path' => $settings['signal_cli_path'] ?? config('notifications.channels.signal.cli_path')]);
            config(['notifications.channels.signal.enabled' => !empty($settings['signal_cli_path']) && !empty($settings['signal_phone_number'] ?? null)]);
        }
        if (array_key_exists('signal_phone_number', $settings)) {
            config(['notifications.channels.signal.phone_number' => $settings['signal_phone_number']]);
        }
        if (array_key_exists('signal_config_dir', $settings) && $settings['signal_config_dir'] !== null && $settings['signal_config_dir'] !== '') {
            config(['notifications.channels.signal.config_dir' => $settings['signal_config_dir']]);
        }
        // Twilio
        if (array_key_exists('twilio_sid', $settings)) {
            config(['notifications.channels.twilio.sid' => $settings['twilio_sid']]);
            config(['notifications.channels.twilio.token' => $settings['twilio_token'] ?? null]);
            config(['notifications.channels.twilio.from' => $settings['twilio_from'] ?? null]);
            config(['notifications.channels.twilio.enabled' => !empty($settings['twilio_sid']) && !empty($settings['twilio_token'] ?? null)]);
        }
        // Vonage
        if (array_key_exists('vonage_api_key', $settings)) {
            config(['notifications.channels.vonage.api_key' => $settings['vonage_api_key']]);
            config(['notifications.channels.vonage.api_secret' => $settings['vonage_api_secret'] ?? null]);
            config(['notifications.channels.vonage.from' => $settings['vonage_from'] ?? null]);
            config(['notifications.channels.vonage.enabled' => !empty($settings['vonage_api_key']) && !empty($settings['vonage_api_secret'] ?? null)]);
        }
        // SNS (uses AWS creds from mail)
        if (array_key_exists('sns_enabled', $settings)) {
            config(['notifications.channels.sns.enabled' => !empty(config('mail.mailers.ses.key')) && $settings['sns_enabled']]);
        }
        // WebPush
        if (array_key_exists('vapid_public_key', $settings)) {
            config(['notifications.channels.webpush.public_key' => $settings['vapid_public_key']]);
            config(['notifications.channels.webpush.private_key' => $settings['vapid_private_key'] ?? null]);
            config(['notifications.channels.webpush.subject' => $settings['vapid_subject'] ?? config('app.url')]);
            config(['notifications.channels.webpush.enabled' => !empty($settings['vapid_public_key']) && !empty($settings['vapid_private_key'] ?? null)]);
        }
        // FCM (v1 API)
        if (array_key_exists('fcm_project_id', $settings)) {
            config(['notifications.channels.fcm.project_id' => $settings['fcm_project_id']]);
        }
        if (array_key_exists('fcm_service_account', $settings)) {
            config(['notifications.channels.fcm.service_account' => $settings['fcm_service_account']]);
            config(['notifications.channels.fcm.enabled' => !empty($settings['fcm_project_id'] ?? null) && !empty($settings['fcm_service_account'])]);
        }
        // ntfy
        if (array_key_exists('ntfy_enabled', $settings)) {
            config(['notifications.channels.ntfy.enabled' => $settings['ntfy_enabled']]);
        }
        if (array_key_exists('ntfy_server', $settings)) {
            config(['notifications.channels.ntfy.server' => $settings['ntfy_server'] ?? 'https://ntfy.sh']);
        }
        // Matrix
        if (array_key_exists('matrix_homeserver', $settings)) {
            config(['notifications.channels.matrix.homeserver' => $settings['matrix_homeserver']]);
            config(['notifications.channels.matrix.access_token' => $settings['matrix_access_token'] ?? null]);
            config(['notifications.channels.matrix.default_room' => $settings['matrix_default_room'] ?? null]);
            config(['notifications.channels.matrix.enabled' => !empty($settings['matrix_homeserver']) && !empty($settings['matrix_access_token'] ?? null)]);
        }
    }

    /**
     * Inject LLM system-wide settings into Laravel config.
     */
    private function injectLLMConfig(array $settings): void
    {
        if (array_key_exists('mode', $settings)) {
            config(['llm.mode' => $settings['mode'] ?? config('llm.mode')]);
        }
        if (array_key_exists('primary', $settings)) {
            config(['llm.primary' => $settings['primary'] ?? config('llm.primary')]);
        }
        if (array_key_exists('timeout', $settings)) {
            config(['llm.timeout' => $settings['timeout'] ?? config('llm.timeout')]);
        }
        if (array_key_exists('logging_enabled', $settings)) {
            config(['llm.logging_enabled' => $settings['logging_enabled'] ?? config('llm.logging_enabled')]);
        }
        if (array_key_exists('council_min_providers', $settings)) {
            config(['llm.council.min_providers' => $settings['council_min_providers'] ?? config('llm.council.min_providers')]);
        }
        if (array_key_exists('council_strategy', $settings)) {
            config(['llm.council.resolution_strategy' => $settings['council_strategy'] ?? config('llm.council.resolution_strategy')]);
        }
        if (array_key_exists('aggregation_parallel', $settings)) {
            config(['llm.aggregation.parallel' => $settings['aggregation_parallel'] ?? config('llm.aggregation.parallel')]);
        }
        if (array_key_exists('aggregation_include_sources', $settings)) {
            config(['llm.aggregation.include_sources' => $settings['aggregation_include_sources'] ?? config('llm.aggregation.include_sources')]);
        }
    }

    /**
     * Inject SSO settings into Laravel config (sso.* and services.* for Socialite).
     */
    private function injectSSOConfig(array $settings): void
    {
        if (array_key_exists('enabled', $settings)) {
            config(['sso.enabled' => $settings['enabled'] ?? config('sso.enabled')]);
        }
        if (array_key_exists('allow_linking', $settings)) {
            config(['sso.allow_linking' => $settings['allow_linking'] ?? config('sso.allow_linking')]);
        }
        if (array_key_exists('auto_register', $settings)) {
            config(['sso.auto_register' => $settings['auto_register'] ?? config('sso.auto_register')]);
        }
        if (array_key_exists('trust_provider_email', $settings)) {
            config(['sso.trust_provider_email' => $settings['trust_provider_email'] ?? config('sso.trust_provider_email')]);
        }

        // Provider enabled flags and Socialite credentials
        $providers = ['google', 'github', 'microsoft', 'apple', 'discord', 'gitlab'];
        foreach ($providers as $provider) {
            $clientIdKey = $provider . '_client_id';
            $clientSecretKey = $provider . '_client_secret';
            if (array_key_exists($clientIdKey, $settings)) {
                config(['services.' . $provider . '.client_id' => $settings[$clientIdKey] ?? config('services.' . $provider . '.client_id')]);
            }
            if (array_key_exists($clientSecretKey, $settings)) {
                config(['services.' . $provider . '.client_secret' => $settings[$clientSecretKey] ?? config('services.' . $provider . '.client_secret')]);
            }
            // Always set redirect URI from APP_URL so Socialite has it
            // even when providers are configured via admin UI (no env vars)
            config(['services.' . $provider . '.redirect' => rtrim(config('app.url'), '/') . '/api/auth/callback/' . $provider]);
            $hasCredentials = !empty($settings[$clientIdKey] ?? config('services.' . $provider . '.client_id'));
            $explicitlyEnabled = $settings[$provider . '_enabled'] ?? true;
            config(['sso.providers.' . $provider . '.enabled' => $hasCredentials && $explicitlyEnabled]);
        }

        // OIDC
        if (array_key_exists('oidc_client_id', $settings)) {
            config(['services.oidc.client_id' => $settings['oidc_client_id'] ?? config('services.oidc.client_id')]);
        }
        if (array_key_exists('oidc_issuer_url', $settings)) {
            config(['services.oidc.issuer_url' => $settings['oidc_issuer_url'] ?? config('services.oidc.issuer_url')]);
        }
        $oidcHasCredentials = !empty($settings['oidc_client_id'] ?? config('services.oidc.client_id')) && !empty($settings['oidc_issuer_url'] ?? config('services.oidc.issuer_url'));
        $oidcExplicitlyEnabled = $settings['oidc_enabled'] ?? true;
        config(['sso.providers.oidc.enabled' => $oidcHasCredentials && $oidcExplicitlyEnabled]);
        if (array_key_exists('oidc_client_secret', $settings)) {
            config(['services.oidc.client_secret' => $settings['oidc_client_secret'] ?? config('services.oidc.client_secret')]);
        }
        // Always set redirect URI from APP_URL for OIDC as well
        config(['services.oidc.redirect' => rtrim(config('app.url'), '/') . '/api/auth/callback/oidc']);
        if (array_key_exists('oidc_provider_name', $settings)) {
            config(['sso.providers.oidc.name' => $settings['oidc_provider_name'] ?? config('sso.providers.oidc.name')]);
        }
    }

    /**
     * Inject backup settings into Laravel config.
     */
    private function injectBackupConfig(array $settings): void
    {
        if (array_key_exists('disk', $settings)) {
            config(['backup.disk' => $settings['disk'] ?? config('backup.disk')]);
        }
        if (array_key_exists('retention_enabled', $settings)) {
            config(['backup.retention.enabled' => $settings['retention_enabled'] ?? config('backup.retention.enabled')]);
        }
        if (array_key_exists('retention_days', $settings)) {
            config(['backup.retention.days' => $settings['retention_days'] ?? config('backup.retention.days')]);
            config(['backup.scheduled.retention.keep_days' => $settings['retention_days'] ?? config('backup.scheduled.retention.keep_days')]);
        }
        if (array_key_exists('retention_count', $settings)) {
            config(['backup.scheduled.retention.keep_count' => $settings['retention_count'] ?? config('backup.scheduled.retention.keep_count')]);
        }
        if (array_key_exists('min_backups', $settings)) {
            config(['backup.retention.min_backups' => $settings['min_backups'] ?? config('backup.retention.min_backups')]);
        }
        if (array_key_exists('schedule_enabled', $settings)) {
            config(['backup.schedule.enabled' => $settings['schedule_enabled'] ?? config('backup.schedule.enabled')]);
        }
        if (array_key_exists('schedule_frequency', $settings)) {
            config(['backup.schedule.frequency' => $settings['schedule_frequency'] ?? config('backup.schedule.frequency')]);
        }
        if (array_key_exists('schedule_time', $settings)) {
            config(['backup.schedule.time' => $settings['schedule_time'] ?? config('backup.schedule.time')]);
        }
        if (array_key_exists('schedule_day', $settings)) {
            config(['backup.schedule.day_of_week' => $settings['schedule_day'] ?? config('backup.schedule.day_of_week')]);
        }
        if (array_key_exists('schedule_date', $settings)) {
            config(['backup.schedule.day_of_month' => $settings['schedule_date'] ?? config('backup.schedule.day_of_month')]);
        }
        if (array_key_exists('scheduled_destinations', $settings)) {
            $dest = $settings['scheduled_destinations'] ?? config('backup.scheduled.destinations');
            config(['backup.scheduled.destinations' => is_string($dest) ? array_map('trim', explode(',', $dest)) : $dest]);
        }
        if (array_key_exists('s3_enabled', $settings)) {
            config(['backup.destinations.s3.enabled' => $settings['s3_enabled'] ?? config('backup.destinations.s3.enabled')]);
        }
        if (array_key_exists('s3_bucket', $settings)) {
            config(['backup.destinations.s3.bucket' => $settings['s3_bucket'] ?? config('backup.destinations.s3.bucket')]);
        }
        if (array_key_exists('s3_path', $settings)) {
            config(['backup.destinations.s3.path' => $settings['s3_path'] ?? config('backup.destinations.s3.path')]);
        }
        if (array_key_exists('s3_access_key_id', $settings)) {
            config(['backup.destinations.s3.key' => $settings['s3_access_key_id'] ?? config('backup.destinations.s3.key')]);
        }
        if (array_key_exists('s3_secret_access_key', $settings)) {
            config(['backup.destinations.s3.secret' => $settings['s3_secret_access_key'] ?? config('backup.destinations.s3.secret')]);
        }
        if (array_key_exists('s3_region', $settings)) {
            config(['backup.destinations.s3.region' => $settings['s3_region'] ?? config('backup.destinations.s3.region')]);
        }
        if (array_key_exists('s3_endpoint', $settings)) {
            config(['backup.destinations.s3.endpoint' => $settings['s3_endpoint'] ?? config('backup.destinations.s3.endpoint')]);
        }
        if (array_key_exists('sftp_enabled', $settings)) {
            config(['backup.destinations.sftp.enabled' => $settings['sftp_enabled'] ?? config('backup.destinations.sftp.enabled')]);
        }
        if (array_key_exists('sftp_host', $settings)) {
            config(['backup.destinations.sftp.host' => $settings['sftp_host'] ?? config('backup.destinations.sftp.host')]);
        }
        if (array_key_exists('sftp_port', $settings)) {
            config(['backup.destinations.sftp.port' => $settings['sftp_port'] ?? config('backup.destinations.sftp.port')]);
        }
        if (array_key_exists('sftp_username', $settings)) {
            config(['backup.destinations.sftp.username' => $settings['sftp_username'] ?? config('backup.destinations.sftp.username')]);
        }
        if (array_key_exists('sftp_password', $settings)) {
            config(['backup.destinations.sftp.password' => $settings['sftp_password'] ?? config('backup.destinations.sftp.password')]);
        }
        if (array_key_exists('sftp_private_key', $settings)) {
            config(['backup.destinations.sftp.private_key' => $settings['sftp_private_key'] ?? config('backup.destinations.sftp.private_key')]);
        }
        if (array_key_exists('sftp_passphrase', $settings)) {
            config(['backup.destinations.sftp.passphrase' => $settings['sftp_passphrase'] ?? config('backup.destinations.sftp.passphrase')]);
        }
        if (array_key_exists('sftp_path', $settings)) {
            config(['backup.destinations.sftp.path' => $settings['sftp_path'] ?? config('backup.destinations.sftp.path')]);
        }
        if (array_key_exists('gdrive_enabled', $settings)) {
            config(['backup.destinations.google_drive.enabled' => $settings['gdrive_enabled'] ?? config('backup.destinations.google_drive.enabled')]);
        }
        if (array_key_exists('gdrive_client_id', $settings)) {
            config(['backup.destinations.google_drive.client_id' => $settings['gdrive_client_id'] ?? config('backup.destinations.google_drive.client_id')]);
        }
        if (array_key_exists('gdrive_client_secret', $settings)) {
            config(['backup.destinations.google_drive.client_secret' => $settings['gdrive_client_secret'] ?? config('backup.destinations.google_drive.client_secret')]);
        }
        if (array_key_exists('gdrive_refresh_token', $settings)) {
            config(['backup.destinations.google_drive.refresh_token' => $settings['gdrive_refresh_token'] ?? config('backup.destinations.google_drive.refresh_token')]);
        }
        if (array_key_exists('gdrive_folder_id', $settings)) {
            config(['backup.destinations.google_drive.folder_id' => $settings['gdrive_folder_id'] ?? config('backup.destinations.google_drive.folder_id')]);
        }
        if (array_key_exists('encryption_enabled', $settings)) {
            config(['backup.encryption.enabled' => $settings['encryption_enabled'] ?? config('backup.encryption.enabled')]);
        }
        if (array_key_exists('encryption_password', $settings)) {
            config(['backup.encryption.password' => $settings['encryption_password'] ?? config('backup.encryption.password')]);
        }
        if (array_key_exists('notify_success', $settings)) {
            config(['backup.notifications.on_success' => $settings['notify_success'] ?? config('backup.notifications.on_success')]);
        }
        if (array_key_exists('notify_failure', $settings)) {
            config(['backup.notifications.on_failure' => $settings['notify_failure'] ?? config('backup.notifications.on_failure')]);
        }
    }
}
