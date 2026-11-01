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

    'doku' => [
        'client_id' => env('DOKU_CLIENT_ID'),
        'secret_key' => env('DOKU_SECRET_KEY'),
        'base_url' => env('DOKU_BASE_URL', 'https://api-sandbox.doku.com'),
        // Path Notification URL yang terdaftar di dashboard DOKU (tanpa host).
        'notification_path' => env('DOKU_NOTIFICATION_PATH', '/api/v1/webhooks/doku'),
        'payment_due_minutes' => (int) env('DOKU_PAYMENT_DUE_MINUTES', 30),
    ],

    'biteship' => [
        'api_key' => env('BITESHIP_API_KEY'),
        'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com'),
        // Daftar kurir aktif di akun Biteship (ops final).
        // UNVERIFIED: nilai default belum dikonfirmasi vs docs Biteship (docs tak terjangkau).
        'couriers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BITESHIP_COURIERS', 'jne,jnt,sicepat,anteraja,gojek,grab,sentral')),
        ))),
    ],

];
