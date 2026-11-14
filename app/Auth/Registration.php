<?php

namespace App\Auth;

/**
 * Where new users create an account. AUTH_LOCAL_REGISTRATION=false (config/login.php): not in this app
 * but at the OpenID Connect provider (AUTH_OIDC_SIGNUP_URL, e.g. the AccountManager's /register),
 * whose first login here creates the account (AUTH_OIDC_REGISTRATION).
 */
class Registration
{
    public static function local(): bool
    {
        return (bool) config('login.local.registration', true);
    }

    /**
     * Target of the "Register" links, null: no link (local registration off and no signup URL).
     */
    public static function url(): ?string
    {
        if (self::local()) {
            return route('register', absolute: false);
        }

        return config('login.oidc.enabled') ? config('login.oidc.signup_url') : null;
    }
}
