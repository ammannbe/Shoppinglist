<?php

namespace App\Http\Controllers;

use App\Auth\OidcAccount;
use App\Auth\OidcLabel;
use App\Households\AccountManagerLabel;
use App\Models\User;
use App\Services\UserDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Passkey;

/**
 * The account is managed in the app's settings ("Manage account", resources/views/partials/settings.blade.php,
 * resources/js/account.js); these are its server endpoints. They need a connection, the settings disable them offline.
 */
class AccountController extends Controller
{
    /**
     * The settings view of the app shell.
     */
    public const SETTINGS = '/#/settings';

    /**
     * A message for the settings after a redirect (OIDC callback), handed out once by /me.
     */
    public const NOTICE = 'account.notice';

    /**
     * The former account page: everything is in the settings now.
     */
    public function show(): RedirectResponse
    {
        return redirect(self::SETTINGS);
    }

    /**
     * What the settings show in "Manage account" (part of /me).
     *
     * @return array<string, mixed>
     */
    public static function state(User $user): array
    {
        $linked = $user->isLinkedToOidc();
        $oidc = (bool) config('login.oidc.enabled');
        $managerUrl = AccountManagerLabel::profileUrl();

        return [
            'has_password' => $user->password !== null,
            // OIDC login: link (OIDC enabled) or unlink (also shown, not offered, while it is disabled).
            'oidc' => $oidc || $linked ? [
                'enabled' => $oidc,
                'provider' => OidcLabel::get(),
                'signup_url' => config('login.oidc.signup_url'),
                // Unlinking and deleting a linked account need a recent OIDC login (OidcAccount::SIGN_IN_TIMEOUT).
                'confirmed' => $linked && app(OidcAccount::class)->hasRecentSignIn(),
            ] : null,
            // Linked accounts manage their profile (name, address, password) in the AccountManager.
            'manager' => $linked && $managerUrl !== null ? ['name' => AccountManagerLabel::get(), 'url' => $managerUrl] : null,
            'passkeys' => $linked ? 0 : $user->passkeys()->count(),
        ];
    }

    /**
     * The user's passkeys for "Passkeys" in the settings. Adding and removing them (Fortify's routes) needs a
     * recent password confirmation (423 otherwise), listing them does not.
     */
    public function passkeys(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_if($user->isLinkedToOidc(), 403);

        return response()->json($user->passkeys()->latest()->get()->map(fn (Passkey $passkey): array => [
            'id' => $passkey->id,
            'name' => $passkey->name,
            'used' => $passkey->last_used_at
                ? __('Last used :date', ['date' => $passkey->last_used_at->locale(app()->getLocale())->isoFormat('LL')])
                : __('Added :date', ['date' => $passkey->created_at?->locale(app()->getLocale())->isoFormat('LL')]),
        ])->all());
    }

    /**
     * Deletes the account (App\Services\UserDeletion, also used for user.deleted of the AccountManager).
     * Confirmed with the password, a linked account (no password) with an OIDC login within
     * OidcAccount::SIGN_IN_TIMEOUT (409 with the confirmation at the provider otherwise).
     */
    public function destroy(Request $request, OidcAccount $accounts, UserDeletion $deletion): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isLinkedToOidc()) {
            $request->validateWithBag('deleteAccount', [
                'password' => ['required', 'string', 'current_password:web'],
            ]);
        } elseif (! config('login.oidc.enabled')) {
            abort(409, __('Deleting the account needs a confirmation with :provider, which is turned off at the moment.', ['provider' => OidcLabel::get()]));
        } elseif (! $accounts->hasRecentSignIn()) {
            return response()->json([
                'message' => __('For your security, confirm with :provider first.', ['provider' => OidcLabel::get()]),
                'redirect' => route('oidc.redirect', ['confirm' => 1, 'for' => 'delete']),
            ], 409);
        }

        // Before deleting: logging out saves a new remember token, which would recreate the user.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $deletion->delete($user);

        // The app removes the local data of the account (?deleted=1).
        return $request->wantsJson() ? response()->json(['redirect' => '/?deleted=1']) : redirect('/?deleted=1');
    }
}
