<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale priority: user setting, locale cookie (guests), browser language.
 */
class SetLocale
{
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::resolve($request));

        return $next($request);
    }

    public static function resolve(Request $request): string
    {
        $supported = array_keys(config('app.locales'));

        foreach ([$request->user()?->locale, $request->cookie(self::COOKIE)] as $locale) {
            if (in_array($locale, $supported, true)) {
                return $locale;
            }
        }

        // Accept-Language "de", "de-DE" etc. map to the Swiss German translation.
        $language = substr((string) $request->getPreferredLanguage(), 0, 2);

        return collect($supported)->first(fn ($locale) => str_starts_with($locale, $language), config('app.locale'));
    }
}
