<?php

namespace App\Sync;

use App\Models\Membership;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lists are pulled through the user's memberships, so revoked access and
 * deleted lists both arrive as deleted documents.
 */
class ListResource extends SyncResource
{
    protected function pullQuery(User $user): Builder
    {
        return Membership::withTrashed()
            ->where('user_id', $user->id)
            ->with(['shoppingList.owner', 'shoppingList.memberships.user']);
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
            'owner_email' => $list->owner->email,
            'is_owner' => $isOwner,
            'members' => $isOwner
                ? $list->memberships->pluck('user.email')->reject(fn ($email) => $email === $user->email)->sort()->values()->all()
                : [],
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

            $list = new ShoppingList(['name' => $new['name']]);
            $list->id = $new['id'];
            $list->owner()->associate($user);
            $list->save();
            $list->addMember($user);

            return null;
        }

        $membership = Membership::withTrashed()
            ->where('shopping_list_id', $list->id)
            ->where('user_id', $user->id)
            ->first();

        // No access (anymore): the client removes the list after the next pull.
        if ($membership === null || $membership->trashed()) {
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
            }

            return null;
        }

        $list->name = $new['name'];
        $list->version = $this->nextVersion($list, $new);
        $list->save();
        $list->touchForMembers();

        return null;
    }
}
