<?php

namespace App\Jobs;

use App\Announcements\AccountManagerAnnouncements;
use App\Households\AccountManagerUnavailable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Webhook announcements.changed: fetches the changes since the last server_time (the event itself is
 * only a trigger). Queued once until it runs; a sync already running releases it for later. If the
 * AccountManager is down, the scheduled sync (every 15 minutes) catches up.
 */
class SyncAnnouncements implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(AccountManagerAnnouncements $announcements): void
    {
        if (! AccountManagerAnnouncements::enabled()) {
            return;
        }

        try {
            if ($announcements->sync() === null && $this->attempts() < $this->tries) {
                $this->release(30);
            }
        } catch (AccountManagerUnavailable $exception) {
            AccountManagerAnnouncements::logUnavailable($exception, 'syncing after a webhook');
        }
    }
}
