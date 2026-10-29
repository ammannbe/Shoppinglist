<?php

namespace App\Sync;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Products, units and shops: own entries per user plus public ones (no owner), which are read-only.
 */
abstract class CatalogResource extends SyncResource
{
    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    protected function pullQuery(User $user): Builder
    {
        return $this->model()::withTrashed()->where(
            fn (Builder $q) => $q->where('owner_id', $user->id)->orWhereNull('owner_id')
        );
    }

    public function document(Model $model, User $user): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'is_public' => $model->owner_id === null,
            ...$this->extraFields($model),
            'version' => $model->version,
            'updated_at' => $model->syncTimestamp(),
            '_deleted' => $model->trashed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraFields(Model $model): array
    {
        return [];
    }

    /**
     * Attributes of a pushed document.
     *
     * @return array<string, mixed>
     */
    protected function attributes(array $new): array
    {
        return ['name' => $new['name']];
    }

    protected function pushRow(User $user, ?array $assumed, array $new): ?array
    {
        $entry = $this->findForUpdate($this->model(), $new['id']);

        if ($entry === null) {
            if (! empty($new['_deleted'])) {
                return null;
            }

            $entry = new ($this->model())($this->attributes($new));
            $entry->id = $new['id'];
            $entry->owner()->associate($user);
            $entry->save();

            return null;
        }

        if ($entry->owner_id !== null && $entry->owner_id !== $user->id) {
            return null;
        }

        // Public entries are read-only: reset the client to the server state.
        if ($entry->owner_id === null || $entry->trashed() || $this->isConflict($entry, $assumed)) {
            return $this->document($entry, $user);
        }

        $entry->version = $this->nextVersion($entry, $new);

        if (! empty($new['_deleted'])) {
            $entry->save();
            $entry->delete();

            return null;
        }

        $entry->fill($this->attributes($new))->save();

        return null;
    }
}
