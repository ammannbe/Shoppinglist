<?php

namespace App\Auth;

use App\Households\AccountManagerHouseholds;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Linking and unlinking the OpenID Connect login of an account.
 *
 * A linked account (users.oidc_sub) logs in through the provider only: linking removes the
 * password, the passkeys, the remember-me token, pending password resets and the other
 * sessions, and the local login, the password reset and the account page refuse to give it a
 * password or passkey again (the User model guards it). Unlinking needs a recent OIDC login
 * and a new password. Same rules as RecipeManager and Chorelist.
 */
class OidcAccount
{
    /**
     * Unlinking and deleting a linked account need an OIDC login (or confirmation) within this many seconds.
     */
    public const SIGN_IN_TIMEOUT = 15 * 60;

    /**
     * Linking from the account page needs the password confirmed within this many seconds.
     */
    public const PASSWORD_TIMEOUT = 5 * 60;

    private const SIGNED_IN_AT = 'oidc.signed_in_at';

    private const PASSWORD_CONFIRMED_AT = 'oidc.password_confirmed_at';

    /**
     * Shown wherever a linked account tries a local login (password, passkey) or a password reset.
     */
    public static function message(): string
    {
        return __('This account is connected to :provider. Sign in with :provider.', ['provider' => OidcLabel::get()]);
    }

    /**
     * The existing account with this address is linked (local login and password reset refuse it).
     */
    public static function isLinkedEmail(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && User::query()->whereRaw('LOWER(email) = ?', [$email])->whereNotNull('oidc_sub')->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes  saved together with the link (e.g. the verification)
     */
    public function link(User $user, string $sub, array $attributes = []): void
    {
        DB::transaction(function () use ($user, $sub, $attributes): void {
            $user->passkeys()->delete();

            $user->forceFill([
                ...$attributes,
                'oidc_sub' => $sub,
                'password' => null,
                // Remember-me cookies of every device stop working.
                'remember_token' => Str::random(60),
            ])->save();

            Password::broker(config('fortify.passwords'))->deleteToken($user);
        });

        $this->endOtherSessions($user);
    }

    /**
     * $password is the new plain password (hashed by the model cast); null only for the
     * users:unlink-oidc command, those users set one with "Forgot your password?".
     */
    public function unlink(User $user, #[SensitiveParameter] ?string $password): void
    {
        // Mirrored households are matched by the login (HOUSEHOLDS_DRIVER=account-manager).
        if (AccountManagerHouseholds::enabled()) {
            app(AccountManagerHouseholds::class)->detachUser($user);
        }

        $user->forceFill([
            'oidc_sub' => null,
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        $this->endOtherSessions($user);

        session()->forget(self::SIGNED_IN_AT);
    }

    /**
     * Called after every successful OIDC login, link or confirmation.
     */
    public function rememberSignIn(): void
    {
        session()->put(self::SIGNED_IN_AT, now()->getTimestamp());
    }

    public function hasRecentSignIn(): bool
    {
        return $this->isRecent(self::SIGNED_IN_AT, self::SIGN_IN_TIMEOUT);
    }

    public function rememberPasswordConfirmation(): void
    {
        session()->put(self::PASSWORD_CONFIRMED_AT, now()->getTimestamp());
    }

    /**
     * Linking from the account page: the password was confirmed just before (accounts without
     * a password have nothing to confirm). Consumed by the check.
     */
    public function pullPasswordConfirmation(User $user): bool
    {
        $recent = $this->isRecent(self::PASSWORD_CONFIRMED_AT, self::PASSWORD_TIMEOUT);

        session()->forget(self::PASSWORD_CONFIRMED_AT);

        return $recent || $user->password === null;
    }

    private function isRecent(string $key, int $timeout): bool
    {
        $at = session()->get($key);

        return is_int($at) && $at >= now()->getTimestamp() - $timeout;
    }

    /**
     * Ends the user's sessions except the current request's. Only the database session driver
     * (the default) can find them; with other drivers they last until SESSION_LIFETIME, the
     * rotated remember token keeps them from coming back.
     */
    private function endOtherSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->where('id', '!=', session()->getId())
                ->delete();
        }

        // AuthenticateSession compares this hash with the password; it is stored again once there is one.
        session()->forget('password_hash_'.config('auth.defaults.guard'));
    }
}
