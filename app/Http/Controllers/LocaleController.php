<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class LocaleController extends Controller
{
    /**
     * Store the chosen locale for the user (if logged in) and as cookie.
     */
    public function update(Request $request): Response
    {
        $locale = $request->validate([
            'locale' => ['required', Rule::in(array_keys(config('app.locales')))],
        ])['locale'];

        $request->user()?->forceFill(['locale' => $locale])->save();

        $response = $request->expectsJson() ? response()->noContent() : back();

        return $response->withCookie(cookie()->forever(SetLocale::COOKIE, $locale));
    }
}
