<?php

namespace App\Households;

use App\Jobs\ReportUserToAccountManager;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reports the users of this app to the AccountManager (ACCOUNT_MANAGER_REPORT_USERS, contract: AccountManager
 * docs/SERVICE-USERS-API.md): single upserts and deletions as queued jobs (ReportUserToAccountManager, from
 * App\Models\Concerns\ReportsToAccountManager), the full list daily (account-manager:report-users).
 * Independent of the households driver; failures never break the app (login, registration, deletion).
 */
class AccountManagerUsers
{
    /**
     * Max. users per request of a full sync.
     */
    public const CHUNK = 5000;

    public static function enabled(): bool
    {
        return (bool) config('households.account_manager.report_users') && AccountManagerClient::configured();
    }

    /**
     * @return array{id: string, email: string, name: string|null, oidc_sub: string|null, last_login_at: string|null, created_at: string|null}
     */
    public static function payload(User $user): array
    {
        return [
            'id' => (string) $user->getKey(),
            'email' => $user->email,
            'name' => $user->name ?: null,
            'oidc_sub' => $user->oidc_sub,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * Queues an upsert of the user (after the transaction).
     */
    public static function report(User $user): void
    {
        self::dispatch(ReportUserToAccountManager::UPSERT, (string) $user->getKey());
    }

    /**
     * Queues the deletion of the user (after the transaction).
     */
    public static function forget(User $user): void
    {
        self::dispatch(ReportUserToAccountManager::DELETE, (string) $user->getKey());
    }

    /**
     * Full sync: all users in chunks of CHUNK, the AccountManager removes the others with the last request.
     *
     * @return array{users: int, upserted: int, removed: int}
     */
    public function syncAll(AccountManagerClient $client, int $chunk = self::CHUNK): array
    {
        // A few seconds early against clock skew: only keeps stale entries one more day, never drops reported ones.
        $startedAt = now()->subSeconds(5)->toIso8601String();
        $result = ['users' => 0, 'upserted' => 0, 'removed' => 0];
        // One chunk is held back, so that the last request is the one that completes the sync.
        $pending = null;

        $send = function (array $users, bool $complete) use ($client, $startedAt, &$result): void {
            $answer = $client->syncUsers($users, $complete, $startedAt);
            $result['users'] += count($users);
            $result['upserted'] += $answer['upserted'];
            $result['removed'] += $answer['removed'];
        };

        User::query()->chunkById($chunk, function ($users) use (&$pending, $send): void {
            if ($pending !== null) {
                $send($pending, false);
            }
            $pending = $users->map(fn (User $user) => self::payload($user))->values()->all();
        });

        // No users at all: an empty complete list removes the ones the AccountManager still knows.
        $send($pending ?? [], true);

        return $result;
    }

    private static function dispatch(string $action, string $id): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            ReportUserToAccountManager::dispatch($action, $id)->afterCommit();
        } catch (Throwable $exception) {
            // E.g. a synchronous queue while the AccountManager is down: never break the request.
            Log::warning('Could not report a user to the AccountManager: '.$exception->getMessage(), ['user' => $id, 'action' => $action]);
        }
    }
}
