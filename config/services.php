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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'api_base_uri' => env('TELEGRAM_API_BASE_URI', 'https://api.telegram.org'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // Check https://ai.google.dev/gemini-api/docs/models for the current
        // cheapest model — pricing/lineup changes often, this default is not
        // guaranteed to still be current or cheapest.
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
        'api_base_uri' => env('GEMINI_API_BASE_URI', 'https://generativelanguage.googleapis.com'),
    ],

];
