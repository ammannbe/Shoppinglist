<?php

namespace App\Sync;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends CatalogResource
{
    protected function model(): string
    {
        return Product::class;
    }

    /**
     * The image is set by the upload (ImageController), pushes never change it.
     */
    protected function extraFields(Model $model): array
    {
        return ['image' => $model->image, 'category' => $model->category];
    }

    /**
     * Clients from before the categories send none: the stored category stays.
     */
    protected function attributes(array $new): array
    {
        return [
            ...parent::attributes($new),
            ...(array_key_exists('category', $new) ? ['category' => $new['category']] : []),
        ];
    }
}
