<?php

namespace App\Http\Controllers\Auth;

use App\Auth\OidcLogin;
use App\Auth\OidcLoginException;
use App\Households\KontoHouseholds;
use App\Households\KontoUnavailable;
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

class OidcController extends Controller
{
    public function redirect(Request $request): SymfonyRedirectResponse
    {
        abort_unless(config('login.oidc.enabled'), 404);

        // "Link" from the account page: the callback attaches the identity to the logged in user.
        $request->session()->put('oidc.link', $request->boolean('link') && Auth::check());

        return $this->provider()->redirect();
    }

    public function callback(Request $request, OidcLogin $login, KontoHouseholds $households): RedirectResponse
    {
        abort_unless(config('login.oidc.enabled'), 404);

        $linking = (bool) $request->session()->pull('oidc.link', false) && Auth::check();

        try {
            $identity = $this->provider()->user();

            if (! $identity instanceof AbstractUser) {
                throw new UnexpectedValueException('Unexpected OIDC user.');
            }

            /** @var User|null $current */
            $current = $linking ? Auth::user() : null;

            $user = $login->resolve($identity, $current);
        } catch (OidcLoginException $exception) {
            return $this->failed(__($exception->getMessage()), $linking);
        } catch (Throwable $exception) {
            Log::warning('OIDC login failed', ['exception' => $exception->getMessage()]);

            return $this->failed(__('The login failed. Please try again.'), $linking);
        }

        $this->syncHouseholds($households, $user);

        if ($linking) {
            return redirect()->route('account')->with('status', __('The login is linked to your account.'));
        }

        // Stay logged in like the password login: the app has to sync after long offline periods.
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    public function destroy(Request $request, KontoHouseholds $households): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Without a password the account would no longer be reachable.
        abort_if($user->password === null, 403);

        // Mirrored households are matched by the login.
        if (KontoHouseholds::enabled()) {
            $households->detachUser($user);
        }

        $user->forceFill(['oidc_sub' => null])->save();

        return redirect()->route('account')->with('status', __('The login is unlinked.'));
    }

    /**
     * HOUSEHOLDS_DRIVER=konto: pending memberships and the user's households from the AccountManager.
     * The login works without it (the daily sync catches up).
     */
    private function syncHouseholds(KontoHouseholds $households, User $user): void
    {
        if (! KontoHouseholds::enabled()) {
            return;
        }

        try {
            $households->syncUser($user);
        } catch (KontoUnavailable $exception) {
            KontoHouseholds::logUnavailable($exception, 'syncing the households on login');
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

    private function failed(string $message, bool $linking): RedirectResponse
    {
        return redirect()->route($linking ? 'account' : 'login')->withErrors(['oidc' => $message]);
    }
}
