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

    // AI assistant (offers, pantry, recipe suggestions). One key for all users for now;
    // without a key the feature is hidden. See app/Ai.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-6-luna'),
        // Only for reasoning models (gpt-5, o-series); empty to omit the parameter.
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 150),
    ],

    // Recipe app connected with OAuth ("white label": name and URL from the environment).
    // Without URL and client the connection is hidden. See app/Recipes.
    'recipes' => [
        'name' => env('RECIPES_NAME', 'RecipeManager'),
        'url' => rtrim((string) env('RECIPES_URL'), '/'),
        'client_id' => env('RECIPES_CLIENT_ID'),
        'client_secret' => env('RECIPES_CLIENT_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
