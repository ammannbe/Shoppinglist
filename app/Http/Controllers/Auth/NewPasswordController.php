<?php

namespace App\Http\Controllers\Auth;

use App\Auth\OidcAccount;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\NewPasswordController as FortifyNewPasswordController;

/**
 * A linked account never gets a password through a reset link, also not through a link that
 * was sent before the account was linked (linking deletes the tokens, this is the safety net):
 * a valid link is refused with OidcAccount::message() and its token deleted. Without a valid
 * token the usual "invalid token" answer stays, so the form does not tell whether an address
 * is linked. Bound in place of Fortify's controller (FortifyServiceProvider).
 */
class NewPasswordController extends FortifyNewPasswordController
{
    public function store(Request $request): Responsable
    {
        $email = mb_strtolower(trim((string) $request->input(Fortify::email())));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->whereNotNull('oidc_sub')->first();
        /** @var PasswordBroker $broker */
        $broker = Password::broker(config('fortify.passwords'));

        if ($user && $broker->tokenExists($user, (string) $request->input('token'))) {
            $broker->deleteToken($user);

            throw ValidationException::withMessages([Fortify::email() => OidcAccount::message()]);
        }

        return parent::store($request);
    }
}
