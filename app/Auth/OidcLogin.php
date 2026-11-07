<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser as SocialiteUser;

/**
 * Resolves the local user for an OpenID Connect login.
 *
 * Accounts are matched by the provider's subject ("sub"). A local account is only
 * linked through an e-mail address that the provider marks as verified. Linking makes
 * the account SSO-only (App\Auth\OidcAccount::link()). Linked accounts take the
 * provider's name on every login (the app has no name form).
 */
class OidcLogin
{
    public function __construct(private readonly OidcAccount $accounts) {}

    public function resolve(SocialiteUser $identity, ?User $linkTo = null): User
    {
        return $this->takeName($this->find($identity, $linkTo), $identity);
    }

    private function find(SocialiteUser $identity, ?User $linkTo): User
    {
        $sub = $this->subject($identity);

        $linked = User::query()->where('oidc_sub', $sub)->first();

        if ($linkTo) {
            return $this->link($linkTo, $sub, $linked);
        }

        if ($linked) {
            return $linked;
        }

        $email = mb_strtolower(trim((string) $identity->getEmail()));

        if ($email === '' || ! $this->emailIsVerified((array) $identity->getRaw())) {
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
     * Before unlinking or deleting the account: the logged in user confirms the login at the provider.
     */
    public function confirm(SocialiteUser $identity, User $user): void
    {
        if (! $user->isLinkedToOidc() || ! hash_equals((string) $user->oidc_sub, $this->subject($identity))) {
            throw OidcLoginException::because('This sign-in belongs to another account.');
        }
    }

    /**
     * The name others see comes from the provider (kept when it sends none).
     */
    private function takeName(User $user, SocialiteUser $identity): User
    {
        $name = $this->name($identity);

        if ($name !== null && $name !== $user->name) {
            $user->forceFill(['name' => $name])->save();
        }

        return $user;
    }

    private function name(SocialiteUser $identity): ?string
    {
        return Str::limit(trim((string) ($identity->getName() ?: $identity->getNickname())), 255, '') ?: null;
    }

    private function subject(SocialiteUser $identity): string
    {
        $sub = (string) $identity->getId();

        if ($sub === '') {
            throw OidcLoginException::because('The login did not return a user.');
        }

        $this->assertAllowed((array) $identity->getRaw());

        return $sub;
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

        if ($user->oidc_sub !== null && $user->oidc_sub !== $sub) {
            throw OidcLoginException::because('This account is already linked to another login.');
        }

        if (! $linked) {
            $this->accounts->link($user, $sub);
        }

        return $user;
    }

    private function linkByEmail(User $user, string $sub): User
    {
        if ($user->oidc_sub !== null) {
            throw OidcLoginException::because('This account is already linked to another login.');
        }

        $wasVerified = $user->hasVerifiedEmail();

        // An unverified account may belong to somebody who registered (or was invited with) the address
        // without owning it: the verified owner takes it over (linking drops the password, the passkeys
        // and the other sessions).
        $this->accounts->link($user, $sub, $wasVerified ? [] : ['email_verified_at' => now()]);

        if (! $wasVerified) {
            event(new Verified($user));
        }

        return $user;
    }

    private function register(SocialiteUser $identity, string $sub, string $email): User
    {
        $user = new User([
            'name' => $this->name($identity),
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
