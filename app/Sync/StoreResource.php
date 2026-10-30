<?php

namespace App\Sync;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StoreResource extends CatalogResource
{
    protected function model(): string
    {
        return Store::class;
    }

    protected function extraFields(Model $model): array
    {
        return ['code' => $model->code];
    }

    protected function attributes(array $new): array
    {
        // Optional and kept as typed; without a code the items show the name.
        $code = trim((string) ($new['code'] ?? ''));

        return [
            ...parent::attributes($new),
            'code' => $code !== '' ? Str::limit($code, 10, '') : null,
        ];
    }
}
