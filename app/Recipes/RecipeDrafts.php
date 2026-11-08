<?php

namespace App\Recipes;

use App\Models\PlannedRecipe;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/**
 * AI ideas handed to the recipe app as drafts (PlannerController::saveRecipe stores `draft_id`):
 * once the user imported one there, its entry becomes the own recipe (PlannedRecipe::becomeOwnRecipe).
 * Asked for when the planned recipes are loaded (GET /planned-recipes), at most every CHECK_SECONDS
 * per user. Failures are ignored (asked again next time). The recipe app's
 * GET /api/recipe-drafts/{id} (RecipeManager AGENTS.md); older versions answer 404, then the
 * entry stays as it is and is no longer asked for after the draft's expiry.
 */
class RecipeDrafts
{
    public const CHECK_SECONDS = 20;

    /** Drafts asked for per check (the most recently changed ones). */
    public const LIMIT = 10;

    /** Asked for this long after the draft's expiry at most (the recipe app keeps imported drafts 30 days). */
    public const ASK_DAYS = 30;

    public function __construct(private RecipesClient $recipes) {}

    public function resolve(User $user): void
    {
        if (! RecipesClient::connected($user)) {
            return;
        }

        $pending = PlannedRecipe::where('user_id', $user->id)
            ->whereNotNull('draft_id')
            ->where(fn ($query) => $query->whereNull('draft_expires_at')->orWhere('draft_expires_at', '>', now()->subDays(self::ASK_DAYS)))
            ->latest('updated_at')
            ->limit(self::LIMIT)
            ->get();

        if ($pending->isEmpty() || ! Cache::add('recipe-drafts:'.$user->id, true, self::CHECK_SECONDS)) {
            return;
        }

        foreach ($pending as $recipe) {
            try {
                $draft = $this->recipes->get($user, '/api/recipe-drafts/'.$recipe->draft_id, timeout: 5)['data'] ?? [];
            } catch (RecipesException $e) {
                if ($e->getCode() !== 404) {
                    // Recipe app down or the connection gone: next time.
                    return;
                }
                // Unknown draft (an older recipe app, or pruned): nothing changes; after its expiry it cannot be imported anymore.
                if ($recipe->draft_expires_at === null || $recipe->draft_expires_at->isPast()) {
                    $recipe->update(['draft_id' => null, 'draft_expires_at' => null]);
                }

                continue;
            } catch (ConnectionException) {
                return;
            }

            $imported = $draft['recipe'] ?? null;
            match ($draft['status'] ?? null) {
                'imported' => is_array($imported) && isset($imported['id'], $imported['name'])
                    ? $this->link($user, $recipe, $imported)
                    // Imported, but the recipe is gone: it can be handed over again.
                    : $this->forget($recipe),
                'expired' => $this->forget($recipe),
                default => null,
            };
        }
    }

    /**
     * @param  array{id: int, name: string, url?: string|null}  $imported
     */
    private function link(User $user, PlannedRecipe $recipe, array $imported): void
    {
        $ingredients = null;
        try {
            $details = $this->recipes->get($user, '/api/recipes/'.$imported['id'], array_filter(['servings' => $recipe->servings]), timeout: 5)['data'] ?? [];
            $ingredients = RecipeIngredients::forSaving($details['ingredients'] ?? []);
        } catch (RecipesException|ConnectionException) {
            // Loaded when needed (building the list, planning again).
        }

        $recipe->becomeOwnRecipe((int) $imported['id'], (string) $imported['name'], $imported['url'] ?? null, $ingredients);
    }

    /** The draft cannot be imported anymore: the idea can be handed over again. */
    private function forget(PlannedRecipe $recipe): void
    {
        $recipe->update(['draft_id' => null, 'draft_expires_at' => null, 'draft_url' => null]);
    }
}
