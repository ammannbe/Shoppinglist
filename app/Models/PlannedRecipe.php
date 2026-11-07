<?php

namespace App\Models;

use App\Support\FoodNames;
use App\Support\Seasons;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planner suggestion the user planned or put on a shopping list (Recipes tab), see PlannedRecipeController.
 * `planned`: planned, not on a list yet (the planner's list step builds the list from these);
 * `planned_at`: when it was planned, the date of the offers in `uses_offers`; `used_at`: last
 * time it went into a shopping list.
 */
#[Fillable(['key', 'source', 'recipe_id', 'name', 'url', 'servings', 'data', 'draft_url', 'planned', 'planned_at', 'used_at'])]
class PlannedRecipe extends Model
{
    use HasUuids;

    /** Entries kept per user, the oldest ones are removed. */
    public const LIMIT = 200;

    /** Fields of a suggestion kept in `data`. */
    public const DATA = ['reason', 'uses_offers', 'uses_pantry', 'uses_season', 'ingredients', 'instructions', 'category', 'complexity'];

    protected function casts(): array
    {
        return ['data' => 'array', 'planned' => 'boolean', 'planned_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Saves planner suggestions (when planned, imported into the recipe app or a list is built
     * from them): own recipes once per recipe id (moved to the top again), AI ideas by their key.
     * Fields not sent keep their saved value. `planned` plans them (now). Building a list does not
     * change the plan: the recipes are on a list once its items are added (markUsed).
     *
     * @param  list<array<string, mixed>>  $suggestions  validated by StorePlannedRecipeRequest::recipe()
     * @return list<self>
     */
    public static function remember(User $user, array $suggestions, bool $planned = false): array
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
            if ($planned) {
                $recipe->planned = true;
                $recipe->planned_at = now();
            }
            $recipe->user()->associate($user);
            $recipe->touch();
            $saved[] = $recipe;
        }

        // Planned recipes are kept, the oldest others make room.
        $planned = self::where('user_id', $user->id)->where('planned', true)->count();
        $old = self::where('user_id', $user->id)->where('planned', false)->latest('updated_at')
            ->skip(max(0, self::LIMIT - $planned))->take(PHP_INT_MAX)->pluck('id');
        if ($old->isNotEmpty()) {
            self::whereIn('id', $old)->delete();
        }

        return $saved;
    }

    /**
     * The planner's items built from these recipes were added to a shopping list: they are on a
     * list now (`used_at` = when they were added, sent by the client, never in the future) and no
     * longer planned. Only the user's own recipes; sending the same ids again (a retry) gives the
     * same result, and a late retry does not unplan a recipe planned again after it was added.
     *
     * @param  list<string>  $ids
     * @return list<self>
     */
    public static function markUsed(User $user, array $ids, ?CarbonInterface $at = null): array
    {
        $at = $at === null || $at->isFuture() ? now() : $at;
        $recipes = self::where('user_id', $user->id)->whereIn('id', $ids)->get();
        foreach ($recipes as $recipe) {
            if ($recipe->used_at === null || $recipe->used_at->lt($at)) {
                $recipe->used_at = $at;
            }
            if ($recipe->planned_at === null || $recipe->planned_at->lte($at)) {
                $recipe->planned = false;
            }
            $recipe->planned_at ??= $at;
            $recipe->save();
        }

        return $recipes->all();
    }

    /**
     * Plans the recipe again (Recipes tab): the offers, pantry items and foods in season it uses
     * are taken from the current ones by the ingredient names (FoodNames, Seasons::among, no AI).
     *
     * @param  list<string>  $offers  names of the selected offers
     * @param  list<string>  $pantry  names of the selected pantry items
     * @param  list<string>  $seasonal  foods in season this month
     */
    public function planAgain(array $offers, array $pantry, array $seasonal): void
    {
        $ingredients = array_column($this->data['ingredients'] ?? [], 'name');
        $this->data = [
            ...$this->data,
            'uses_offers' => FoodNames::used($ingredients, $offers),
            'uses_pantry' => FoodNames::used($ingredients, $pantry),
            'uses_season' => Seasons::among($ingredients, $seasonal),
        ];
        $this->planned = true;
        $this->planned_at = now();
        $this->save();
    }

    /**
     * The owner, and members of a list with an item linked to the recipe (`items.recipes[].key`),
     * e.g. a recipe somebody else planned for a shared list.
     */
    public function readableBy(User $user): bool
    {
        return $this->user_id === $user->id || self::linkedInListsOf($user, [$this->id]) !== [];
    }

    /**
     * The ones of `$keys` (planned recipe ids) an item in the user's lists links to.
     *
     * @param  list<string>  $keys  uuids
     * @return list<string>
     */
    public static function linkedInListsOf(User $user, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        // The JSON column is searched as text (MariaDB and SQLite), the decoded keys decide.
        $linked = Item::whereIn('shopping_list_id', $user->accessibleListIds())
            ->where(function ($query) use ($keys) {
                foreach ($keys as $key) {
                    $query->orWhere('recipes', 'like', '%"key":"'.$key.'"%');
                }
            })
            ->pluck('recipes')->flatten(1)->pluck('key')->filter()->all();

        return array_values(array_intersect($keys, $linked));
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
            'planned' => (bool) $this->planned,
            'planned_at' => $this->planned_at?->toIso8601String(),
            'used_at' => $this->used_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * Read only for other members of a list linked to the recipe: without the owner's import link.
     *
     * @return array<string, mixed>
     */
    public function toShared(): array
    {
        return [...collect($this->toClient())->except(['draft_url', 'planned', 'used_at'])->all(), 'shared' => true];
    }
}
