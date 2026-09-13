<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | Service credentials. SSO providers are referenced by config/sso.php;
    | redirect URLs must match the /sso/{provider}/callback routes.
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

    // Built-in Socialite SSO providers. Custom OIDC issuers live in config/oidc.php.
    'github' => [
        'client_id' => env('SSO_GITHUB_ID'),
        'client_secret' => env('SSO_GITHUB_SECRET'),
        'redirect' => '/sso/github/callback',
    ],

    'google' => [
        'client_id' => env('SSO_GOOGLE_ID'),
        'client_secret' => env('SSO_GOOGLE_SECRET'),
        'redirect' => '/sso/google/callback',
    ],

    'discord' => [
        'client_id' => env('SSO_DISCORD_ID'),
        'client_secret' => env('SSO_DISCORD_SECRET'),
        'redirect' => '/sso/discord/callback',
    ],

];
