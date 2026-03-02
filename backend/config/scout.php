<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    |
    | This option controls the default search engine that will be used when
    | using the Laravel Scout search methods. You may set this to any of
    | the engines that are supported by Laravel Scout.
    |
    | Supported: "collection", "meilisearch", "algolia", "typesense"
    |
    */

    'driver' => env('SCOUT_DRIVER', 'collection'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | Here you may specify a prefix that will be applied to all search index
    | names used by Scout. This prefix may be useful if you have multiple
    | "tenants" or applications sharing the same search infrastructure.
    |
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    |
    | This option allows you to control if the operations that sync your data
    | with your search engines are queued. When this is set to "true" then
    | all automatic data syncing will get queued for better performance.
    |
    */

    'queue' => env('SCOUT_QUEUE', false),

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    |
    | This configuration option determines if your data will only be synced
    | with your search indexes after every open database transaction has
    | been committed, thus preventing any discarded data from syncing.
    |
    */

    'after_commit' => false,

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    |
    | These options allow you to control the maximum chunk size when you are
    | mass importing data into the search engine.
    |
    */

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | This option allows to control whether to keep soft deleted records in
    | the search indexes.
    |
    */

    'soft_delete' => false,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    |
    | This option allows you to control whether to notify the search engine
    | of the user performing the search.
    |
    */

    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Meilisearch settings. Meilisearch is an open
    | source search engine with minimal configuration.
    |
    | See: https://www.meilisearch.com/docs/learn/configuration/instance_options
    |
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            'users' => [
                'filterableAttributes' => ['id'],
                'sortableAttributes' => ['created_at', 'name'],
            ],
            'notifications' => [
                'filterableAttributes' => ['user_id', 'type'],
                'sortableAttributes' => ['created_at'],
            ],
            'email_templates' => [
                'filterableAttributes' => ['is_active', 'is_system'],
                'sortableAttributes' => ['name'],
            ],
            'api_tokens' => [
                'filterableAttributes' => ['user_id'],
                'sortableAttributes' => ['created_at', 'name'],
            ],
            'ai_providers' => [
                'filterableAttributes' => ['user_id', 'provider', 'is_enabled'],
                'sortableAttributes' => ['provider'],
            ],
            'webhooks' => [
                'filterableAttributes' => ['active'],
                'sortableAttributes' => ['name'],
            ],
            'emails' => [
                'filterableAttributes' => [
                    'user_id',
                    'direction',
                    'from_address',
                    'is_read',
                    'is_starred',
                    'is_draft',
                    'is_spam',
                    'is_trashed',
                    'has_attachment',
                    'label_ids',
                    'sent_at',
                ],
                'sortableAttributes' => ['sent_at'],
                'searchableAttributes' => [
                    'subject',
                    'from_address',
                    'from_name',
                    'body_text',
                    'to_addresses',
                    'cc_addresses',
                ],
            ],
            'contacts' => [
                'filterableAttributes' => ['user_id'],
                'sortableAttributes' => ['email_count', 'display_name'],
                'searchableAttributes' => ['email_address', 'display_name'],
            ],
        ],
    ],

];
