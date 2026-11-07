<?php

namespace App\Households;

use App\Jobs\ReportKontoShares;
use App\Models\Household;
use App\Models\HouseholdPendingMember;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors the households of the AccountManager (HOUSEHOLDS_DRIVER=konto, contract: AccountManager
 * docs/HOUSEHOLDS-API.md, section 5). Every webhook and API answer carries the full snapshot of a
 * household: the local copy is set to it (name, members by oidc_sub, roles), older versions are
 * ignored. Access to the shared lists follows through ShoppingList::syncHouseholdAccess(), so
 * removed members lose the lists on their next sync.
 */
class KontoHouseholds
{
    public const DELETED = 'household.deleted';

    public function __construct(private readonly KontoClient $client) {}

    public static function enabled(): bool
    {
        return config('households.driver') === 'konto' && KontoClient::configured();
    }

    /**
     * Applies a webhook event (any type, the snapshot counts). Events of unknown households are
     * confirmed through the API, so stale events after a deletion create nothing.
     *
     * @param  array{uuid: string, name: string, version: int, members: list<array{sub: string, role: string}>, deleted?: bool}  $snapshot
     *
     * @throws KontoUnavailable
     */
    public function handleEvent(string $type, array $snapshot): void
    {
        if ($type === self::DELETED || ! empty($snapshot['deleted'])) {
            $this->delete($snapshot['uuid']);

            return;
        }

        if (! Household::where('konto_household_uuid', $snapshot['uuid'])->exists()) {
            $this->refresh($snapshot['uuid']);

            return;
        }

        $this->apply($snapshot);
    }

    /**
     * Fetches the household and applies it; 404 deletes the local copy.
     *
     * @throws KontoUnavailable
     */
    public function refresh(string $uuid): ?Household
    {
        $snapshot = $this->client->household($uuid);

        if ($snapshot === null) {
            $this->delete($uuid);

            return null;
        }

        return $this->apply($snapshot, authoritative: true);
    }

    /**
     * Sets the local copy to the snapshot. Webhooks apply only newer versions, API answers
     * ($authoritative) also the same version.
     *
     * @param  array{uuid: string, name: string, version: int, members: list<array{sub: string, role: string}>}  $snapshot
     */
    public function apply(array $snapshot, bool $authoritative = false, ?Household $household = null): Household
    {
        try {
            return $this->applyOnce($snapshot, $authoritative, $household);
        } catch (UniqueConstraintViolationException) {
            // Two deliveries created the same household at once: the other one won, apply on top.
            return $this->applyOnce($snapshot, $authoritative, null);
        }
    }

    public function delete(string $uuid): void
    {
        Household::where('konto_household_uuid', $uuid)->first()?->deleteWithShares();
    }

    /**
     * Login and daily sync: all households of the user (3.2). Mirrored households of the user that
     * are missing were left or deleted: they are checked one by one (3.3). Uuids applied are added
     * to $checked, so the daily sync fetches every household once.
     *
     * @param  array<string, true>  $checked
     *
     * @throws KontoUnavailable the user's households could not be fetched (the state stays)
     */
    public function syncUser(User $user, array &$checked = []): void
    {
        if ($user->oidc_sub === null) {
            return;
        }

        $this->attachPending($user);
        $seen = [];

        foreach ($this->client->households($user->oidc_sub) as $snapshot) {
            $seen[$snapshot['uuid']] = true;
            if (! isset($checked[$snapshot['uuid']])) {
                $this->apply($snapshot, authoritative: true);
                $checked[$snapshot['uuid']] = true;
            }
        }

        $missing = $user->households()->whereNotNull('konto_household_uuid')->pluck('konto_household_uuid')
            ->reject(fn (string $uuid) => isset($seen[$uuid]) || isset($checked[$uuid]));

        foreach ($missing as $uuid) {
            $this->refresh($uuid);
            $checked[$uuid] = true;
        }
    }

    /**
     * The user logged in with OIDC for the first time: memberships the AccountManager reported
     * for their subject before become real ones.
     */
    public function attachPending(User $user): void
    {
        if ($user->oidc_sub === null) {
            return;
        }

        HouseholdPendingMember::where('oidc_sub', $user->oidc_sub)->with('household')->get()
            ->each(function (HouseholdPendingMember $pending) use ($user) {
                DB::transaction(function () use ($pending, $user) {
                    $household = $pending->household;
                    $pending->delete();
                    // Syncs the access to the shared lists (the user gets them on the next pull).
                    if (! $household->hasMember($user)) {
                        $household->addMember($user, $pending->role);
                    }
                });
            });
    }

    /**
     * The user unlinked the login: they lose the mirrored households (kept as pending, in case
     * the login is linked again).
     */
    public function detachUser(User $user): void
    {
        $user->households()->whereNotNull('konto_household_uuid')->get()
            ->each(function (Household $household) use ($user) {
                DB::transaction(function () use ($household, $user) {
                    if ($user->oidc_sub !== null) {
                        $household->pendingMembers()->updateOrCreate(['oidc_sub' => $user->oidc_sub], ['role' => $household->pivot->role]);
                    }
                    $this->reportShares($household, $household->detachMembers([$user->id])->isNotEmpty());
                    $household->syncListAccess();
                });
            });
    }

    /**
     * Switching from local households: the AccountManager confirmed the proposal of $local. Its
     * shares stay; if the household is mirrored already (the event came first), the shares move
     * there and the local household is dropped. Name and members come from the snapshot.
     *
     * @param  array{uuid: string, name: string, version: int, members: list<array{sub: string, role: string}>}  $snapshot
     */
    public function adopt(Household $local, array $snapshot): Household
    {
        return DB::transaction(function () use ($local, $snapshot) {
            $target = Household::where('konto_household_uuid', $snapshot['uuid'])->first();

            if ($target === null || $target->is($local)) {
                $local->forceFill(['konto_household_uuid' => $snapshot['uuid'], 'konto_version' => null, 'konto_import_status' => null])->save();
                $target = $local;
            } else {
                $target->shoppingLists()->syncWithoutDetaching($local->shoppingLists()->withTrashed()->pluck('shopping_lists.id')->all());
                $local->shoppingLists()->detach();
                $local->delete();
            }

            $target = $this->apply($snapshot, authoritative: true, household: $target);
            // Also when the snapshot changed nothing: the moved shares and the members.
            $target->syncListAccess();
            $this->reportShares($target);

            return $target;
        });
    }

    /**
     * Queues the report of the shares (3.4, overview in the AccountManager, failures are harmless).
     */
    public function reportShares(Household $household, bool $changed = true): void
    {
        if ($changed) {
            ReportKontoShares::dispatchFor($household);
        }
    }

    /**
     * @param  array{uuid: string, name: string, version: int, members: list<array{sub: string, role: string}>}  $snapshot
     */
    private function applyOnce(array $snapshot, bool $authoritative, ?Household $household): Household
    {
        return DB::transaction(function () use ($snapshot, $authoritative, $household) {
            $household = $household
                ? Household::whereKey($household->id)->lockForUpdate()->firstOrFail()
                : Household::where('konto_household_uuid', $snapshot['uuid'])->lockForUpdate()->first();

            if ($household?->konto_version !== null) {
                $stale = $authoritative ? $snapshot['version'] < $household->konto_version : $snapshot['version'] <= $household->konto_version;
                if ($stale) {
                    return $household;
                }
            }

            $household ??= (new Household)->forceFill(['konto_household_uuid' => $snapshot['uuid']]);
            $renamed = $household->exists && $household->name !== $snapshot['name'];
            $household->name = $snapshot['name'];
            $household->konto_version = $snapshot['version'];
            $household->save();

            [$membersChanged, $unshared] = $this->syncMembers($household, $snapshot['members']);

            if ($membersChanged) {
                // Creates or deletes the sync rows of the members and touches the list documents.
                $household->syncListAccess();
            } elseif ($renamed) {
                // The household name is part of the list documents.
                $household->touchLists();
            }
            $this->reportShares($household, $unshared);

            return $household;
        });
    }

    /**
     * Members by oidc_sub: unknown subjects are kept as pending members.
     *
     * @param  list<array{sub: string, role: string}>  $members
     * @return array{0: bool, 1: bool} members changed, lists of removed members unshared
     */
    private function syncMembers(Household $household, array $members): array
    {
        $roles = [];
        foreach ($members as $member) {
            $roles[$member['sub']] = in_array($member['role'], Household::ROLES, true) ? $member['role'] : 'member';
        }

        $users = User::whereIn('oidc_sub', array_keys($roles))->get()->keyBy('id');
        $current = $household->members()->get()->keyBy('id');
        $changed = false;

        foreach ($users as $user) {
            $role = $roles[$user->oidc_sub];
            if (! $current->has($user->id)) {
                $household->members()->attach($user, ['role' => $role, 'joined_at' => now()]);
                $changed = true;
            } elseif ($current[$user->id]->pivot->role !== $role) {
                $household->changeRole($user, $role);
            }
        }

        $removed = $current->keys()->diff($users->keys())->values()->all();
        $unshared = false;
        if ($removed !== []) {
            $unshared = $household->detachMembers($removed)->isNotEmpty();
            $changed = true;
        }

        $pending = array_diff_key($roles, array_flip($users->pluck('oidc_sub')->all()));
        $household->pendingMembers()->whereNotIn('oidc_sub', array_keys($pending))->delete();
        foreach ($pending as $sub => $role) {
            $household->pendingMembers()->updateOrCreate(['oidc_sub' => (string) $sub], ['role' => $role]);
        }

        return [$changed, $unshared];
    }

    /**
     * Logs a failed call; the app keeps working with the last mirrored state.
     */
    public static function logUnavailable(KontoUnavailable $exception, string $context): void
    {
        Log::warning("Households: {$context} failed, keeping the last state.", ['exception' => $exception->getMessage()]);
    }
}
