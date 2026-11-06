<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planner suggestion the user saved or put on a shopping list (Recipes tab), see PlannedRecipeController.
 */
#[Fillable(['key', 'source', 'recipe_id', 'name', 'url', 'servings', 'data', 'draft_url', 'used_at'])]
class PlannedRecipe extends Model
{
    use HasUuids;

    /** Entries kept per user, the oldest ones are removed. */
    public const LIMIT = 200;

    /** Fields of a suggestion kept in `data`. */
    public const DATA = ['reason', 'uses_offers', 'uses_pantry', 'uses_season', 'ingredients', 'instructions', 'category', 'complexity'];

    protected function casts(): array
    {
        return ['data' => 'array', 'used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Saves planner suggestions (on "Save" and when they go into a shopping list): own recipes
     * once per recipe id (moved to the top again), AI ideas by their key. Fields not sent keep
     * their saved value.
     *
     * @param  list<array<string, mixed>>  $suggestions  validated by StorePlannedRecipeRequest::recipe()
     * @return list<self>
     */
    public static function remember(User $user, array $suggestions, bool $used = false): array
    {
        $saved = [];
        foreach ($suggestions as $suggestion) {
            $own = $suggestion['source'] === 'recipes';
            $recipe = self::firstOrNew(['user_id' => $user->id, 'key' => $own ? 'recipe-'.$suggestion['recipe_id'] : $suggestion['key']]);
            $recipe->fill([
                'source' => $suggestion['source'],
                'recipe_id' => $own ? $suggestion['recipe_id'] : null,
                'name' => mb_substr($suggestion['name'], 0, 255),
                'url' => $own ? ($suggestion['url'] ?? $recipe->url) : null,
                'servings' => isset($suggestion['servings']) ? (int) round($suggestion['servings']) : $recipe->servings,
                'data' => [...$recipe->data ?? [], ...array_intersect_key($suggestion, array_flip(self::DATA))],
            ]);
            if ($used) {
                $recipe->used_at = now();
            }
            $recipe->user()->associate($user);
            $recipe->touch();
            $saved[] = $recipe;
        }

        $old = self::where('user_id', $user->id)->latest('updated_at')->skip(self::LIMIT)->take(PHP_INT_MAX)->pluck('id');
        if ($old->isNotEmpty()) {
            self::whereIn('id', $old)->delete();
        }

        return $saved;
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
