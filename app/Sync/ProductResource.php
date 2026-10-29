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
        return ['image' => $model->image];
    }
}
