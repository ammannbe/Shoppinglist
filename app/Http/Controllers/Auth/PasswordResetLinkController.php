<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Notifications\SignInWithOidcNotice;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest;

/**
 * A linked account gets no reset link (it has no password and must not get one), but a mail
 * that points to the OIDC provider. The page answers the same as for any other account
 * (also "wait a moment" within the broker's throttle), so it does not tell whether the
 * account is linked. Bound in place of Fortify's controller (FortifyServiceProvider).
 */
class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    /**
     * One notice per account in this time.
     */
    public const NOTICE_DECAY_SECONDS = 15 * 60;

    public function store(SendPasswordResetLinkRequest $request): Responsable
    {
        $email = mb_strtolower(trim((string) $request->input(Fortify::email())));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->whereNotNull('oidc_sub')->first();

        if (! $user) {
            return parent::store($request);
        }

        $key = 'oidc-reset-notice:'.$user->getKey();

        // Like the broker's own throttle for reset links.
        if (RateLimiter::tooManyAttempts($key, 1)
            && self::NOTICE_DECAY_SECONDS - RateLimiter::availableIn($key) < (int) config('auth.passwords.'.config('fortify.passwords').'.throttle', 60)) {
            return app(FailedPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_THROTTLED]);
        }

        RateLimiter::attempt($key, 1, fn () => $user->notify(new SignInWithOidcNotice), self::NOTICE_DECAY_SECONDS);

        return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT]);
    }
}
