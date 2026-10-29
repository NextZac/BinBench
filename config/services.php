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

    /*
    | Any OpenAI-compatible chat completions API, used to explain Stage 1 proposals.
    | DeepSeek: https://api.deepseek.com (model deepseek-chat)
    | GitHub Models: https://models.github.ai/inference (model e.g. openai/gpt-4.1-mini, token = GitHub PAT)
    | OpenRouter: https://openrouter.ai/api/v1 · Ollama: http://localhost:11434/v1 (no key)
    */
    'tuning_ai' => [
        'base_url' => env('TUNING_AI_BASE_URL'),
        'api_key' => env('TUNING_AI_API_KEY'),
        'model' => env('TUNING_AI_MODEL'),
        'timeout' => env('TUNING_AI_TIMEOUT', 60),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
