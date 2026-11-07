<?php

namespace App\Sync;

use App\Models\Household;
use App\Models\Membership;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Lists are pulled through the user's memberships, so revoked access and
 * deleted lists both arrive as deleted documents. Members of a household the
 * list is shared with have a membership too (`direct` false), see
 * ShoppingList::syncHouseholdAccess().
 */
class ListResource extends SyncResource
{
    /**
     * @var array<int, Collection<int, int>>
     */
    private array $householdIds = [];

    protected function pullQuery(User $user): Builder
    {
        return Membership::withTrashed()
            ->where('user_id', $user->id)
            ->with(['shoppingList.owner', 'shoppingList.memberships.user', 'shoppingList.households']);
    }

    protected function checkpointIdColumn(): string
    {
        return 'shopping_list_id';
    }

    /**
     * @param  Membership  $model
     */
    public function document(Model $model, User $user): array
    {
        $list = $model->shoppingList;
        $isOwner = $list->isOwnedBy($user);

        return [
            'id' => $list->id,
            'name' => $list->name,
            'item_sort' => $list->item_sort,
            'owner_email' => $list->owner->email,
            'is_owner' => $isOwner,
            // The order of the lists is personal.
            'position' => $model->position,
            // Shared by email, members through a household are not listed.
            'members' => $isOwner
                ? $list->memberships->where('direct', true)->pluck('user.email')->reject(fn ($email) => $email === $user->email)->sort()->values()->all()
                : [],
            // The owner sees all households the list is shared with, members the ones they belong to.
            'households' => $list->households
                ->when(! $isOwner, fn ($households) => $households->whereIn('id', $this->householdIdsOf($user)))
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn (Household $household) => ['id' => $household->uuid, 'name' => $household->name])
                ->values()->all(),
            'version' => $list->version,
            'updated_at' => $model->syncTimestamp(),
            '_deleted' => $model->trashed() || $list->trashed(),
        ];
    }

    protected function pushRow(User $user, ?array $assumed, array $new): ?array
    {
        /** @var ShoppingList|null $list */
        $list = $this->findForUpdate(ShoppingList::class, $new['id']);

        if ($list === null) {
            if (! empty($new['_deleted'])) {
                return null;
            }

            $list = new ShoppingList(['name' => $new['name'], 'item_sort' => $new['item_sort'] ?? null]);
            $list->id = $new['id'];
            $list->owner()->associate($user);
            $list->save();
            $list->addMember($user)->update(['position' => $new['position'] ?? null]);

            return null;
        }

        $membership = Membership::withTrashed()
            ->where('shopping_list_id', $list->id)
            ->where('user_id', $user->id)
            ->first();

        // No access (anymore): the client removes the list after the next pull.
        if ($membership === null || $membership->trashed()
            || ! $user->accessibleListIds()->withTrashed()->whereKey($list->id)->exists()) {
            return null;
        }

        if ($list->trashed() || $this->isConflict($list, $assumed)) {
            return $this->document($membership->setRelation('shoppingList', $list), $user);
        }

        if (! empty($new['_deleted'])) {
            if ($list->isOwnedBy($user)) {
                $list->version = $this->nextVersion($list, $new);
                $list->save();
                $list->delete();
                $list->touchForMembers();
            } else {
                // Members cannot delete a list, they leave it.
                $list->removeMember($user);

                // Access through a household stays: the list comes back.
                if ($user->accessibleListIds()->whereKey($list->id)->exists()) {
                    return $this->document($membership->refresh()->setRelation('shoppingList', $list), $user);
                }
            }

            return null;
        }

        $list->name = $new['name'];
        $list->item_sort = $new['item_sort'] ?? null;
        $list->version = $this->nextVersion($list, $new);
        $list->save();
        $list->touchForMembers();
        $membership->update(['position' => $new['position'] ?? null]);

        return null;
    }

    /**
     * @return Collection<int, int>
     */
    private function householdIdsOf(User $user): Collection
    {
        return $this->householdIds[$user->id] ??= $user->households()->pluck('households.id');
    }
}
