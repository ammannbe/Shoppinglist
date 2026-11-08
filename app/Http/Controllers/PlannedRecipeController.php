<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlannedRecipeRequest;
use App\Models\Offer;
use App\Models\PlannedRecipe;
use App\Recipes\RecipeDrafts;
use App\Recipes\RecipeIngredients;
use App\Recipes\RecipesClient;
use App\Recipes\RecipesException;
use App\Support\Seasons;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Recipes tab (#/recipes): planner suggestions the user planned ("Plan", step 3) or put on a
 * shopping list (PlannerController::list builds the items from the planned ones, `used` marks
 * them once the items are added). Plan or cook them
 * again, import AI ideas into the recipe app later.
 */
class PlannedRecipeController extends Controller
{
    public function index(Request $request, RecipeDrafts $drafts): JsonResponse
    {
        // AI ideas imported in the recipe app meanwhile become the own recipes (throttled, failures ignored).
        $drafts->resolve($request->user());

        return response()->json(['recipes' => PlannedRecipe::where('user_id', $request->user()->id)
            ->latest('updated_at')->limit(PlannedRecipe::LIMIT)->get()
            ->map(fn (PlannedRecipe $recipe) => $recipe->toClient())]);
    }

    public function store(StorePlannedRecipeRequest $request): JsonResponse
    {
        [$recipe] = PlannedRecipe::remember($request->user(), [$request->validated()], planned: true);

        return response()->json(['recipe' => $recipe->toClient()], $recipe->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Plans a saved recipe again ("Plan again"): the offers, pantry items and foods in season it
     * uses are matched anew with the planner's current selection (by name, no AI request). Own
     * recipes without saved ingredients get them from the recipe app first.
     */
    public function plan(Request $request, PlannedRecipe $plannedRecipe, RecipesClient $recipes): JsonResponse
    {
        $user = $request->user();
        abort_unless($plannedRecipe->user_id === $user->id, 403);
        $data = $request->validate([
            'offer_ids' => ['nullable', 'array', 'max:500'],
            'offer_ids.*' => ['uuid'],
            'pantry' => ['nullable', 'array', 'max:200'],
            'pantry.*' => ['string', 'max:255'],
        ]);

        $foods = null;
        if (RecipesClient::connected($user)) {
            try {
                if ($plannedRecipe->source === 'recipes' && empty($plannedRecipe->data['ingredients']) && $plannedRecipe->recipe_id) {
                    $details = $recipes->get($user, '/api/recipes/'.$plannedRecipe->recipe_id, array_filter(['servings' => $plannedRecipe->servings]))['data'] ?? [];
                    $plannedRecipe->data = [...$plannedRecipe->data, 'ingredients' => RecipeIngredients::forSaving($details['ingredients'] ?? [])];
                }
                // Season data of the recipe app, like the suggestions.
                $foods = $recipes->get($user, '/api/foods')['data'] ?? null;
            } catch (RecipesException|ConnectionException $e) {
                // Matched with what is known (and the season list of the app).
                report($e);
            }
        }

        $offers = Offer::visibleTo($user)->whereIn('id', $data['offer_ids'] ?? [])->orderBy('store')->orderBy('name')->pluck('name')->unique()->values()->all();
        $plannedRecipe->planAgain($offers, array_values($data['pantry'] ?? []), Seasons::names(Seasons::month(), $foods));

        return response()->json(['recipe' => $plannedRecipe->toClient()]);
    }

    /**
     * The planner added the items built from these recipes to a shopping list: they are on a list
     * now, no longer planned. Sent after adding (the client retries if offline), `added_at` = ms of
     * adding, so a retry gives the same result. Ids of others are ignored.
     */
    public function used(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.PlannedRecipe::LIMIT],
            'ids.*' => ['uuid'],
            'added_at' => ['nullable', 'integer', 'min:0'],
        ]);
        $at = isset($data['added_at']) ? Carbon::createFromTimestampMs($data['added_at']) : null;

        return response()->json(['recipes' => array_map(
            fn (PlannedRecipe $recipe) => $recipe->toClient(),
            PlannedRecipe::markUsed($request->user(), array_values(array_unique($data['ids'])), $at),
        )]);
    }

    /** Takes a recipe out of the plan (it stays saved). */
    public function unplan(Request $request, PlannedRecipe $plannedRecipe): JsonResponse
    {
        abort_unless($plannedRecipe->user_id === $request->user()->id, 403);
        $plannedRecipe->update(['planned' => false]);

        return response()->json(['recipe' => $plannedRecipe->toClient()]);
    }

    /**
     * One recipe, for the recipe links of items (#/recipes/<id>): own ones, and read only the ones
     * of others linked by an item in a list the user can access (also through a household).
     */
    public function show(Request $request, PlannedRecipe $plannedRecipe): JsonResponse
    {
        $user = $request->user();
        abort_unless($plannedRecipe->readableBy($user), 404);

        return response()->json(['recipe' => $plannedRecipe->user_id === $user->id ? $plannedRecipe->toClient() : $plannedRecipe->toShared()]);
    }

    public function destroy(Request $request, PlannedRecipe $plannedRecipe): Response
    {
        abort_unless($plannedRecipe->user_id === $request->user()->id, 403);
        $plannedRecipe->delete();

        return response()->noContent();
    }

    public function destroyAll(Request $request): Response
    {
        PlannedRecipe::where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }
}
