<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlannedRecipeRequest;
use App\Models\PlannedRecipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Recipes tab (#/recipes): planner suggestions the user saved ("Save") or put on a shopping list
 * (PlannerController::list). Plan or cook them again, import AI ideas into the recipe app later.
 */
class PlannedRecipeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['recipes' => PlannedRecipe::where('user_id', $request->user()->id)
            ->latest('updated_at')->limit(PlannedRecipe::LIMIT)->get()
            ->map(fn (PlannedRecipe $recipe) => $recipe->toClient())]);
    }

    public function store(StorePlannedRecipeRequest $request): JsonResponse
    {
        [$recipe] = PlannedRecipe::remember($request->user(), [$request->validated()]);

        return response()->json(['recipe' => $recipe->toClient()], $recipe->wasRecentlyCreated ? 201 : 200);
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
