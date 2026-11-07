<?php

namespace App\Http\Middleware;

use App\Auth\OidcAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH_LOCAL_ENABLED=false: only the OpenID Connect login is offered. Password logins stay
 * possible for the emergency addresses (config/login.php), passkey logins are off.
 */
class RestrictLocalLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('login.local.enabled')) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        if (in_array($route, ['passkey.login-options', 'passkey.login'], true)) {
            abort(404);
        }

        if ($request->isMethod('post') && $route === 'login.store') {
            $email = mb_strtolower(trim((string) $request->input('email')));

            if (! in_array($email, config('login.local.emergency_emails'), true)) {
                // Linked accounts are told to use the provider, like with the local login enabled (RefuseLinkedAccounts).
                throw ValidationException::withMessages(['email' => OidcAccount::isLinkedEmail($email) ? OidcAccount::message() : __('auth.failed')]);
            }
        }

        return $next($request);
    }
}
