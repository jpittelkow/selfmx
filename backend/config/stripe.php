<?php

/**
 * Stripe Connect Configuration
 *
 * Values are overridden at boot by ConfigServiceProvider when stored in database.
 * See config/settings-schema.php for the full list of migratable settings.
 */

return [
    'secret_key' => env('STRIPE_SECRET_KEY'),
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Connect Platform
    |--------------------------------------------------------------------------
    */
    'platform_account_id' => env('STRIPE_PLATFORM_ACCOUNT_ID'),
    'platform_client_id' => 'pk_live_51T3IOFLxjkep9LMmNOGaCUjcW2wJ94BADNXlgNPLS6zqpqsG0TKeg5WxDlboeWbKobd3I4sSsMGL7znxFLrG7gMF00hY5PRSme',
    'application_fee_percent' => (float) env('STRIPE_APPLICATION_FEE_PERCENT', 1.0),
    'currency' => env('STRIPE_CURRENCY', 'usd'),
    'mode' => env('STRIPE_MODE', 'test'),

    /*
    |--------------------------------------------------------------------------
    | Connected Account (set via OAuth onboarding)
    |--------------------------------------------------------------------------
    */
    'deployment_role' => env('STRIPE_DEPLOYMENT_ROLE', 'fork'),
    'connected_account_id' => env('STRIPE_CONNECTED_ACCOUNT_ID'),
    'connect_onboarding_state' => null,
];
