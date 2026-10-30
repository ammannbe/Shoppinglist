<?php

namespace App\Sync;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StoreResource extends CatalogResource
{
    /** Own shop icons: a small raster image as data URL (the client scales it to 64 px). */
    public const ICON_PATTERN = '/^data:image\/(webp|png|jpeg);base64,[A-Za-z0-9+\/]+={0,2}$/';

    public const ICON_MAX = 20_000;

    protected function model(): string
    {
        return Store::class;
    }

    protected function extraFields(Model $model): array
    {
        return ['code' => $model->code, 'icon' => $model->icon, 'countries' => $model->countries ?? []];
    }

    protected function attributes(array $new): array
    {
        // Optional and kept as typed; without a code the items show the name.
        $code = trim((string) ($new['code'] ?? ''));
        $icon = $new['icon'] ?? null;

        return [
            ...parent::attributes($new),
            'code' => $code !== '' ? Str::limit($code, 10, '') : null,
            // Standard shop icons are files, own ones only small images (validated in SyncController).
            'icon' => is_string($icon) && preg_match(self::ICON_PATTERN, $icon) ? $icon : null,
        ];
    }
}
