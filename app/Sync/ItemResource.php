<?php

namespace App\Sync;

use App\Models\Item;
use App\Models\Product;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ItemResource extends SyncResource
{
    protected function pullQuery(User $user): Builder
    {
        return Item::withTrashed()
            ->whereIn('shopping_list_id', $user->accessibleListIds())
            ->with('creator');
    }

    /**
     * @param  Item  $model
     */
    public function document(Model $model, User $user): array
    {
        return [
            'id' => $model->id,
            'shopping_list_id' => $model->shopping_list_id,
            'name' => $model->name,
            'amount' => $model->amount,
            'unit' => $model->unit,
            'image' => $model->image,
            'done' => $model->done,
            'position' => $model->position,
            'creator_email' => $model->creator?->email,
            'version' => $model->version,
            'updated_at' => $model->syncTimestamp(),
            '_deleted' => $model->trashed(),
        ];
    }

    protected function pushRow(User $user, ?array $assumed, array $new): ?array
    {
        /** @var Item|null $item */
        $item = $this->findForUpdate(Item::class, $new['id']);
        $listId = $item->shopping_list_id ?? $new['shopping_list_id'];

        if (! $this->canAccessList($user, $listId)) {
            return null;
        }

        if ($item === null) {
            if (! empty($new['_deleted'])) {
                return null;
            }

            $item = new Item($this->attributes($new, $user));
            $item->id = $new['id'];
            $item->shopping_list_id = $listId;
            $item->creator()->associate($user);
            $item->save();

            return null;
        }

        if ($item->trashed() || $this->isConflict($item, $assumed)) {
            return $this->document($item, $user);
        }

        $item->version = $this->nextVersion($item, $new);

        if (! empty($new['_deleted'])) {
            $item->save();
            $item->delete();

            return null;
        }

        $item->fill($this->attributes($new, $user, $item))->save();

        return null;
    }

    /**
     * @throws RetryLaterException when the list was created offline and not pushed yet
     */
    private function canAccessList(User $user, string $listId): bool
    {
        if (! ShoppingList::withTrashed()->whereKey($listId)->exists()) {
            throw new RetryLaterException("Unknown shopping list {$listId}.");
        }

        return $user->accessibleListIds()->whereKey($listId)->exists();
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function attributes(array $doc, User $user, ?Item $item = null): array
    {
        // Only images of own products (or the one the item already has), so nobody gets
        // access to a foreign image by pushing its id.
        $image = $doc['image'] ?? null;
        if ($image !== null && $image !== $item?->image
            && ! Product::where('image', $image)->where('owner_id', $user->id)->exists()) {
            $image = $item?->image;
        }

        return [
            'image' => $image,
            'name' => $doc['name'],
            'amount' => $doc['amount'] ?? null,
            'unit' => $doc['unit'] ?? null,
            'done' => (bool) ($doc['done'] ?? false),
            'position' => $doc['position'] ?? null,
        ];
    }
}
