<?php

namespace App\Models\Concerns;

use App\Households\AccountManagerUsers;
use App\Models\User;

/**
 * ACCOUNT_MANAGER_REPORT_USERS: the AccountManager learns about new users, changes of what it shows (address,
 * name, OIDC link, last login: App\Listeners\RecordLogin) and deletions (App\Households\AccountManagerUsers).
 * Same file as in Chorelist.
 */
trait ReportsToAccountManager
{
    /**
     * Attributes the AccountManager gets (AccountManagerUsers::payload).
     *
     * @var list<string>
     */
    public const REPORTED_ATTRIBUTES = ['email', 'name', 'oidc_sub', 'last_login_at'];

    protected static function bootReportsToAccountManager(): void
    {
        static::created(fn (User $user) => AccountManagerUsers::report($user));

        static::updated(function (User $user): void {
            if ($user->wasChanged(self::REPORTED_ATTRIBUTES)) {
                AccountManagerUsers::report($user);
            }
        });

        static::deleted(fn (User $user) => AccountManagerUsers::forget($user));
    }
}
