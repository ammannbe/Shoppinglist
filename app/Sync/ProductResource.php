<?php

namespace App\Sync;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends SyncResource
{
    protected function pullQuery(User $user): Builder
    {
        return Product::withTrashed()->where(
            fn (Builder $q) => $q->where('owner_id', $user->id)->orWhereNull('owner_id')
        );
    }

    /**
     * @param  Product  $model
     */
    public function document(Model $model, User $user): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'is_public' => $model->owner_id === null,
            'version' => $model->version,
            'updated_at' => $model->syncTimestamp(),
            '_deleted' => $model->trashed(),
        ];
    }

    protected function pushRow(User $user, ?array $assumed, array $new): ?array
    {
        /** @var Product|null $product */
        $product = $this->findForUpdate(Product::class, $new['id']);

        if ($product === null) {
            if (! empty($new['_deleted'])) {
                return null;
            }

            $product = new Product(['name' => $new['name']]);
            $product->id = $new['id'];
            $product->owner()->associate($user);
            $product->save();

            return null;
        }

        if ($product->owner_id !== null && $product->owner_id !== $user->id) {
            return null;
        }

        // Public products are read-only: reset the client to the server state.
        if ($product->owner_id === null || $product->trashed() || $this->isConflict($product, $assumed)) {
            return $this->document($product, $user);
        }

        $product->version = $this->nextVersion($product, $new);

        if (! empty($new['_deleted'])) {
            $product->save();
            $product->delete();

            return null;
        }

        $product->fill(['name' => $new['name']])->save();

        return null;
    }
}
