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
        // Relay endpoint we POST a lightweight "document ready" alert to (pull model).
        // When empty, alerts are skipped (e.g. local dev) so nothing is dispatched.
        'document_alert_url' => env('SINGAPUR_DOCUMENT_ALERT_URL'),
        // A deliverable PDF larger than this (bytes) is Ghostscript-compressed before the
        // relay pulls it. China's multi-hop Drive upload pipeline chokes on files bigger than
        // a few MB (a 6 MB file already times out), so we compress aggressively toward this
        // target. Scanned CSF/RPP/domicilio drop well under 1 MB; only multi-page actas can't.
        'relay_max_bytes' => (int) env('SINGAPUR_RELAY_MAX_BYTES', (int) (2.5 * 1024 * 1024)),
        // Hard ceiling China can actually ingest. After compression, a served file still above
        // this is NOT sent — it is marked failed with a clear reason instead of hanging ~130 s
        // on China's timeout. Actas (which compress to ~6 MB at best) land here until China
        // fixes its upload pipeline. Default 3.5 MB.
        'china_max_bytes' => (int) env('SINGAPUR_CHINA_MAX_BYTES', (int) (3.5 * 1024 * 1024)),
    ],

    // MUA bot — Python microservice that automates the SE/MUA portal via Playwright.
    'mua_bot' => [
        'url' => env('MUA_BOT_URL', 'http://mua-bot:8000'),
        'api_key' => env('MUA_BOT_API_KEY'),
        'secret_key' => env('MUA_BOT_SECRET_KEY'),
    ],

    // SAT bot (nexum-citas-sat) — external service that schedules SAT appointments.
    // api_key secures the pending pull; secret_key signs the HMAC callback.
    'sat_bot' => [
        // Base URL of the nexum-citas-sat bot. Unlike the MUA bot (a container on the
        // same Docker network), this one runs on Cloud Run, so it is a public HTTPS URL.
        'url' => env('SAT_BOT_URL'),
        'api_key' => env('SAT_BOT_API_KEY'),
        'secret_key' => env('SAT_BOT_SECRET_KEY'),
    ],

    // Manual intake API — token to inspect/complete expedientes that arrived incomplete
    // from the relay. Only the team holds it. Generate with: openssl rand -hex 32.
    'intake' => [
        'token' => env('INTAKE_API_TOKEN'),
    ],

    // Anthropic Claude API — used by DocumentAnalysisService for KYC document vision extraction.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    // DocuSign — electronic signature for the partner_signature stage (acta constitutiva).
    // JWT auth: integration_key + user_id + RSA private key.
    // See: https://developers.docusign.com/platform/auth/jwt/
    'docusign' => [
        'integration_key' => env('DOCUSIGN_INTEGRATION_KEY'),
        'user_id' => env('DOCUSIGN_USER_ID'),
        'account_id' => env('DOCUSIGN_ACCOUNT_ID'),
        'rsa_private_key' => env('DOCUSIGN_PRIVATE_KEY'),
        'secret_hmac' => env('DOCUSIGN_SECRET_HMAC'),
        'base_url' => env('DOCUSIGN_AUTH_SERVER', 'account.docusign.com'),
        'return_url' => env('DOCUSIGN_RETURN_URL', env('APP_URL').'/admin'),
    ],

];
