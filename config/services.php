<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        // Switch to a cheaper model by setting ANTHROPIC_MODEL; no code change needed.
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'timeout' => 20,
    ],

    // Stripe Connect, test mode only (ADR 0015, docs/STRIPE.md). Blank keys
    // mean simulated mode. Live keys are refused (App\Support\StripeMode).
    // Never print or log these values.
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Optional: the signing secret of a second endpoint that listens to
        // events on connected accounts (account.updated). Blank is fine.
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'currency' => 'usd',
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // CloudFront adds this secret as the X-Origin-Verify header on every
    // request it sends to Beanstalk. Only then is its X-Forwarded-For (the
    // visitor's real IP) believed. See App\Http\Middleware\TrustCloudFront.
    'cloudfront' => [
        'origin_secret' => env('CLOUDFRONT_ORIGIN_SECRET'),
    ],

];
