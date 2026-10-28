<?php

namespace App\Sync;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pull only, units are managed on the server.
 */
class UnitResource extends SyncResource
{
    protected function pullQuery(User $user): Builder
    {
        return Unit::withTrashed();
    }

    /**
     * @param  Unit  $model
     */
    public function document(Model $model, User $user): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'updated_at' => $model->syncTimestamp(),
            '_deleted' => $model->trashed(),
        ];
    }

    protected function pushRow(User $user, ?array $assumed, array $new): ?array
    {
        $unit = Unit::withTrashed()->find($new['id']);

        return $unit ? $this->document($unit, $user) : null;
    }
}
