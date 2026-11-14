<?php

namespace App\Http\Middleware;

use App\Auth\Registration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH_LOCAL_REGISTRATION=false: Fortify's registration is closed. The page redirects to the provider's
 * signup (AUTH_OIDC_SIGNUP_URL) or the login page, registering is refused (403).
 */
class RestrictLocalRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Registration::local()) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        if ($route === 'register.store') {
            abort(403, __('Registering is not possible here.'));
        }

        if ($route === 'register') {
            return redirect()->away(Registration::url() ?? route('login'));
        }

        return $next($request);
    }
}
