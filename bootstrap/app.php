<?php

use App\Http\Middleware\EnsureEntitlementAccess;
use App\Http\Middleware\LogRejectedRememberCookie;
use App\Http\Middleware\RestrictLocalLogin;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // EnsureEntitlementAccess: ENTITLEMENTS_DRIVER=account-manager blocks users whose access was blocked in the AccountManager.
        // LogRejectedRememberCookie: temporary diagnostics for remember cookies that do not sign in.
        $middleware->web(append: [LogRejectedRememberCookie::class, SetLocale::class, RestrictLocalLogin::class, EnsureEntitlementAccess::class]);
        // Before the auth middleware, which would answer guests first.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: LogRejectedRememberCookie::class);
        // Changing or resetting the password ends the other sessions.
        $middleware->authenticateSessions();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
