<?php

/**
 * selfmx Version Configuration
 *
 * This file manages version information for the application.
 * The VERSION file in the project root is the source of truth.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | The semantic version of the application. This is read from the VERSION
    | file in the project root, or can be overridden via environment variable.
    |
    */

    'version' => env('APP_VERSION', trim(file_get_contents(base_path('../VERSION')) ?: '0.0.0')),

    /*
    |--------------------------------------------------------------------------
    | Build Information
    |--------------------------------------------------------------------------
    |
    | Git commit SHA and build timestamp, typically set during Docker build.
    |
    */

    'build_sha' => env('APP_BUILD_SHA', 'development'),

    'build_time' => env('APP_BUILD_TIME', null),

    /*
    |--------------------------------------------------------------------------
    | Version Check
    |--------------------------------------------------------------------------
    |
    | Enable checking for newer versions. Set the URL to check against.
    |
    */

    'check_enabled' => env('VERSION_CHECK_ENABLED', false),

    // 'check_url' => env('VERSION_CHECK_URL', 'https://api.github.com/repos/selfmx/selfmx/releases/latest'),
    'check_url' => env('VERSION_CHECK_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Migration Version
    |--------------------------------------------------------------------------
    |
    | Track database schema version separately from application version.
    |
    */

    'schema_version' => env('SCHEMA_VERSION', '1'),

];
