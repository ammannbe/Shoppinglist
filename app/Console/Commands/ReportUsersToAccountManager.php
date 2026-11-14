<?php

namespace App\Console\Commands;

use App\Households\AccountManagerClient;
use App\Households\AccountManagerUnavailable;
use App\Households\AccountManagerUsers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily full sync of the users of this app to the AccountManager (ACCOUNT_MANAGER_REPORT_USERS): catches up
 * lost single reports and removes users deleted here. Same file as in Chorelist.
 */
class ReportUsersToAccountManager extends Command
{
    protected $signature = 'account-manager:report-users';

    protected $description = 'Send the list of all users of this app to the AccountManager';

    public function handle(AccountManagerUsers $users, AccountManagerClient $client): int
    {
        if (! AccountManagerUsers::enabled()) {
            $this->info('Users are not reported (ACCOUNT_MANAGER_REPORT_USERS with ACCOUNT_MANAGER_URL, ACCOUNT_MANAGER_SERVICE_KEY and ACCOUNT_MANAGER_SECRET).');

            return self::SUCCESS;
        }

        try {
            $result = $users->syncAll($client->withTimeout(60));
        } catch (AccountManagerUnavailable $exception) {
            Log::warning('account-manager:report-users: '.$exception->getMessage());
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Reported {$result['users']} user(s): {$result['upserted']} upserted, {$result['removed']} removed.");

        return self::SUCCESS;
    }
}
