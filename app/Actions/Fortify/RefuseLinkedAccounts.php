<?php

namespace App\Actions\Fortify;

use App\Auth\OidcAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

/**
 * Login pipeline step before the password check: accounts linked to the OIDC login never log in
 * with a password (they have none; this also covers a password left over by mistake) and are
 * told to use the provider. This only adds "linked or not" for an address whose account exists:
 * registration and password reset already tell that it exists, unknown addresses and wrong
 * passwords keep the usual message, and the login stays rate limited like before.
 */
class RefuseLinkedAccounts
{
    public function __construct(private readonly LoginRateLimiter $limiter) {}

    public function handle(Request $request, callable $next): mixed
    {
        if (OidcAccount::isLinkedEmail($request->input(Fortify::username()))) {
            $this->limiter->increment($request);

            throw ValidationException::withMessages([
                Fortify::username() => OidcAccount::message(),
            ]);
        }

        return $next($request);
    }
}
