<?php

/**
 * Settings schema: defines all migratable settings with env fallback.
 *
 * Keys per setting:
 * - env: Original env variable name (for import/fallback)
 * - default: Default value if neither DB nor env is set
 * - encrypted: Whether to encrypt in database
 * - public: Whether visible to unauthenticated users
 */
return [
    'general' => [
        'app_name' => ['env' => 'APP_NAME', 'default' => 'Sourdough', 'public' => true],
        'default_timezone' => ['env' => 'APP_TIMEZONE', 'default' => 'UTC'],
        // app_url removed -- controlled exclusively by APP_URL env var (see config('app.url'))
    ],

    'mail' => [
        'mailer' => ['env' => 'MAIL_MAILER', 'default' => 'log'],
        'smtp_host' => ['env' => 'MAIL_HOST', 'default' => '127.0.0.1'],
        'smtp_port' => ['env' => 'MAIL_PORT', 'default' => 587],
        'smtp_encryption' => ['env' => 'MAIL_ENCRYPTION', 'default' => 'tls'],
        'smtp_username' => ['env' => 'MAIL_USERNAME', 'default' => null],
        'smtp_password' => ['env' => 'MAIL_PASSWORD', 'default' => null, 'encrypted' => true],
        'from_address' => ['env' => 'MAIL_FROM_ADDRESS', 'default' => 'hello@example.com'],
        'from_name' => ['env' => 'MAIL_FROM_NAME', 'default' => 'Sourdough'],
        'mailgun_domain' => ['env' => 'MAILGUN_DOMAIN', 'default' => null],
        'mailgun_secret' => ['env' => 'MAILGUN_SECRET', 'default' => null, 'encrypted' => true],
        'sendgrid_api_key' => ['env' => 'SENDGRID_API_KEY', 'default' => null, 'encrypted' => true],
        'ses_key' => ['env' => 'AWS_ACCESS_KEY_ID', 'default' => null],
        'ses_secret' => ['env' => 'AWS_SECRET_ACCESS_KEY', 'default' => null, 'encrypted' => true],
        'ses_region' => ['env' => 'AWS_DEFAULT_REGION', 'default' => 'us-east-1'],
        'postmark_token' => ['env' => 'POSTMARK_TOKEN', 'default' => null, 'encrypted' => true],
    ],

    'notifications' => [
        // Telegram
        'telegram_bot_token' => ['env' => 'TELEGRAM_BOT_TOKEN', 'default' => null, 'encrypted' => true],
        // Discord
        'discord_webhook_url' => ['env' => 'DISCORD_WEBHOOK_URL', 'default' => null, 'encrypted' => true],
        'discord_bot_name' => ['env' => 'DISCORD_BOT_NAME', 'default' => 'Sourdough'],
        'discord_avatar_url' => ['env' => 'DISCORD_AVATAR_URL', 'default' => null],
        // Slack
        'slack_webhook_url' => ['env' => 'SLACK_WEBHOOK_URL', 'default' => null, 'encrypted' => true],
        'slack_bot_name' => ['env' => 'SLACK_BOT_NAME', 'default' => 'Sourdough'],
        'slack_icon' => ['env' => 'SLACK_ICON', 'default' => ':robot_face:'],
        // Signal
        'signal_cli_path' => ['env' => 'SIGNAL_CLI_PATH', 'default' => null],
        'signal_phone_number' => ['env' => 'SIGNAL_PHONE_NUMBER', 'default' => null, 'encrypted' => true],
        'signal_config_dir' => ['env' => 'SIGNAL_CONFIG_DIR', 'default' => null],
        // Twilio
        'twilio_sid' => ['env' => 'TWILIO_SID', 'default' => null],
        'twilio_token' => ['env' => 'TWILIO_TOKEN', 'default' => null, 'encrypted' => true],
        'twilio_from' => ['env' => 'TWILIO_FROM', 'default' => null],
        // Vonage
        'vonage_api_key' => ['env' => 'VONAGE_API_KEY', 'default' => null],
        'vonage_api_secret' => ['env' => 'VONAGE_API_SECRET', 'default' => null, 'encrypted' => true],
        'vonage_from' => ['env' => 'VONAGE_FROM', 'default' => null],
        // SNS (uses shared AWS creds from mail; only enable flag here)
        'sns_enabled' => ['env' => 'SNS_ENABLED', 'default' => false],
        // WebPush (VAPID)
        'vapid_public_key' => ['env' => 'VAPID_PUBLIC_KEY', 'default' => null],
        'vapid_private_key' => ['env' => 'VAPID_PRIVATE_KEY', 'default' => null, 'encrypted' => true],
        'vapid_subject' => ['env' => 'VAPID_SUBJECT', 'default' => null],
        // FCM (v1 API - service account JSON)
        'fcm_project_id' => ['env' => 'FCM_PROJECT_ID', 'default' => null],
        'fcm_service_account' => ['env' => 'FCM_SERVICE_ACCOUNT', 'default' => null, 'encrypted' => true],
        // ntfy
        'ntfy_enabled' => ['env' => 'NTFY_ENABLED', 'default' => true],
        'ntfy_server' => ['env' => 'NTFY_SERVER', 'default' => 'https://ntfy.sh'],
        // Matrix
        'matrix_homeserver' => ['env' => 'MATRIX_HOMESERVER', 'default' => null],
        'matrix_access_token' => ['env' => 'MATRIX_ACCESS_TOKEN', 'default' => null, 'encrypted' => true],
        'matrix_default_room' => ['env' => 'MATRIX_DEFAULT_ROOM', 'default' => null],
        // Rate limiting
        'rate_limit_enabled' => ['env' => 'NOTIFICATION_RATE_LIMIT_ENABLED', 'default' => false],
        'rate_limit_max' => ['env' => 'NOTIFICATION_RATE_LIMIT_MAX', 'default' => 10],
        'rate_limit_window_minutes' => ['env' => 'NOTIFICATION_RATE_LIMIT_WINDOW', 'default' => 60],
        // Queue
        'queue_enabled' => ['env' => 'NOTIFICATION_QUEUE_ENABLED', 'default' => true],
    ],

    'llm' => [
        'mode' => ['env' => 'LLM_MODE', 'default' => 'single'],
        'primary' => ['env' => 'LLM_PRIMARY', 'default' => 'claude'],
        'timeout' => ['env' => 'LLM_TIMEOUT', 'default' => 120],
        'logging_enabled' => ['env' => 'LLM_LOGGING_ENABLED', 'default' => true],
        'council_min_providers' => ['env' => 'LLM_COUNCIL_MIN_PROVIDERS', 'default' => 2],
        'council_strategy' => ['env' => 'LLM_COUNCIL_STRATEGY', 'default' => 'synthesize'],
        'aggregation_parallel' => ['env' => 'LLM_AGGREGATION_PARALLEL', 'default' => true],
        'aggregation_include_sources' => ['env' => 'LLM_AGGREGATION_INCLUDE_SOURCES', 'default' => true],
    ],

    'sso' => [
        'enabled' => ['env' => 'SSO_ENABLED', 'default' => true],
        'allow_linking' => ['env' => 'SSO_ALLOW_LINKING', 'default' => true],
        'auto_register' => ['env' => 'SSO_AUTO_REGISTER', 'default' => true],
        'trust_provider_email' => ['env' => 'SSO_TRUST_PROVIDER_EMAIL', 'default' => true],
        'google_enabled' => ['env' => null, 'default' => true],
        'github_enabled' => ['env' => null, 'default' => true],
        'microsoft_enabled' => ['env' => null, 'default' => true],
        'apple_enabled' => ['env' => null, 'default' => true],
        'discord_enabled' => ['env' => null, 'default' => true],
        'gitlab_enabled' => ['env' => null, 'default' => true],
        'oidc_enabled' => ['env' => null, 'default' => true],
        'google_test_passed' => ['env' => null, 'default' => false],
        'github_test_passed' => ['env' => null, 'default' => false],
        'microsoft_test_passed' => ['env' => null, 'default' => false],
        'apple_test_passed' => ['env' => null, 'default' => false],
        'discord_test_passed' => ['env' => null, 'default' => false],
        'gitlab_test_passed' => ['env' => null, 'default' => false],
        'oidc_test_passed' => ['env' => null, 'default' => false],
        'google_client_id' => ['env' => 'GOOGLE_CLIENT_ID', 'default' => null],
        'google_client_secret' => ['env' => 'GOOGLE_CLIENT_SECRET', 'default' => null, 'encrypted' => true],
        'github_client_id' => ['env' => 'GITHUB_CLIENT_ID', 'default' => null],
        'github_client_secret' => ['env' => 'GITHUB_CLIENT_SECRET', 'default' => null, 'encrypted' => true],
        'microsoft_client_id' => ['env' => 'MICROSOFT_CLIENT_ID', 'default' => null],
        'microsoft_client_secret' => ['env' => 'MICROSOFT_CLIENT_SECRET', 'default' => null, 'encrypted' => true],
        'apple_client_id' => ['env' => 'APPLE_CLIENT_ID', 'default' => null],
        'apple_client_secret' => ['env' => 'APPLE_CLIENT_SECRET', 'default' => null, 'encrypted' => true],
        'discord_client_id' => ['env' => 'DISCORD_CLIENT_ID', 'default' => null],
        'discord_client_secret' => ['env' => 'DISCORD_CLIENT_SECRET', 'default' => null, 'encrypted' => true],
        'gitlab_client_id' => ['env' => 'GITLAB_CLIENT_ID', 'default' => null],
        'gitlab_client_secret' => ['env' => 'GITLAB_CLIENT_SECRET', 'default' => null, 'encrypted' => true],
        'oidc_client_id' => ['env' => 'OIDC_CLIENT_ID', 'default' => null],
        'oidc_client_secret' => ['env' => 'OIDC_CLIENT_SECRET', 'default' => null, 'encrypted' => true],
        'oidc_issuer_url' => ['env' => 'OIDC_ISSUER_URL', 'default' => null],
        'oidc_provider_name' => ['env' => 'OIDC_PROVIDER_NAME', 'default' => 'Enterprise SSO'],
    ],

    'backup' => [
        // Core
        'disk' => ['env' => 'BACKUP_DISK', 'default' => 'backups'],
        'retention_enabled' => ['env' => 'BACKUP_RETENTION_ENABLED', 'default' => true],
        'retention_days' => ['env' => 'BACKUP_RETENTION_DAYS', 'default' => 30],
        'retention_count' => ['env' => 'BACKUP_RETENTION_COUNT', 'default' => 10],
        'min_backups' => ['env' => 'BACKUP_MIN_BACKUPS', 'default' => 5],
        // Schedule
        'schedule_enabled' => ['env' => 'BACKUP_SCHEDULE_ENABLED', 'default' => false],
        'schedule_frequency' => ['env' => 'BACKUP_SCHEDULE_FREQUENCY', 'default' => 'daily'],
        'schedule_time' => ['env' => 'BACKUP_SCHEDULE_TIME', 'default' => '02:00'],
        'schedule_day' => ['env' => 'BACKUP_SCHEDULE_DAY', 'default' => 0],
        'schedule_date' => ['env' => 'BACKUP_SCHEDULE_DATE', 'default' => 1],
        'scheduled_destinations' => ['env' => 'BACKUP_SCHEDULED_DESTINATIONS', 'default' => 'local'],
        // S3
        's3_enabled' => ['env' => 'BACKUP_S3_ENABLED', 'default' => false],
        's3_bucket' => ['env' => 'BACKUP_S3_BUCKET', 'default' => null],
        's3_path' => ['env' => 'BACKUP_S3_PATH', 'default' => 'backups'],
        's3_access_key_id' => ['env' => 'AWS_ACCESS_KEY_ID', 'default' => null, 'encrypted' => true],
        's3_secret_access_key' => ['env' => 'AWS_SECRET_ACCESS_KEY', 'default' => null, 'encrypted' => true],
        's3_region' => ['env' => 'AWS_DEFAULT_REGION', 'default' => 'us-east-1'],
        's3_endpoint' => ['env' => 'AWS_ENDPOINT', 'default' => null],
        // SFTP
        'sftp_enabled' => ['env' => 'BACKUP_SFTP_ENABLED', 'default' => false],
        'sftp_host' => ['env' => 'BACKUP_SFTP_HOST', 'default' => null],
        'sftp_port' => ['env' => 'BACKUP_SFTP_PORT', 'default' => 22],
        'sftp_username' => ['env' => 'BACKUP_SFTP_USERNAME', 'default' => null],
        'sftp_password' => ['env' => 'BACKUP_SFTP_PASSWORD', 'default' => null, 'encrypted' => true],
        'sftp_private_key' => ['env' => 'BACKUP_SFTP_PRIVATE_KEY', 'default' => null, 'encrypted' => true],
        'sftp_passphrase' => ['env' => 'BACKUP_SFTP_PASSPHRASE', 'default' => null, 'encrypted' => true],
        'sftp_path' => ['env' => 'BACKUP_SFTP_PATH', 'default' => '/backups'],
        // Google Drive
        'gdrive_enabled' => ['env' => 'BACKUP_GDRIVE_ENABLED', 'default' => false],
        'gdrive_client_id' => ['env' => 'BACKUP_GDRIVE_CLIENT_ID', 'default' => null],
        'gdrive_client_secret' => ['env' => 'BACKUP_GDRIVE_CLIENT_SECRET', 'default' => null, 'encrypted' => true],
        'gdrive_refresh_token' => ['env' => 'BACKUP_GDRIVE_REFRESH_TOKEN', 'default' => null, 'encrypted' => true],
        'gdrive_folder_id' => ['env' => 'BACKUP_GDRIVE_FOLDER_ID', 'default' => null],
        // Encryption
        'encryption_enabled' => ['env' => 'BACKUP_ENCRYPTION_ENABLED', 'default' => false],
        'encryption_password' => ['env' => 'BACKUP_ENCRYPTION_PASSWORD', 'default' => null, 'encrypted' => true],
        // Notifications
        'notify_success' => ['env' => 'BACKUP_NOTIFY_SUCCESS', 'default' => false],
        'notify_failure' => ['env' => 'BACKUP_NOTIFY_FAILURE', 'default' => true],
    ],

    'logging' => [
        'app_retention_days' => ['env' => 'LOG_APP_RETENTION_DAYS', 'default' => 90],
        'audit_retention_days' => ['env' => 'AUDIT_LOG_RETENTION_DAYS', 'default' => 365],
        'access_retention_days' => ['env' => 'ACCESS_LOG_RETENTION_DAYS', 'default' => 2190],
        'hipaa_access_logging_enabled' => ['env' => 'HIPAA_ACCESS_LOGGING_ENABLED', 'default' => true],
    ],

    'auth' => [
        'email_verification_mode' => ['env' => 'AUTH_EMAIL_VERIFICATION_MODE', 'default' => 'optional'],
        'password_reset_enabled' => ['env' => 'AUTH_PASSWORD_RESET_ENABLED', 'default' => true],
        'two_factor_mode' => ['env' => 'AUTH_TWO_FACTOR_MODE', 'default' => 'optional'],
        'passkey_mode' => ['env' => 'AUTH_PASSKEY_MODE', 'default' => 'disabled'],
    ],

    'search' => [
        'enabled' => ['env' => 'SEARCH_ENABLED', 'default' => true],
        'use_embedded' => ['env' => 'SEARCH_USE_EMBEDDED', 'default' => true],
        'host' => ['env' => 'MEILISEARCH_HOST', 'default' => 'http://127.0.0.1:7700'],
        'api_key' => ['env' => 'MEILI_MASTER_KEY', 'default' => null, 'encrypted' => true],
        'results_per_page' => ['env' => 'SEARCH_RESULTS_PER_PAGE', 'default' => 15],
        'suggestions_limit' => ['env' => 'SEARCH_SUGGESTIONS_LIMIT', 'default' => 5],
        'min_query_length' => ['env' => 'SEARCH_MIN_QUERY_LENGTH', 'default' => 2],
    ],

    'novu' => [
        'enabled' => ['env' => 'NOVU_ENABLED', 'default' => false, 'public' => true],
        'api_key' => ['env' => 'NOVU_API_KEY', 'default' => '', 'encrypted' => true],
        'app_identifier' => ['env' => 'NOVU_APP_IDENTIFIER', 'default' => '', 'public' => true],
        'api_url' => ['env' => 'NOVU_API_URL', 'default' => 'https://api.novu.co', 'public' => true],
        'socket_url' => ['env' => 'NOVU_SOCKET_URL', 'default' => 'https://ws.novu.co', 'public' => true],
        'workflow_map' => ['env' => null, 'default' => null],
    ],

    'storage' => [
        'storage_alert_enabled' => ['env' => 'STORAGE_ALERT_ENABLED', 'default' => false],
        'storage_alert_threshold' => ['env' => 'STORAGE_ALERT_THRESHOLD', 'default' => 80],
        'storage_alert_critical' => ['env' => 'STORAGE_ALERT_CRITICAL', 'default' => 95],
        'storage_alert_email' => ['env' => 'STORAGE_ALERT_EMAIL', 'default' => true],
    ],

    'stripe' => [
        'secret_key' => ['env' => 'STRIPE_SECRET_KEY', 'default' => null, 'encrypted' => true],
        'publishable_key' => ['env' => 'STRIPE_PUBLISHABLE_KEY', 'default' => null, 'public' => true],
        'webhook_secret' => ['env' => 'STRIPE_WEBHOOK_SECRET', 'default' => null, 'encrypted' => true],
        'platform_account_id' => ['env' => 'STRIPE_PLATFORM_ACCOUNT_ID', 'default' => null],
        'platform_client_id' => ['default' => 'pk_live_51T3IOFLxjkep9LMmNOGaCUjcW2wJ94BADNXlgNPLS6zqpqsG0TKeg5WxDlboeWbKobd3I4sSsMGL7znxFLrG7gMF00hY5PRSme'],
        'application_fee_percent' => ['env' => 'STRIPE_APPLICATION_FEE_PERCENT', 'default' => 1.0],
        'currency' => ['env' => 'STRIPE_CURRENCY', 'default' => 'usd'],
        'mode' => ['env' => 'STRIPE_MODE', 'default' => 'test'],
        'deployment_role' => ['env' => 'STRIPE_DEPLOYMENT_ROLE', 'default' => 'fork'],
        'connected_account_id' => ['env' => 'STRIPE_CONNECTED_ACCOUNT_ID', 'default' => null],
        'connect_onboarding_state' => ['env' => null, 'default' => null],
    ],

    'graphql' => [
        'enabled' => ['env' => null, 'default' => false, 'public' => true],
        'max_keys_per_user' => ['env' => 'GRAPHQL_MAX_KEYS_PER_USER', 'default' => 5],
        'default_rate_limit' => ['env' => 'GRAPHQL_DEFAULT_RATE_LIMIT', 'default' => 60],
        'introspection_enabled' => ['env' => 'GRAPHQL_INTROSPECTION_ENABLED', 'default' => false],
        'max_query_depth' => ['env' => 'GRAPHQL_MAX_QUERY_DEPTH', 'default' => 12],
        'max_query_complexity' => ['env' => 'GRAPHQL_MAX_QUERY_COMPLEXITY', 'default' => 200],
        'max_result_size' => ['env' => 'GRAPHQL_MAX_RESULT_SIZE', 'default' => 100],
        'key_rotation_grace_days' => ['env' => 'GRAPHQL_KEY_ROTATION_GRACE_DAYS', 'default' => 7],
        'cors_allowed_origins' => ['env' => 'GRAPHQL_CORS_ALLOWED_ORIGINS', 'default' => '*'],
    ],

    'usage' => [
        'pricing_llm' => ['env' => 'USAGE_PRICING_LLM', 'default' => '{}'],
        'budget_llm' => ['env' => 'USAGE_BUDGET_LLM', 'default' => null],
        'budget_email' => ['env' => 'USAGE_BUDGET_EMAIL', 'default' => null],
        'budget_sms' => ['env' => 'USAGE_BUDGET_SMS', 'default' => null],
        'budget_storage' => ['env' => 'USAGE_BUDGET_STORAGE', 'default' => null],
        'budget_broadcasting' => ['env' => 'USAGE_BUDGET_BROADCASTING', 'default' => null],
        'budget_payments' => ['env' => 'USAGE_BUDGET_PAYMENTS', 'default' => null],
        'alert_threshold' => ['env' => 'USAGE_ALERT_THRESHOLD', 'default' => 80],
    ],
];
