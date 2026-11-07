<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Auth\OidcAccount;
use App\Auth\OidcLogin;
use App\Auth\OidcLoginException;
use App\Entitlements\AccountManagerEntitlements;
use App\Entitlements\Entitlements;
use App\Households\AccountManagerHouseholds;
use App\Households\AccountManagerUnavailable;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;
use UnexpectedValueException;

/**
 * OIDC login, linking from the account page and the confirmation before unlinking or deleting
 * a linked account (App\Auth\OidcAccount).
 */
class OidcController extends Controller
{
    use PasswordValidationRules;

    private const INTENT = 'oidc.intent';

    /**
     * What the confirmation is for: the account page opens at that section afterwards.
     */
    private const CONFIRM_FOR = 'oidc.confirm_for';

    /**
     * "?link=1" links the logged in user (account page, after confirming the password), "?confirm=1"
     * confirms a linked user's login before unlinking ("&for=delete": deleting the account);
     * otherwise a normal login.
     */
    public function redirect(Request $request, OidcAccount $accounts): SymfonyRedirectResponse
    {
        abort_unless(config('login.oidc.enabled'), 404);

        $request->session()->forget([self::INTENT, self::CONFIRM_FOR]);

        /** @var User|null $user */
        $user = Auth::user();
        $intent = 'login';

        if ($user && $request->boolean('link')) {
            // Linking removes the password and the passkeys, so a session alone (e.g. an unlocked device) is not enough.
            if (! $accounts->pullPasswordConfirmation($user)) {
                return $this->failed(__('Please confirm your password first.'), 'link');
            }

            $intent = 'link';
        } elseif ($user && $request->boolean('confirm')) {
            $intent = 'confirm';
            $request->session()->put(self::CONFIRM_FOR, $request->query('for') === 'delete' ? 'delete' : 'unlink');
        }

        $request->session()->put(self::INTENT, $intent);

        return $this->provider()->redirect();
    }

    public function callback(Request $request, OidcLogin $login, OidcAccount $accounts, AccountManagerHouseholds $households, AccountManagerEntitlements $entitlements): RedirectResponse
    {
        abort_unless(config('login.oidc.enabled'), 404);

        /** @var User|null $current */
        $current = Auth::user();
        $intent = (string) $request->session()->pull(self::INTENT, 'login');
        $intent = $current ? $intent : 'login';
        $confirmFor = $request->session()->pull(self::CONFIRM_FOR);

        try {
            $identity = $this->provider()->user();

            if (! $identity instanceof AbstractUser) {
                throw new UnexpectedValueException('Unexpected OIDC user.');
            }

            if ($intent === 'confirm' && $current) {
                $login->confirm($identity, $current);
                $accounts->rememberSignIn();

                // Opens the account page at the unlink (or delete) form.
                return redirect()->to(route('account').($confirmFor === 'delete' ? '#delete-account' : '#oidc'));
            }

            $user = $login->resolve($identity, $intent === 'link' ? $current : null);
        } catch (OidcLoginException $exception) {
            return $this->failed(__($exception->getMessage()), $intent);
        } catch (Throwable $exception) {
            Log::warning('OIDC login failed', ['exception' => $exception->getMessage()]);

            return $this->failed(__('The login failed. Please try again.'), $intent);
        }

        $this->syncHouseholds($households, $user);

        // ENTITLEMENTS_DRIVER / AUTH_ADMIN_SOURCE=account-manager: the current snapshot (failures keep the last one).
        $entitlements->syncOnSignIn($user);

        if (! Entitlements::allowsAccess($user)) {
            if ($current) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return $this->failed(Entitlements::deniedMessage($user), 'login');
        }

        // Stay logged in like the password login: the app has to sync after long offline periods.
        // Also after linking: the remember token was rotated, this device gets the new one.
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $accounts->rememberSignIn();

        if ($intent === 'link') {
            return redirect()->route('account')->with('status', __('The login is linked to your account.'));
        }

        return redirect()->intended(config('fortify.home'));
    }

    /**
     * "Link …" on the account page: the current password first (accounts without one skip it),
     * then the provider (redirect "?link=1", within OidcAccount::PASSWORD_TIMEOUT, used once).
     */
    public function link(Request $request, OidcAccount $accounts): RedirectResponse
    {
        abort_unless(config('login.oidc.enabled'), 404);

        /** @var User $user */
        $user = $request->user();

        abort_if($user->isLinkedToOidc(), 403);

        if ($user->password !== null) {
            $request->validateWithBag('linkOidc', [
                'password' => ['required', 'string', 'current_password:web'],
            ]);
        }

        $accounts->rememberPasswordConfirmation();

        return redirect()->route('oidc.redirect', ['link' => 1]);
    }

    /**
     * Unlinking needs an OIDC login within OidcAccount::SIGN_IN_TIMEOUT (otherwise the
     * confirmation at the provider first) and a new password. Without the OIDC login there is
     * nothing to confirm with; users:unlink-oidc is the way back then.
     */
    public function destroy(Request $request, OidcAccount $accounts): RedirectResponse
    {
        abort_unless(config('login.oidc.enabled'), 404);

        /** @var User $user */
        $user = $request->user();

        abort_unless($user->isLinkedToOidc(), 403);

        if (! $accounts->hasRecentSignIn()) {
            return redirect()->route('oidc.redirect', ['confirm' => 1]);
        }

        $input = $request->validateWithBag('unlinkOidc', [
            'password' => $this->passwordRules(),
        ]);

        $accounts->unlink($user, $input['password']);

        return redirect()->route('account')->with('status', __('The sign-in is unlinked. You now sign in with your e-mail address and password.'));
    }

    /**
     * HOUSEHOLDS_DRIVER=account-manager: pending memberships and the user's households from the AccountManager.
     * The login works without it (the daily sync catches up).
     */
    private function syncHouseholds(AccountManagerHouseholds $households, User $user): void
    {
        if (! AccountManagerHouseholds::enabled()) {
            return;
        }

        try {
            $households->syncUser($user);
        } catch (AccountManagerUnavailable $exception) {
            AccountManagerHouseholds::logUnavailable($exception, 'syncing the households on login');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function provider(): AbstractProvider
    {
        /** @var AbstractProvider $provider */
        $provider = Socialite::driver('oidc');

        $provider->redirectUrl(route('oidc.callback'));

        if (config('login.oidc.required_group')) {
            $provider->scopes(['groups']);
        }

        return $provider;
    }

    private function failed(string $message, string $intent): RedirectResponse
    {
        return redirect()->route($intent === 'login' ? 'login' : 'account')->withErrors(['oidc' => $message]);
    }
}
