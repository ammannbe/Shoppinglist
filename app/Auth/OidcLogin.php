<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser as SocialiteUser;

/**
 * Resolves the local user for an OpenID Connect login.
 *
 * Accounts are matched by the provider's subject ("sub"). A local account is only
 * linked through an e-mail address that the provider marks as verified.
 */
class OidcLogin
{
    public function resolve(SocialiteUser $identity, ?User $linkTo = null): User
    {
        $sub = (string) $identity->getId();
        $claims = (array) $identity->getRaw();

        if ($sub === '') {
            throw OidcLoginException::because('The login did not return a user.');
        }

        $this->assertAllowed($claims);

        $linked = User::query()->where('oidc_sub', $sub)->first();

        if ($linkTo) {
            return $this->link($linkTo, $sub, $linked);
        }

        if ($linked) {
            return $linked;
        }

        $email = mb_strtolower(trim((string) $identity->getEmail()));

        if ($email === '' || ! $this->emailIsVerified($claims)) {
            throw OidcLoginException::because('Your email address must be verified with the login provider.');
        }

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing) {
            return $this->linkByEmail($existing, $sub);
        }

        if (! config('login.oidc.registration')) {
            throw OidcLoginException::because('There is no account for this email address.');
        }

        return $this->register($identity, $sub, $email);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertAllowed(array $claims): void
    {
        $group = config('login.oidc.required_group');

        if ($group && ! in_array($group, (array) ($claims['groups'] ?? []), true)) {
            throw OidcLoginException::because('You do not have access to this app.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function emailIsVerified(array $claims): bool
    {
        $verified = $claims['email_verified'] ?? false;

        return $verified === true || $verified === 'true';
    }

    private function link(User $user, string $sub, ?User $linked): User
    {
        if ($linked && ! $linked->is($user)) {
            throw OidcLoginException::because('This login is already linked to another account.');
        }

        $user->forceFill(['oidc_sub' => $sub])->save();

        return $user;
    }

    private function linkByEmail(User $user, string $sub): User
    {
        if ($user->oidc_sub !== null) {
            throw OidcLoginException::because('This account is already linked to another login.');
        }

        if ($user->hasVerifiedEmail()) {
            $user->forceFill(['oidc_sub' => $sub])->save();

            return $user;
        }

        // Somebody else may have registered (or been invited with) this address: the verified owner
        // must not end up in an account that someone else can still log in to.
        DB::transaction(function () use ($user, $sub): void {
            $user->passkeys()->delete();
            $user->forceFill([
                'oidc_sub' => $sub,
                'email_verified_at' => now(),
                'password' => null,
                'remember_token' => Str::random(60),
            ])->save();
            $this->endSessions($user);
        });

        event(new Verified($user));

        return $user;
    }

    /**
     * Without a password AuthenticateSession no longer checks the sessions, so end them here.
     */
    private function endSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }
    }

    private function register(SocialiteUser $identity, string $sub, string $email): User
    {
        $user = new User([
            'name' => Str::limit(trim((string) ($identity->getName() ?: $identity->getNickname())), 255, '') ?: null,
            'email' => $email,
            'locale' => app()->getLocale(),
        ]);
        $user->forceFill([
            'oidc_sub' => $sub,
            'email_verified_at' => now(),
        ])->save();

        event(new Registered($user));

        return $user;
    }
}
