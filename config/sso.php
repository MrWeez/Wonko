<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SSO (Single Sign-On)
    |--------------------------------------------------------------------------
    |
    | Enable SSO_ENABLED=true. Each provider below renders a login button only
    | when its credentials are configured (config/services.php or
    | config/oidc.php). New users are created email-verified from the
    | provider's identity; existing accounts are matched by email.
    |
    */

    'enabled' => env('SSO_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Provider registry
    |--------------------------------------------------------------------------
    |
    | Key = /sso/{key} route segment. 'driver' is the Socialite driver;
    | 'name' is the login button label. Custom OIDC connections are registered
    | as drivers named "oidc_<connection>".
    |
    */

    'providers' => [
        'github' => ['driver' => 'github', 'name' => 'GitHub'],
        'google' => ['driver' => 'google', 'name' => 'Google'],
        'discord' => ['driver' => 'discord', 'name' => 'Discord'],
        'generic' => ['driver' => 'oidc_generic', 'name' => env('SSO_GENERIC_NAME', 'Generic OIDC')],
    ],

];
