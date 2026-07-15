<?php

return [
    // Swap provider without touching any other code — set AI_PROVIDER in .env
    'provider' => env('AI_PROVIDER', 'openai'),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model'   => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'deepseek' => [
        'api_key'  => env('DEEPSEEK_API_KEY'),
        'model'    => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model'   => env('GEMINI_MODEL', 'gemini-1.5-flash'),
    ],

    // Internal FastAPI microservice URL
    'service_url' => env('AI_SERVICE_URL', 'http://localhost:8001'),
];
