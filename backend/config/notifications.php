<?php

/**
 * Sourdough Notifications Configuration
 *
 * Configure notification channels and providers.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Notification Channels
    |--------------------------------------------------------------------------
    |
    | Channels to use by default when sending notifications.
    |
    */

    'default_channels' => ['database', 'email', 'webpush', 'fcm', 'ntfy', 'telegram', 'discord', 'slack', 'signal', 'matrix', 'twilio', 'vonage', 'sns'],

    /*
    |--------------------------------------------------------------------------
    | Notification Channels
    |--------------------------------------------------------------------------
    */

    'channels' => [

        /*
        |--------------------------------------------------------------------------
        | Database (In-App) Notifications
        |--------------------------------------------------------------------------
        */

        'database' => [
            'enabled' => true,
            'driver' => 'database',
        ],

        /*
        |--------------------------------------------------------------------------
        | Email Notifications
        |--------------------------------------------------------------------------
        */

        'email' => [
            'enabled' => env('MAIL_MAILER') !== 'log',
            'driver' => 'mail',
            // Uses Laravel mail configuration
        ],

        /*
        |--------------------------------------------------------------------------
        | Telegram Notifications
        |--------------------------------------------------------------------------
        */

        'telegram' => [
            'enabled' => !empty(env('TELEGRAM_BOT_TOKEN')),
            'driver' => 'telegram',
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'parse_mode' => 'HTML',
        ],

        /*
        |--------------------------------------------------------------------------
        | Discord Notifications (Webhook)
        |--------------------------------------------------------------------------
        */

        'discord' => [
            'enabled' => !empty(env('DISCORD_WEBHOOK_URL')),
            'driver' => 'discord',
            'webhook_url' => env('DISCORD_WEBHOOK_URL'),
            'username' => env('DISCORD_BOT_NAME', 'Sourdough'),
            'avatar_url' => env('DISCORD_AVATAR_URL'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Slack Notifications (Webhook)
        |--------------------------------------------------------------------------
        */

        'slack' => [
            'enabled' => !empty(env('SLACK_WEBHOOK_URL')),
            'driver' => 'slack',
            'webhook_url' => env('SLACK_WEBHOOK_URL'),
            'username' => env('SLACK_BOT_NAME', 'Sourdough'),
            'icon' => env('SLACK_ICON', ':robot_face:'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Signal Notifications (via signal-cli)
        |--------------------------------------------------------------------------
        */

        'signal' => [
            'enabled' => !empty(env('SIGNAL_CLI_PATH')) && !empty(env('SIGNAL_PHONE_NUMBER')),
            'driver' => 'signal',
            'cli_path' => env('SIGNAL_CLI_PATH', '/usr/local/bin/signal-cli'),
            'phone_number' => env('SIGNAL_PHONE_NUMBER'),
            'config_dir' => env('SIGNAL_CONFIG_DIR', storage_path('signal')),
        ],

        /*
        |--------------------------------------------------------------------------
        | Matrix Notifications
        |--------------------------------------------------------------------------
        */

        'matrix' => [
            'enabled' => !empty(env('MATRIX_HOMESERVER')) && !empty(env('MATRIX_ACCESS_TOKEN')),
            'driver' => 'matrix',
            'homeserver' => env('MATRIX_HOMESERVER'),
            'access_token' => env('MATRIX_ACCESS_TOKEN'),
            'default_room' => env('MATRIX_DEFAULT_ROOM'),
        ],

        /*
        |--------------------------------------------------------------------------
        | SMS - Twilio
        |--------------------------------------------------------------------------
        */

        'twilio' => [
            'enabled' => !empty(env('TWILIO_SID')) && !empty(env('TWILIO_TOKEN')),
            'driver' => 'twilio',
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],

        /*
        |--------------------------------------------------------------------------
        | SMS - Vonage (Nexmo)
        |--------------------------------------------------------------------------
        */

        'vonage' => [
            'enabled' => !empty(env('VONAGE_API_KEY')) && !empty(env('VONAGE_API_SECRET')),
            'driver' => 'vonage',
            'api_key' => env('VONAGE_API_KEY'),
            'api_secret' => env('VONAGE_API_SECRET'),
            'from' => env('VONAGE_FROM'),
        ],

        /*
        |--------------------------------------------------------------------------
        | SMS - AWS SNS
        |--------------------------------------------------------------------------
        */

        'sns' => [
            'enabled' => !empty(env('AWS_ACCESS_KEY_ID')) && env('SNS_ENABLED', false),
            'driver' => 'sns',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Web Push Notifications
        |--------------------------------------------------------------------------
        */

        'webpush' => [
            'enabled' => !empty(env('VAPID_PUBLIC_KEY')) && !empty(env('VAPID_PRIVATE_KEY')),
            'driver' => 'webpush',
            'public_key' => env('VAPID_PUBLIC_KEY'),
            'private_key' => env('VAPID_PRIVATE_KEY'),
            'subject' => env('VAPID_SUBJECT', env('APP_URL')),
        ],

        /*
        |--------------------------------------------------------------------------
        | Firebase Cloud Messaging (FCM)
        |--------------------------------------------------------------------------
        */

        'fcm' => [
            'enabled' => !empty(env('FCM_PROJECT_ID')) && !empty(env('FCM_SERVICE_ACCOUNT')),
            'driver' => 'fcm',
            'project_id' => env('FCM_PROJECT_ID'),
            'service_account' => env('FCM_SERVICE_ACCOUNT'),
        ],

        /*
        |--------------------------------------------------------------------------
        | ntfy Push Notifications
        |--------------------------------------------------------------------------
        */

        'ntfy' => [
            'enabled' => env('NTFY_ENABLED', false),
            'driver' => 'ntfy',
            'server' => env('NTFY_SERVER', 'https://ntfy.sh'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'enabled' => env('NOTIFICATION_QUEUE_ENABLED', true),
        'connection' => env('NOTIFICATION_QUEUE_CONNECTION', 'database'),
        'queue' => env('NOTIFICATION_QUEUE_NAME', 'notifications'),
    ],

];
