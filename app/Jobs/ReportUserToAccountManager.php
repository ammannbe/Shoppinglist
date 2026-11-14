<?php

namespace App\Jobs;

use App\Households\AccountManagerClient;
use App\Households\AccountManagerUnavailable;
use App\Households\AccountManagerUsers;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the AccountManager about one user of this app (App\Households\AccountManagerUsers): upsert with the
 * current data (a user deleted meanwhile is skipped, the deletion job follows) or the deletion. Retried with
 * backoff; after the last try the daily account-manager:report-users catches up.
 */
class ReportUserToAccountManager implements ShouldQueue
{
    use Queueable;

    public const UPSERT = 'upsert';

    public const DELETE = 'delete';

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 600];

    public function __construct(public string $action, public string $userId) {}

    public function handle(AccountManagerClient $client): void
    {
        if (! AccountManagerUsers::enabled()) {
            return;
        }

        try {
            if ($this->action === self::DELETE) {
                $client->deleteUser($this->userId);

                return;
            }

            $user = User::find($this->userId);
            if ($user !== null) {
                $client->upsertUser(AccountManagerUsers::payload($user));
            }
        } catch (AccountManagerUnavailable $exception) {
            // Synchronous queue (no worker, no retries): log only, the request goes on.
            if ($this->job === null || $this->job instanceof SyncJob) {
                Log::warning('Could not report a user to the AccountManager: '.$exception->getMessage(), ['user' => $this->userId, 'action' => $this->action]);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Gave up reporting a user to the AccountManager: '.$exception?->getMessage(), ['user' => $this->userId, 'action' => $this->action]);
    }
}
