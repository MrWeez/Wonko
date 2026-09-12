<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SSO (Single Sign-On)
    |--------------------------------------------------------------------------
    |
    | Wonko's SSO support is a config-driven extension point for plugging in
    | social / OIDC / SAML providers, typically used on deployments where
    | self-service registration is closed off (REGISTRATION_ENABLED=false). It is
    | built on Laravel Socialite.
    |
    | CONTRACT — how to add a provider:
    |
    |   1. Install the Socialite driver for the provider. Discord and other
    |      third-party drivers ship as community packages, e.g.:
    |      `composer require socialiteproviders/discord`, then register the
    |      driver with Socialite in a service provider as documented by the
    |      driver's README. OIDC servers (Keycloak, Authentik, ...) use the
    |      SocialiteProviders/OpenIDConnect driver.
    |
    |   2. Register the provider's OAuth credentials in config/services.php
    |      using the provider name as the array key:
    |          'discord' => [
    |              'client_id' => env('DISCORD_CLIENT_ID'),
    |              'client_secret' => env('DISCORD_CLIENT_SECRET'),
    |              'redirect' => '/sso/discord/callback',
    |          ],
    |
    |   3. Expose redirect/callback routes (e.g. /sso/{provider} and
    |      /sso/{provider}/callback) that call Socialite::driver($provider)
    |      and, on the callback, either log in an existing user (matched by
    |      email) or create a new verified one. Keep callbacks idempotent.
    |
    |   4. Add the provider name to the 'providers' list below so the UI can
    |      render a login button for it. This list is purely declarative;
    |      nothing is active until the driver AND routes exist.
    |
    | Nothing below is enabled until a provider is both listed here and fully
    | wired up. Keep the list empty unless a provider is actually shipped.
    |
    */

    'enabled' => env('SSO_ENABLED', false),

    'providers' => [
        // 'discord' => ['name' => 'Discord'],
        // 'github' => ['name' => 'GitHub'],
        // 'keycloak' => ['name' => 'Keycloak'],
    ],

];
