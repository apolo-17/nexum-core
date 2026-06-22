<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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

    'singapur' => [
        'base_url' => env('SINGAPUR_API_URL', 'http://152.42.206.224:8789'),
        'bearer_token' => env('SINGAPUR_BEARER_TOKEN'),
        'webhook_secret' => env('SINGAPUR_WEBHOOK_SECRET'),
    ],

    // MUA bot — Python microservice that automates the SE/MUA portal via Playwright.
    'mua_bot' => [
        'url' => env('MUA_BOT_URL', 'http://mua-bot:8000'),
        'secret_key' => env('MUA_BOT_SECRET_KEY'),
    ],

    // Anthropic Claude API — used by DocumentAnalysisService for KYC document vision extraction.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

];
