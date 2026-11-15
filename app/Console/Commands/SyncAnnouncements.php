<?php

namespace App\Console\Commands;

use App\Announcements\AccountManagerAnnouncements;
use App\Households\AccountManagerUnavailable;
use Illuminate\Console\Command;

/**
 * Announcements of the AccountManager (ANNOUNCEMENTS_DRIVER=account-manager): every 15 minutes with since
 * (fallback for lost webhooks), daily with --full. If the AccountManager is down, the last state stays.
 */
class SyncAnnouncements extends Command
{
    protected $signature = 'account-manager:sync-announcements {--full : Fetch the complete list (without since) and remove local copies missing there}';

    protected $description = 'Mirror the announcements of the AccountManager and send new ones by push and email';

    public function handle(AccountManagerAnnouncements $announcements): int
    {
        if (! AccountManagerAnnouncements::enabled()) {
            $this->info('Announcements from the AccountManager are not enabled (ANNOUNCEMENTS_DRIVER, ACCOUNT_MANAGER_*).');

            return self::SUCCESS;
        }

        try {
            $result = $announcements->sync((bool) $this->option('full'));
        } catch (AccountManagerUnavailable $exception) {
            AccountManagerAnnouncements::logUnavailable($exception, 'syncing');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result === null) {
            $this->warn('Another sync of the announcements is running.');

            return self::SUCCESS;
        }

        $this->info("Announcements: {$result['upserted']} updated, {$result['withdrawn']} withdrawn.");

        return self::SUCCESS;
    }
}
