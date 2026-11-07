<?php

namespace App\Models;

use App\Households\KontoHouseholds;
use App\Jobs\ReportKontoShares;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'item_sort'])]
class ShoppingList extends Model
{
    use HasFactory, Syncable;

    protected static function booted(): void
    {
        // The AccountManager shows the names of the lists shared with a household (HOUSEHOLDS_DRIVER=konto).
        static::updated(function (ShoppingList $list) {
            if ($list->wasChanged('name')) {
                $list->reportKontoShares();
            }
        });
        static::deleted(fn (ShoppingList $list) => $list->reportKontoShares());
        static::restored(fn (ShoppingList $list) => $list->reportKontoShares());
    }

    public function reportKontoShares(): void
    {
        if (KontoHouseholds::enabled()) {
            ReportKontoShares::dispatchFor($this->households()->whereNotNull('konto_household_uuid')->get());
        }
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Active memberships (the owner is a member too), including the sync rows of household
     * members (`direct` false).
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'shopping_list_household')->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    /**
     * Shared with the user by email (or the owner), access through a household not counted.
     */
    public function hasMember(User $user): bool
    {
        return $this->memberships()->where('user_id', $user->id)->where('direct', true)->exists();
    }

    /**
     * Add a user, or restore a revoked membership.
     */
    public function addMember(User $user): Membership
    {
        $membership = Membership::withTrashed()->firstOrNew([
            'shopping_list_id' => $this->id,
            'user_id' => $user->id,
        ]);
        $membership->direct = true;

        if ($membership->trashed()) {
            $membership->restore();
        } else {
            $membership->save();
        }

        $this->touchForMembers();
        // A new member has to pull all existing items of the list.
        $this->items()->touch();

        return $membership;
    }

    /**
     * Remove a member shared by email. With access through a household the sync row stays.
     */
    public function removeMember(User $user): void
    {
        $membership = $this->memberships()->where('user_id', $user->id)->first();

        if ($membership !== null && $this->householdUserIds()->contains($user->id)) {
            $membership->update(['direct' => false]);
        } else {
            $membership?->delete();
        }

        $this->touchForMembers();
    }

    public function shareWith(Household $household): void
    {
        $this->households()->syncWithoutDetaching([$household->id]);
        $this->syncHouseholdAccess();
    }

    public function unshareWith(Household $household): void
    {
        $this->households()->detach($household->id);
        $this->syncHouseholdAccess();
    }

    /**
     * Users with access through the households the list is shared with.
     *
     * @return Collection<int, int>
     */
    public function householdUserIds(): Collection
    {
        return DB::table('household_user')
            ->join('shopping_list_household', 'shopping_list_household.household_id', '=', 'household_user.household_id')
            ->where('shopping_list_household.shopping_list_id', $this->id)
            ->distinct()
            ->pluck('household_user.user_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Lists are pulled per sync row (shopping_list_user), so household members need one too:
     * create or restore it for members with access, delete it when the access is gone (the
     * client then removes the list). Rows of members shared by email and the owner stay.
     * Call it after every change of the shares or of the members of a shared household.
     */
    public function syncHouseholdAccess(): void
    {
        $userIds = $this->householdUserIds();
        $memberships = Membership::withTrashed()->where('shopping_list_id', $this->id)->get()->keyBy('user_id');
        $added = false;

        foreach ($userIds as $userId) {
            $membership = $memberships->get($userId);

            if ($membership === null) {
                $membership = new Membership(['shopping_list_id' => $this->id, 'user_id' => $userId]);
                $membership->direct = false;
                $membership->save();
                $added = true;
            } elseif ($membership->trashed()) {
                $membership->direct = false;
                $membership->restore();
                $added = true;
            }
        }

        foreach ($memberships as $membership) {
            if (! $membership->trashed() && ! $membership->direct && $membership->user_id !== $this->owner_id
                && ! $userIds->contains($membership->user_id)) {
                $membership->delete();
            }
        }

        $this->touchForMembers();
        if ($added) {
            // New members have to pull all existing items of the list.
            $this->items()->touch();
        }
    }

    /**
     * List documents are pulled per membership, so every change of the
     * list (name, members, deletion) has to touch all memberships.
     */
    public function touchForMembers(): void
    {
        $this->memberships()->touch();
    }
}
