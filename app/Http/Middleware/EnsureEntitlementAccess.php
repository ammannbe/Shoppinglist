<?php

namespace App\Http\Middleware;

use App\Entitlements\Entitlements;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ENTITLEMENTS_DRIVER=account-manager: a linked user whose access is suspended, requested or removed in
 * the AccountManager is blocked. Page requests sign out and show the message on the login page; JSON
 * requests (the app shell: /me, sync, planner …) get 403 with the message and `access` (suspended or
 * disabled) and keep the session, so the app can show the reason. Users without a linked sign-in
 * (emergency addresses) are never blocked.
 */
class EnsureEntitlementAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || Entitlements::allowsAccess($user)) {
            return $next($request);
        }

        $message = Entitlements::deniedMessage($user);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'access' => Entitlements::deniedReason($user)], 403);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['access' => $message]);
    }
}
