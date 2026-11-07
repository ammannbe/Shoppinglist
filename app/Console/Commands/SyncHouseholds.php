<?php

namespace App\Console\Commands;

use App\Households\KontoHouseholds;
use App\Households\KontoUnavailable;
use App\Jobs\ReportKontoShares;
use App\Models\Household;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily fallback for lost webhooks (HOUSEHOLDS_DRIVER=konto): the households of every user with
 * oidc_sub, then every mirrored household nobody listed (404 deletes it). If the AccountManager is
 * not reachable, the last state stays.
 */
#[Signature('households:sync')]
#[Description('Mirror the households of the AccountManager (HOUSEHOLDS_DRIVER=konto)')]
class SyncHouseholds extends Command
{
    public function handle(KontoHouseholds $households): int
    {
        if (! KontoHouseholds::enabled()) {
            $this->info('Households are not mirrored (HOUSEHOLDS_DRIVER=konto with KONTO_URL, KONTO_SERVICE_KEY and KONTO_SECRET).');

            return self::SUCCESS;
        }

        $checked = [];
        $users = 0;

        try {
            User::whereNotNull('oidc_sub')->lazyById()->each(function (User $user) use ($households, &$checked, &$users) {
                $households->syncUser($user, $checked);
                $users++;
            });

            Household::whereNotNull('konto_household_uuid')->pluck('konto_household_uuid')
                ->reject(fn (string $uuid) => isset($checked[$uuid]))
                ->each(fn (string $uuid) => $households->refresh($uuid));
        } catch (KontoUnavailable $exception) {
            KontoHouseholds::logUnavailable($exception, 'households:sync');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        ReportKontoShares::dispatchFor(Household::whereNotNull('konto_household_uuid')->get());
        $this->info("Synced the households of {$users} users, ".Household::whereNotNull('konto_household_uuid')->count().' households mirrored.');

        return self::SUCCESS;
    }
}
