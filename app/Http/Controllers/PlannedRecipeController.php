<?php

namespace App\Http\Controllers;

use App\Models\PlannedRecipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * History of the recipes the week planner suggested (#/recipes): plan or cook them again,
 * import AI ideas into the recipe app later. Saved by PlannerController.
 */
class PlannedRecipeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['recipes' => PlannedRecipe::where('user_id', $request->user()->id)
            ->latest('updated_at')->limit(PlannedRecipe::LIMIT)->get()
            ->map(fn (PlannedRecipe $recipe) => $recipe->toClient())]);
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
