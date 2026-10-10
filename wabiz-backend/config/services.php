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

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
        'base_url' => env('GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com/v1beta',
        'connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('GEMINI_TIMEOUT', 20),
    ],

    'whatsapp' => [
        'meta' => [
            'base_url' => env('WHATSAPP_META_BASE_URL') ?: 'https://graph.facebook.com',
            'graph_api_version' => env('WHATSAPP_META_GRAPH_API_VERSION', ''),
            'connect_timeout' => (int) env('WHATSAPP_META_CONNECT_TIMEOUT', 5),
            'timeout' => (int) env('WHATSAPP_META_TIMEOUT', 20),
        ],
    ],

    'wppconnect' => [
        'base_url' => env('WPPCONNECT_BASE_URL', 'http://127.0.0.1:21465'),
        'secret_key' => env('WPPCONNECT_SECRET_KEY'),
        'webhook_token' => env('WPPCONNECT_WEBHOOK_TOKEN'),
    ],

];
