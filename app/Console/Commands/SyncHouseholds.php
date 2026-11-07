<?php

namespace App\Console\Commands;

use App\Households\AccountManagerHouseholds;
use App\Households\AccountManagerUnavailable;
use App\Jobs\ReportAccountManagerShares;
use App\Models\Household;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily fallback for lost webhooks (HOUSEHOLDS_DRIVER=account-manager): the households of every user with
 * oidc_sub, then every mirrored household nobody listed (404 deletes it). If the AccountManager is
 * not reachable, the last state stays.
 */
#[Signature('households:sync')]
#[Description('Mirror the households of the AccountManager (HOUSEHOLDS_DRIVER=account-manager)')]
class SyncHouseholds extends Command
{
    public function handle(AccountManagerHouseholds $households): int
    {
        if (! AccountManagerHouseholds::enabled()) {
            $this->info('Households are not mirrored (HOUSEHOLDS_DRIVER=account-manager with ACCOUNT_MANAGER_URL, ACCOUNT_MANAGER_SERVICE_KEY and ACCOUNT_MANAGER_SECRET).');

            return self::SUCCESS;
        }

        $checked = [];
        $users = 0;

        try {
            User::whereNotNull('oidc_sub')->lazyById()->each(function (User $user) use ($households, &$checked, &$users) {
                $households->syncUser($user, $checked);
                $users++;
            });

            Household::whereNotNull('account_manager_household_uuid')->pluck('account_manager_household_uuid')
                ->reject(fn (string $uuid) => isset($checked[$uuid]))
                ->each(fn (string $uuid) => $households->refresh($uuid));
        } catch (AccountManagerUnavailable $exception) {
            AccountManagerHouseholds::logUnavailable($exception, 'households:sync');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        ReportAccountManagerShares::dispatchFor(Household::whereNotNull('account_manager_household_uuid')->get());
        $this->info("Synced the households of {$users} users, ".Household::whereNotNull('account_manager_household_uuid')->count().' households mirrored.');

        return self::SUCCESS;
    }
}
