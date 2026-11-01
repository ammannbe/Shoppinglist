<?php

namespace App\Providers;

use App\Auth\OidcProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('oidc', OidcProvider::class);
        });

        // The AI requests use one key for all users: limit them per user.
        RateLimiter::for('ai', fn (Request $request) => Limit::perHour(60)->by((string) $request->user()?->id));
    }
}
