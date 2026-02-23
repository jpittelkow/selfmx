<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GraphQL Schema
    |--------------------------------------------------------------------------
    */
    'schema_path' => base_path('graphql/schema.graphql'),

    /*
    |--------------------------------------------------------------------------
    | Schema Cache
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enable' => env('LIGHTHOUSE_CACHE_ENABLE', env('APP_ENV', 'production') !== 'local'),
        'store' => env('LIGHTHOUSE_CACHE_STORE', 'file'),
        'key' => env('LIGHTHOUSE_CACHE_KEY', 'lighthouse-schema'),
        'ttl' => env('LIGHTHOUSE_CACHE_TTL', null),
        'version' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    | Prefix is 'api' so the final URL is /api/graphql.
    */
    'route' => [
        'uri' => '/graphql',
        'name' => 'graphql',
        'prefix' => 'api',
        'domain' => null,
        'middleware' => [
            \App\Http\Middleware\GraphQLCors::class,
            \App\Http\Middleware\GraphQLFeatureGate::class,
            \App\Http\Middleware\AddCorrelationId::class,
            'auth:api-key',
            \App\Http\Middleware\ApiKeyRateLimiter::class,
            \App\Http\Middleware\GraphQLUsageTracker::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Authentication Guard
    |--------------------------------------------------------------------------
    */
    'guard' => 'api-key',

    /*
    |--------------------------------------------------------------------------
    | Namespaces
    |--------------------------------------------------------------------------
    */
    'namespaces' => [
        'models' => ['App\\Models'],
        'queries' => ['App\\GraphQL\\Queries'],
        'mutations' => ['App\\GraphQL\\Mutations'],
        'subscriptions' => ['App\\GraphQL\\Subscriptions'],
        'interfaces' => ['App\\GraphQL\\Interfaces'],
        'unions' => ['App\\GraphQL\\Unions'],
        'scalars' => ['App\\GraphQL\\Scalars'],
        'directives' => ['App\\GraphQL\\Directives'],
        'validators' => ['App\\GraphQL\\Validators'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    | Values overridden at runtime by GraphQLServiceProvider from admin settings.
    */
    'security' => [
        'max_query_depth' => 12,
        'max_query_complexity' => 200,
        'disable_introspection' => \GraphQL\Validator\Rules\DisableIntrospection::ENABLED,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'default_count' => 25,
        'max_count' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Handlers
    |--------------------------------------------------------------------------
    */
    'error_handlers' => [
        \Nuwave\Lighthouse\Execution\AuthenticationErrorHandler::class,
        \Nuwave\Lighthouse\Execution\AuthorizationErrorHandler::class,
        \Nuwave\Lighthouse\Execution\ValidationErrorHandler::class,
        \App\GraphQL\ErrorHandlers\GraphQLErrorHandler::class,
        \Nuwave\Lighthouse\Execution\ReportingErrorHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Field Middleware
    |--------------------------------------------------------------------------
    */
    'field_middleware' => [
        \Nuwave\Lighthouse\Schema\Directives\TrimDirective::class,
        \Nuwave\Lighthouse\Schema\Directives\ConvertEmptyStringsToNullDirective::class,
        \Nuwave\Lighthouse\Schema\Directives\SanitizeDirective::class,
        \Nuwave\Lighthouse\Validation\ValidateDirective::class,
        \Nuwave\Lighthouse\Schema\Directives\TransformArgsDirective::class,
        \Nuwave\Lighthouse\Schema\Directives\SpreadArgsDirective::class,
        \Nuwave\Lighthouse\Schema\Directives\RenameArgsDirective::class,
        \Nuwave\Lighthouse\Schema\Directives\DropArgsDirective::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Global ID
    |--------------------------------------------------------------------------
    */
    'global_id_field' => 'id',

    /*
    |--------------------------------------------------------------------------
    | Batching
    |--------------------------------------------------------------------------
    */
    'batching' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transactional Mutations
    |--------------------------------------------------------------------------
    */
    'transactional_mutations' => true,

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment Protection
    |--------------------------------------------------------------------------
    */
    'force_fill' => false,

    /*
    |--------------------------------------------------------------------------
    | Non-null Pagination Results
    |--------------------------------------------------------------------------
    */
    'non_null_pagination_results' => false,

    /*
    |--------------------------------------------------------------------------
    | Eager Load Relations
    |--------------------------------------------------------------------------
    */
    'eager_load_count' => false,

];
