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

    'bitbucket' => [
        'base_url' => env('BITBUCKET_BASE_URL', 'https://api.bitbucket.org/2.0'),
        'webhook_base_url' => env('BITBUCKET_WEBHOOK_BASE_URL', env('APP_URL').'/bb/webhook'),
    ],

    'github' => [
        'base_url' => env('GITHUB_BASE_URL', 'https://api.github.com'),
        'app_id' => env('GITHUB_APP_ID'),
        'app_slug' => env('GITHUB_APP_SLUG'),
        'app_private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'app_webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
    ],

];
