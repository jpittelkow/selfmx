<?php

/**
 * selfmx SSO Configuration
 *
 * Configure which SSO providers are enabled and their settings.
 * All providers are optional for self-hosted deployments.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Enable SSO
    |--------------------------------------------------------------------------
    |
    | Master switch to enable/disable all SSO functionality.
    |
    */

    'enabled' => env('SSO_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Available Providers
    |--------------------------------------------------------------------------
    |
    | List of supported SSO providers. Each provider can be individually
    | enabled by setting their client credentials in .env
    |
    */

    'providers' => [

        'google' => [
            'name' => 'Google',
            'icon' => 'google',
            'enabled' => !empty(env('GOOGLE_CLIENT_ID')),
            'color' => '#4285F4',
        ],

        'github' => [
            'name' => 'GitHub',
            'icon' => 'github',
            'enabled' => !empty(env('GITHUB_CLIENT_ID')),
            'color' => '#333333',
        ],

        'microsoft' => [
            'name' => 'Microsoft',
            'icon' => 'microsoft',
            'enabled' => !empty(env('MICROSOFT_CLIENT_ID')),
            'color' => '#00A4EF',
        ],

        'apple' => [
            'name' => 'Apple',
            'icon' => 'apple',
            'enabled' => !empty(env('APPLE_CLIENT_ID')),
            'color' => '#000000',
        ],

        'discord' => [
            'name' => 'Discord',
            'icon' => 'discord',
            'enabled' => !empty(env('DISCORD_CLIENT_ID')),
            'color' => '#5865F2',
        ],

        'gitlab' => [
            'name' => 'GitLab',
            'icon' => 'gitlab',
            'enabled' => !empty(env('GITLAB_CLIENT_ID')),
            'color' => '#FC6D26',
        ],

        'oidc' => [
            'name' => env('OIDC_PROVIDER_NAME', 'Enterprise SSO'),
            'icon' => 'key',
            'enabled' => !empty(env('OIDC_CLIENT_ID')) && !empty(env('OIDC_ISSUER_URL')),
            'color' => '#6B7280',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Account Linking
    |--------------------------------------------------------------------------
    |
    | Allow users to link multiple SSO providers to the same account.
    |
    */

    'allow_linking' => env('SSO_ALLOW_LINKING', true),

    /*
    |--------------------------------------------------------------------------
    | Auto Registration
    |--------------------------------------------------------------------------
    |
    | Automatically create user accounts for new SSO logins.
    | If disabled, users must register first before linking SSO.
    |
    */

    'auto_register' => env('SSO_AUTO_REGISTER', true),

    /*
    |--------------------------------------------------------------------------
    | Require Email Verification for SSO
    |--------------------------------------------------------------------------
    |
    | Whether to trust the email verification status from SSO providers.
    | If false, emails from SSO providers are considered verified.
    |
    */

    'trust_provider_email' => env('SSO_TRUST_PROVIDER_EMAIL', true),

];
