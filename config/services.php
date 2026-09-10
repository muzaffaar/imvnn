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

        // Channel TelegramChannelSeeder registers, so a deploy can be
        // provisioned non-interactively. Read through config (not env()
        // directly) because env() returns null once config is cached.
        'channel' => [
            'chat_id' => env('TELEGRAM_CHANNEL_CHAT_ID'),
            'name' => env('TELEGRAM_CHANNEL_NAME'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Text-generation provider
    |--------------------------------------------------------------------------
    |
    | `gemini` uses Google's native generateContent API. `openai-compatible`
    | uses the Chat Completions request shape supported by OpenAI and many
    | hosted or self-hosted GPU servers (for example vLLM and LM Studio).
    |
    | The GEMINI_* fallbacks keep an existing deployment working until its
    | environment variables are deliberately migrated to AI_* names.
    */
    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'),
        'api_key' => env('AI_API_KEY', env('GEMINI_API_KEY')),
        'model' => env('AI_MODEL', env('GEMINI_MODEL', 'gemini-2.5-flash-lite')),
        'base_uri' => env('AI_BASE_URI', env('GEMINI_API_BASE_URI', 'https://generativelanguage.googleapis.com')),

        'openai_compatible' => [
            // Relative to AI_BASE_URI. Include v1/ here when the server needs it.
            'path' => env('AI_OPENAI_PATH', 'chat/completions'),
            // Use json_object or none for servers that do not implement json_schema.
            'structured_output' => env('AI_OPENAI_STRUCTURED_OUTPUT', 'json_schema'),
            // Older compatible servers commonly accept max_tokens only.
            'max_tokens_field' => env('AI_OPENAI_MAX_TOKENS_FIELD', 'max_tokens'),
        ],
    ],

];
