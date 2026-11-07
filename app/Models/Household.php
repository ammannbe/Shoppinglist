<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Group of users sharing lists. Lists shared with a household are accessible to all its
 * members; access is resolved through the membership (see ShoppingList::syncHouseholdAccess).
 * Removing members or deleting the household only removes access, lists stay with their owner.
 */
#[Fillable(['name'])]
class Household extends Model
{
    public const ROLES = ['admin', 'member'];

    /**
     * HOUSEHOLDS_DRIVER=local: managed in this app. Otherwise (account-manager) they are mirrored and
     * the local management is read only.
     */
    public static function managedLocally(): bool
    {
        return config('households.driver') === 'local';
    }

    /**
     * Mirrored from the AccountManager (HOUSEHOLDS_DRIVER=account-manager): name and members come from there.
     * After switching back to "local" mirrored households are ordinary local ones.
     */
    public function isMirrored(): bool
    {
        return $this->account_manager_household_uuid !== null && ! self::managedLocally();
    }

    protected function casts(): array
    {
        return [
            'account_manager_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Household $household) {
            $household->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'joined_at');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(HouseholdInvitation::class);
    }

    /**
     * Members of the mirrored household without a user here yet (attached on their first OIDC login).
     */
    public function pendingMembers(): HasMany
    {
        return $this->hasMany(HouseholdPendingMember::class);
    }

    public function shoppingLists(): BelongsToMany
    {
        return $this->belongsToMany(ShoppingList::class, 'shopping_list_household')->withTimestamps();
    }

    public function roleOf(User $user): ?string
    {
        return $this->members()->whereKey($user->id)->first()?->pivot->role;
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function isAdmin(User $user): bool
    {
        return $this->roleOf($user) === 'admin';
    }

    /**
     * Removing the user (or making them a member) would leave the household without admin.
     */
    public function isLastAdmin(User $user): bool
    {
        return $this->isAdmin($user) && $this->members()->wherePivot('role', 'admin')->count() === 1;
    }

    /**
     * Add a user (or change the role of a member). The lists shared with the household
     * become accessible to the new member with the next sync.
     */
    public function addMember(User $user, string $role = 'member'): void
    {
        if ($this->hasMember($user)) {
            $this->members()->updateExistingPivot($user->id, ['role' => $role]);

            return;
        }

        $this->members()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        $this->syncListAccess();
    }

    public function changeRole(User $user, string $role): void
    {
        $this->members()->updateExistingPivot($user->id, ['role' => $role]);
    }

    /**
     * Remove a member (or let them leave). The member loses access to the lists shared with
     * the household, and the household loses access to the lists the member shared with it.
     */
    public function removeMember(User $user): void
    {
        $this->detachMembers([$user->id]);
        $this->syncListAccess();
    }

    /**
     * Removes members without syncing the other shared lists (the caller does, syncListAccess):
     * their own lists are no longer shared with the household. Returns these lists.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, ShoppingList>
     */
    public function detachMembers(array $userIds): Collection
    {
        $this->members()->detach($userIds);
        $own = $this->shoppingLists()->withTrashed()->whereIn('owner_id', $userIds)->get();
        $this->shoppingLists()->detach($own->modelKeys());
        $own->each->syncHouseholdAccess();

        return $own;
    }

    /**
     * Delete the household: only the shares and memberships go, the lists stay.
     */
    public function deleteWithShares(): void
    {
        $lists = $this->shoppingLists()->get();
        $this->delete();
        $lists->each->syncHouseholdAccess();
    }

    /**
     * Brings the sync rows of all lists shared with the household in line with the members.
     */
    public function syncListAccess(): void
    {
        $this->shoppingLists()->get()->each->syncHouseholdAccess();
    }

    /**
     * The household names are part of the list documents.
     */
    public function touchLists(): void
    {
        $this->shoppingLists()->get()->each->touchForMembers();
    }
}
