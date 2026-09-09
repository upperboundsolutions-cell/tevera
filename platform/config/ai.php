<?php

return [
    'enabled' => env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'openai'),
    'key' => env('AI_API_KEY') ?: (env('AI_PROVIDER', 'openai') === 'openai' ? env('OPENAI_API_KEY') : null),
    'model' => env('AI_MODEL') ?: (env('AI_PROVIDER', 'openai') === 'openai' ? env('OPENAI_MODEL') : null),
    'base_url' => env('AI_BASE_URL'),
];
