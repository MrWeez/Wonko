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

    /*
    |--------------------------------------------------------------------------
    | SSO / Socialite providers
    |--------------------------------------------------------------------------
    |
    | Credentials for the SSO providers referenced by config/sso.php. Redirect
    | URLs must match the callback routes: /sso/{provider}/callback.
    |
    | Custom OIDC issuers (Keycloak, Authentik, ...) are NOT configured here —
    | they live in config/oidc.php and are registered as drivers named
    | "oidc_<connection>".
    |
    */

    'github' => [
        'client_id' => env('SSO_GITHUB_CLIENT_ID'),
        'client_secret' => env('SSO_GITHUB_CLIENT_SECRET'),
        'redirect' => '/sso/github/callback',
    ],

    'google' => [
        'client_id' => env('SSO_GOOGLE_CLIENT_ID'),
        'client_secret' => env('SSO_GOOGLE_CLIENT_SECRET'),
        'redirect' => '/sso/google/callback',
    ],

    'discord' => [
        'client_id' => env('SSO_DISCORD_CLIENT_ID'),
        'client_secret' => env('SSO_DISCORD_CLIENT_SECRET'),
        'redirect' => '/sso/discord/callback',
    ],

];
