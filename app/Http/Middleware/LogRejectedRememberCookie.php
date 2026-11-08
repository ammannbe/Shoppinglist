<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Recaller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary diagnostics: logs why a request that carries a remember-me cookie is still a guest
 * (users were signed out after a while although the cookie should sign them in again).
 * Remove together with the "remember_token changed" log in User::booted().
 */
class LogRejectedRememberCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if ($guard instanceof SessionGuard) {
            $cookie = $request->cookies->get($guard->getRecallerName());

            // Once per cookie and 10 minutes: the rejected cookie stays until the next login.
            if (is_string($cookie) && $cookie !== '' && $guard->guest()
                && Cache::add('remember-cookie-rejected:'.hash('sha256', $cookie), true, now()->addMinutes(10))) {
                Log::warning('Remember cookie rejected', [
                    'reason' => $this->reason(new Recaller($cookie)),
                    ...$this->context($request),
                ]);
            }
        }

        return $next($request);
    }

    private function reason(Recaller $recaller): string
    {
        if (! $recaller->valid()) {
            return 'invalid_cookie';
        }

        $user = User::query()->find($recaller->id());

        if ($user === null) {
            return 'user_not_found';
        }

        $stored = $user->getRememberToken();

        if (! $stored) {
            return 'stored_token_empty';
        }

        return hash_equals($stored, $recaller->token()) ? 'token_matches_but_guest' : 'token_mismatch';
    }

    /**
     * @return array<string, mixed>
     */
    public static function context(?Request $request = null): array
    {
        if ($request === null && app()->runningInConsole()) {
            return ['console' => implode(' ', (array) ($_SERVER['argv'] ?? []))];
        }

        $request ??= request();

        return [
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'has_session_cookie' => $request->cookies->has(config('session.cookie')),
        ];
    }
}
