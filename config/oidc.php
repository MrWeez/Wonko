<?php

return [

    // Driver prefix: connection 'generic' → Socialite::driver('oidc_generic').
    'driver_prefix' => 'oidc_',

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | One entry per OIDC issuer. 'base_url' + /.well-known/openid-configuration
    | is used for discovery. Check the openidconnect package README for all
    | supported keys (provider, scopes, verify_jwt, ...) and the built-in
    | provider shorthands (keycloak, authentik, ...). The redirect URL must
    | match the /sso/{provider}/callback route used in config/sso.php.
    |
    */

    'connections' => [
        'generic' => [
            'base_url' => env('SSO_GENERIC_URL'),
            'client_id' => env('SSO_GENERIC_ID'),
            'client_secret' => env('SSO_GENERIC_SECRET'),
            'redirect' => '/sso/generic/callback',
            'scopes' => array_values(array_map(
                'trim',
                array_filter(explode(',', (string) env('SSO_GENERIC_SCOPES', 'openid,email,profile'))),
            )),
        ],
    ],

];
