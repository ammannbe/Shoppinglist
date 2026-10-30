<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recipe the week planner suggested (history), see PlannedRecipeController.
 */
#[Fillable(['key', 'source', 'recipe_id', 'name', 'url', 'servings', 'data', 'draft_url', 'used_at'])]
class PlannedRecipe extends Model
{
    use HasUuids;

    /** Entries kept per user, the oldest ones are removed. */
    public const LIMIT = 200;

    /** Fields of a suggestion kept in `data`. */
    public const DATA = ['reason', 'uses_offers', 'uses_pantry', 'ingredients', 'instructions', 'category', 'complexity'];

    protected function casts(): array
    {
        return ['data' => 'array', 'used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Saves the suggestions of the planner: own recipes once (moved to the top again), AI ideas each.
     *
     * @param  list<array<string, mixed>>  $suggestions
     */
    public static function remember(User $user, array $suggestions): void
    {
        foreach ($suggestions as $suggestion) {
            $recipe = self::firstOrNew(['user_id' => $user->id, 'key' => $suggestion['key']]);
            $recipe->fill([
                'source' => $suggestion['source'],
                'recipe_id' => $suggestion['recipe_id'] ?? null,
                'name' => mb_substr($suggestion['name'], 0, 255),
                'url' => $suggestion['url'] ?? null,
                'servings' => $suggestion['servings'] ?? $recipe->servings,
                'data' => array_intersect_key($suggestion, array_flip(self::DATA)),
            ]);
            $recipe->user()->associate($user);
            $recipe->touch();
        }

        $old = self::where('user_id', $user->id)->latest('updated_at')->skip(self::LIMIT)->take(PHP_INT_MAX)->pluck('id');
        if ($old->isNotEmpty()) {
            self::whereIn('id', $old)->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toClient(): array
    {
        return [
            ...$this->data,
            'id' => $this->id,
            'key' => $this->key,
            'source' => $this->source,
            'recipe_id' => $this->recipe_id,
            'name' => $this->name,
            'url' => $this->url,
            'servings' => $this->servings,
            'draft_url' => $this->draft_url,
            'used_at' => $this->used_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
