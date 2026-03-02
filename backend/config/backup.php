<?php

/**
 * selfmx Backup Configuration
 *
 * Configure backup and restore functionality.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk to use for storing backups.
    |
    */

    'disk' => env('BACKUP_DISK', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Backup Format Version
    |--------------------------------------------------------------------------
    |
    | Current backup format version for compatibility checking.
    |
    */

    'format_version' => '2.0',

    /*
    |--------------------------------------------------------------------------
    | Include in Backup
    |--------------------------------------------------------------------------
    */

    'include' => [
        'database' => true,
        'files' => true,
        'settings' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Policy
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'enabled' => env('BACKUP_RETENTION_ENABLED', true),
        'days' => env('BACKUP_RETENTION_DAYS', 30),
        'min_backups' => env('BACKUP_MIN_BACKUPS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled Backups
    |--------------------------------------------------------------------------
    */

    'schedule' => [
        'enabled' => env('BACKUP_SCHEDULE_ENABLED', false),
        // Options: daily, weekly, monthly
        'frequency' => env('BACKUP_SCHEDULE_FREQUENCY', 'daily'),
        // Time in 24h format (for daily/weekly)
        'time' => env('BACKUP_SCHEDULE_TIME', '02:00'),
        // Day of week for weekly (0=Sunday, 6=Saturday)
        'day_of_week' => env('BACKUP_SCHEDULE_DAY', 0),
        // Day of month for monthly
        'day_of_month' => env('BACKUP_SCHEDULE_DATE', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Backup Destinations
    |--------------------------------------------------------------------------
    */

    'destinations' => [

        'local' => [
            'enabled' => true,
            'driver' => 'local',
            'path' => storage_path('app/backups'),
        ],

        's3' => [
            'enabled' => env('BACKUP_S3_ENABLED', false),
            'driver' => 's3',
            'bucket' => env('BACKUP_S3_BUCKET', env('AWS_BUCKET')),
            'path' => env('BACKUP_S3_PATH', 'backups'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ],

        'sftp' => [
            'enabled' => env('BACKUP_SFTP_ENABLED', false),
            'driver' => 'sftp',
            'host' => env('BACKUP_SFTP_HOST'),
            'port' => env('BACKUP_SFTP_PORT', 22),
            'username' => env('BACKUP_SFTP_USERNAME'),
            'password' => env('BACKUP_SFTP_PASSWORD'),
            'private_key' => env('BACKUP_SFTP_PRIVATE_KEY'),
            'passphrase' => env('BACKUP_SFTP_PASSPHRASE'),
            'path' => env('BACKUP_SFTP_PATH', '/backups'),
        ],

        'google_drive' => [
            'enabled' => env('BACKUP_GDRIVE_ENABLED', false),
            'driver' => 'google_drive',
            'client_id' => env('BACKUP_GDRIVE_CLIENT_ID'),
            'client_secret' => env('BACKUP_GDRIVE_CLIENT_SECRET'),
            'refresh_token' => env('BACKUP_GDRIVE_REFRESH_TOKEN'),
            'folder_id' => env('BACKUP_GDRIVE_FOLDER_ID'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled Backup Configuration
    |--------------------------------------------------------------------------
    */

    'scheduled' => [
        // Which destinations to use for scheduled backups
        'destinations' => explode(',', env('BACKUP_SCHEDULED_DESTINATIONS', 'local')),

        // What to include in scheduled backups
        'include_database' => true,
        'include_files' => true,
        'include_settings' => true,

        // Retention policy for scheduled backups
        'retention' => [
            'keep_count' => env('BACKUP_RETENTION_COUNT', 10),
            'keep_days' => env('BACKUP_RETENTION_DAYS', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    */

    'encryption' => [
        'enabled' => env('BACKUP_ENCRYPTION_ENABLED', false),
        'password' => env('BACKUP_ENCRYPTION_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        'on_success' => env('BACKUP_NOTIFY_SUCCESS', false),
        'on_failure' => env('BACKUP_NOTIFY_FAILURE', true),
        'channels' => ['database'],
    ],

];
