<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'amount', 'unit', 'stores', 'note', 'recipes', 'image', 'done', 'position'])]
class Item extends Model
{
    use HasFactory, Syncable;

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'done' => 'boolean',
            'position' => 'float',
            'stores' => 'array',
            'recipes' => 'array',
        ];
    }

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
