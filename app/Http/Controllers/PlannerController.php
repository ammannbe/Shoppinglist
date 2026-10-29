<?php

namespace App\Http\Controllers;

use App\Ai\AiException;
use App\Ai\Assistant;
use App\Http\Requests\PlannerListRequest;
use App\Http\Requests\PlannerSuggestionsRequest;
use App\Recipes\RecipesClient;
use App\Recipes\RecipesException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Week planner: offers and pantry are read by the AI, recipes come from the connected
 * recipe app and/or the AI, the result is a shopping list the client stores locally.
 * Stateless: the client sends offers and pantry back with every step.
 */
class PlannerController extends Controller
{
    public function __construct(private Assistant $assistant, private RecipesClient $recipes) {}

    public function offers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['nullable', 'required_without:text', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,application/pdf', 'max:20480'],
            'text' => ['nullable', 'required_without:file', 'string', 'max:20000'],
        ]);

        return $this->answer(fn () => ['offers' => $this->assistant->readOffers($request->file('file'), $validated['text'] ?? null, $this->language())]);
    }

    public function pantry(Request $request): JsonResponse
    {
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            'photos.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,image/heic', 'max:10240'],
        ]);

        return $this->answer(fn () => ['items' => $this->assistant->readPantry($request->file('photos'), $this->language())]);
    }

    public function suggestions(PlannerSuggestionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $offers = $data['offers'] ?? [];
        $pantry = $data['pantry'] ?? [];
        $user = $request->user();

        return $this->answer(function () use ($data, $offers, $pantry, $user) {
            [$candidates, $urls, $warning] = $this->candidates($user, $offers, $pantry);
            $result = $this->assistant->suggest(
                $offers, $pantry, $candidates, (int) $data['servings'], (int) $data['count'],
                (bool) $data['ideas'] || $candidates === [], $data['wishes'] ?? null, $this->language(),
            );
            $names = array_column($candidates, 'name', 'id');

            $suggestions = [
                ...array_map(fn (array $pick) => [
                    'key' => 'recipe-'.$pick['recipe_id'],
                    'source' => 'recipes',
                    'recipe_id' => $pick['recipe_id'],
                    'name' => $names[$pick['recipe_id']],
                    'url' => $urls[$pick['recipe_id']] ?? null,
                    'reason' => $pick['reason'],
                    'uses_offers' => $pick['uses_offers'],
                    'uses_pantry' => $pick['uses_pantry'],
                ], $result['picks']),
                ...array_map(fn (array $idea, int $i) => [
                    'key' => 'idea-'.$i,
                    'source' => 'ai',
                    ...$idea,
                ], $result['ideas'], array_keys($result['ideas'])),
            ];

            return ['suggestions' => $suggestions, 'warning' => $warning];
        });
    }

    public function list(PlannerListRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $this->answer(function () use ($data, $user) {
            $recipes = [];
            foreach ($data['recipes'] as $recipe) {
                if ($recipe['source'] === 'recipes') {
                    try {
                        $details = $this->recipes->get($user, '/api/recipes/'.$recipe['recipe_id'], ['servings' => $recipe['servings']])['data'] ?? [];
                    } catch (RecipesException $e) {
                        throw new AiException(__('The recipes of :name could not be loaded.', ['name' => config('services.recipes.name')]), previous: $e);
                    }
                    $recipes[] = [
                        'name' => $details['name'] ?? $recipe['name'],
                        'servings' => $recipe['servings'],
                        'ingredients' => array_map(fn (array $ingredient) => [
                            'amount' => $ingredient['amount'] ?? null,
                            'unit' => $ingredient['unit'] ?? null,
                            'name' => $ingredient['food'] ?? $ingredient['text'] ?? '',
                        ], $details['ingredients'] ?? []),
                    ];
                } else {
                    $recipes[] = ['name' => $recipe['name'], 'servings' => $recipe['servings'], 'ingredients' => $recipe['ingredients'] ?? []];
                }
            }

            return ['items' => $this->assistant->shoppingList($recipes, $data['offers'] ?? [], $data['pantry'] ?? [], $this->language())];
        });
    }

    /**
     * Save an AI recipe idea in the recipe app.
     */
    public function saveRecipe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'servings' => ['required', 'integer', 'min:1', 'max:100'],
            'instructions' => ['required', 'string', 'max:20000'],
            'ingredients' => ['required', 'array', 'min:1', 'max:100'],
            'ingredients.*.name' => ['required', 'string', 'max:255'],
            'ingredients.*.amount' => ['nullable', 'numeric', 'min:0'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $result = $this->recipes->post($request->user(), '/api/recipes', [
                ...$data,
                'ingredients' => array_map(fn (array $i) => ['amount' => $i['amount'] ?? null, 'unit' => $i['unit'] ?? null, 'food' => $i['name']], $data['ingredients']),
            ]);
        } catch (RecipesException $e) {
            report($e);

            return response()->json(['message' => __('The recipe could not be saved in :name.', ['name' => config('services.recipes.name')])], 502);
        }

        return response()->json(['url' => $result['data']['url'] ?? null]);
    }

    /**
     * Recipes of the recipe app that use the offers or the pantry; recipes using several offers come first.
     *
     * @return array{0: list<array{id: int, name: string, foods: list<string>, offers: list<string>, pantry: list<string>}>, 1: array<int, string>, 2: ?string}
     */
    private function candidates($user, array $offers, array $pantry): array
    {
        if (! RecipesClient::connected($user)) {
            return [[], [], null];
        }

        try {
            $foods = $this->recipes->get($user, '/api/foods')['data'] ?? [];
            $offerNames = array_values(array_unique(array_column($offers, 'name')));
            $pantryNames = array_values(array_unique(array_column($pantry, 'name')));
            $matches = $this->assistant->matchFoods(array_values(array_unique([...$offerNames, ...$pantryNames])), $foods);
            $offerFoods = array_filter(array_intersect_key($matches, array_flip($offerNames)));
            $pantryFoods = array_filter(array_intersect_key($matches, array_flip($pantryNames)));
            if ($offerFoods === [] && $pantryFoods === []) {
                return [[], [], null];
            }

            // The recipe app ranks by matched foods: search the offers on their own so recipes
            // combining several offers are not pushed out by pantry matches, then add pantry recipes.
            $found = [];
            foreach ([[$offerFoods, 25], [$pantryFoods, $offerFoods === [] ? 30 : 10]] as [$ids, $limit]) {
                $ids = array_values(array_unique($ids));
                if ($ids !== []) {
                    $found = [...$found, ...$this->recipes->post($user, '/api/recipes/search', ['food_ids' => array_slice($ids, 0, 200), 'limit' => $limit])['data'] ?? []];
                }
            }
            $found = collect($found)->unique('id')->values()->all();
        } catch (RecipesException $e) {
            report($e);

            return [[], [], __('The recipes of :name could not be loaded.', ['name' => config('services.recipes.name')])];
        }

        $foodNames = array_column($foods, 'name', 'id');
        $using = fn (array $recipe, array $products) => array_keys(array_filter(
            $products, fn ($foodId) => in_array($foodId, $recipe['food_ids'] ?? [], true),
        ));

        return [
            collect($found)
                ->map(fn (array $recipe) => [
                    'id' => $recipe['id'],
                    'name' => $recipe['name'],
                    'foods' => array_values(array_filter(array_map(fn ($id) => $foodNames[$id] ?? null, $recipe['food_ids'] ?? []))),
                    'offers' => array_map('strval', $using($recipe, $offerFoods)),
                    'pantry' => array_map('strval', $using($recipe, $pantryFoods)),
                ])
                ->sortByDesc(fn (array $recipe) => count($recipe['offers']) * 100 + count($recipe['pantry']))
                ->values()
                ->all(),
            array_column($found, 'url', 'id'),
            null,
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $work
     */
    private function answer(callable $work): JsonResponse
    {
        // The AI may need longer than the default limit.
        set_time_limit(240);

        try {
            return response()->json($work());
        } catch (AiException $e) {
            return response()->json(['message' => __($e->getMessage())], 503);
        }
    }

    private function language(): string
    {
        return str_starts_with(app()->getLocale(), 'de')
            ? 'Swiss Standard German (use "ss" instead of "ß", Swiss terms like Rüebli, Poulet, Rahm)'
            : 'English';
    }
}
