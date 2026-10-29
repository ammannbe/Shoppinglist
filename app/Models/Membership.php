<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('shopping_list_user')]
#[Fillable(['shopping_list_id', 'user_id'])]
class Membership extends Model
{
    use Syncable;

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
