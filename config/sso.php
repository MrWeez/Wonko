<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SSO (Single Sign-On)
    |--------------------------------------------------------------------------
    |
    | Wonko's SSO lets users register and log in through an external OAuth/OIDC
    | provider in one click. It is built on Laravel Socialite and is useful on
    | deployments where self-service password registration is closed off
    | (REGISTRATION_ENABLED=false); SSO keeps onboarding working through the
    | identity provider instead.
    |
    | HOW IT WORKS
    | ------------
    |
    | 1. Enable SSO:                   SSO_ENABLED=true
    |
    | 2. Configure at least one provider below. A provider is shown on the
    |    login/register pages ONLY when it has credentials configured, so the
    |    list stays short — enable exactly the buttons you want.
    |
    |    Popular providers (GitHub, Google, GitLab, Bitbucket, LinkedIn, Slack,
    |    Twitch, X, Facebook) are built into Socialite. Credentials live in
    |    config/services.php (all .env-driven):
    |
    |        'github' => [
    |            'client_id' => env('SSO_GITHUB_CLIENT_ID'),
    |            'client_secret' => env('SSO_GITHUB_CLIENT_SECRET'),
    |            'redirect' => '/sso/github/callback',
    |        ],
    |
    |    Discord needs the community driver (installed with composer):
    |    composer require socialiteproviders/discord   -> SSO_DISCORD_*
    |
    |    A custom OIDC issuer (Keycloak, Authentik, ...) is configured in
    |    config/oidc.php (the openidconnect package). Each connection becomes
    |    a Socialite driver named "oidc_<connection>". Reference it here:
    |
    |        'keycloak' => ['driver' => 'oidc_keycloak', 'name' => 'Keycloak'],
    |        'authentik' => ['driver' => 'oidc_authentik', 'name' => 'Authentik'],
    |
    |    The connection's redirect URL must point at the callback route:
    |    /sso/keycloak/callback, /sso/authentik/callback, ...
    |
    | 3. Done. Buttons appear on the login and registration pages; the callback
    |    matches an existing user by email or creates a new verified one.
    |
    | SECURITY NOTES
    | --------------
    |
    | - SSO accounts are considered email-verified: new users are created with
    |   email_verified_at set. The provider, not Wonko, verified the address.
    | - Reusing an SSO login for an email that already has a local password
    |   account simply logs that user in (the SSO identity is authoritative).
    | - While SSO is disabled, nothing here renders and /sso/* returns 404.
    |
    */

    'enabled' => env('SSO_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Provider registry
    |--------------------------------------------------------------------------
    |
    | Each entry maps a route/display key (used in /sso/{key}) to a Socialite
    | driver and a human-readable name. Only providers whose credentials exist
    | are rendered.
    |
    */

    'providers' => [
        'github' => ['driver' => 'github', 'name' => 'GitHub'],
        'google' => ['driver' => 'google', 'name' => 'Google'],
        'discord' => ['driver' => 'discord', 'name' => 'Discord'],

        // Custom OIDC example (Keycloak):
        // 'keycloak' => ['driver' => 'oidc_keycloak', 'name' => 'Keycloak'],
        //
        // Authentik example:
        // 'authentik' => ['driver' => 'oidc_authentik', 'name' => 'Authentik'],
    ],

];
