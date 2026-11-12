<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product suggestions: public ones (no owner) and the user's own. `category`: key of
 * App\Support\ProductCategories (null: none; an own copy of a standard product uses the standard one).
 */
#[Fillable(['name', 'category'])]
class Product extends Model
{
    use Syncable;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
