<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local login
    |--------------------------------------------------------------------------
    |
    | E-mail/password and passkey login of the app itself. When disabled, only
    | the addresses in "emergency_emails" may still sign in with a password
    | (e.g. while the OpenID Connect provider is down). Registration follows
    | AUTH_LOCAL_REGISTRATION (config/fortify.php). Password reset stays
    | available, so that unlinked users without password can set one.
    | Accounts linked to the OpenID Connect login never log in with a
    | password or passkey (App\Auth\OidcAccount), so the emergency
    | addresses stay unlinked.
    |
    */

    'local' => [
        'enabled' => (bool) env('AUTH_LOCAL_ENABLED', true),
        'emergency_emails' => array_values(array_filter(array_map(
            fn (string $email): string => mb_strtolower(trim($email)),
            explode(',', (string) env('AUTH_LOCAL_EMERGENCY_EMAILS', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenID Connect login
    |--------------------------------------------------------------------------
    |
    | Optional "Log in with …" through any OpenID Connect provider (e.g.
    | Pocket ID). The provider connection itself is configured in
    | config/services.php ("oidc").
    |
    */

    'oidc' => [
        'enabled' => (bool) env('AUTH_OIDC_ENABLED', false),
        // Name on the buttons; empty: the APP_NAME of a Pocket ID provider, otherwise "SSO" (App\Auth\OidcLabel).
        'label' => env('AUTH_OIDC_LABEL') ?: null,

        // Optional: where users create an account at the provider (e.g. the AccountManager's /register).
        'signup_url' => env('AUTH_OIDC_SIGNUP_URL') ?: null,

        // Create a new account on the first OIDC login of an unknown user.
        'registration' => (bool) env('AUTH_OIDC_REGISTRATION', true),

        // Only users with this value in the "groups" claim may log in.
        'required_group' => env('AUTH_OIDC_REQUIRED_GROUP') ?: null,
    ],

];
