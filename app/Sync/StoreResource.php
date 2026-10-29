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
        $code = Str::upper(trim((string) ($new['code'] ?? '')));

        return [
            ...parent::attributes($new),
            // Without a code the start of the name, like the client does.
            'code' => Str::limit($code !== '' ? $code : Str::upper(Str::substr(trim($new['name']), 0, 3)), 10, ''),
        ];
    }
}
