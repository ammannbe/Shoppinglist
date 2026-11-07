<?php

namespace App\Http\Controllers;

use App\Ai\AiException;
use App\Ai\Assistant;
use App\Http\Requests\PlannerListRequest;
use App\Http\Requests\PlannerSuggestionsRequest;
use App\Models\PlannedRecipe;
use App\Recipes\RecipeIngredients;
use App\Recipes\RecipesClient;
use App\Recipes\RecipesException;
use App\Support\Seasons;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            // Share the offers with all users (default for clients that do not send it).
            'share' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $shared = $request->boolean('share', true);
        // Offers of a regional shop without a region in the material are the user's region's
        // (shop pages show the offers of the region chosen there).
        $regions = (array) ($user->preferences['regions'] ?? []);

        // The offers are saved in the pool (#/offers), the planner gets them with their ids.
        return $this->answer(fn () => ['offers' => array_map(
            fn (array $offer) => OfferController::save($user, [
                ...$offer,
                'region' => $offer['region'] ?? $regions[$offer['store'] ?? ''] ?? null,
                'shared' => $shared,
            ])->toClient($user),
            $this->assistant->readOffers($request->file('file'), $validated['text'] ?? null, $this->language()),
        )]);
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
            $month = Seasons::month();
            [$candidates, $urls, $warning, $foods] = $this->candidates($user, $offers, $pantry, $month);
            $seasonal = Seasons::names($month, $foods);
            $excludedIds = $data['exclude']['recipe_ids'] ?? [];
            $candidates = array_values(array_filter($candidates, fn (array $candidate) => ! in_array($candidate['id'], $excludedIds, true)));
            $result = $this->assistant->suggest(
                $offers, $pantry, $candidates, (int) $data['servings'], (int) $data['count'],
                (bool) $data['ideas'] || $candidates === [], $data['wishes'] ?? null, $this->language(),
                $this->vocabulary($user), $data['exclude']['names'] ?? [], $seasonal,
            );
            $names = array_column($candidates, 'name', 'id');
            $seasonOf = array_column($candidates, 'season', 'id');

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
                    'uses_season' => $seasonOf[$pick['recipe_id']] ?? [],
                ], $result['picks']),
                // Unique keys: "suggest more" appends to the shown suggestions.
                ...array_map(fn (array $idea) => [
                    'key' => 'idea-'.Str::uuid7(),
                    'source' => 'ai',
                    ...$idea,
                    'uses_season' => Seasons::among(array_column($idea['ingredients'] ?? [], 'name'), $seasonal),
                ], $result['ideas']),
            ];

            // Not saved: the user saves the ones to keep (POST /planned-recipes), the ones a list is built from are saved then.
            return ['suggestions' => $suggestions, 'warning' => $warning];
        });
    }

    public function list(PlannerListRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $this->answer(function () use ($data, $user) {
            $recipes = [];
            $loaded = [];
            foreach ($data['recipes'] as $index => $recipe) {
                if ($recipe['source'] === 'recipes') {
                    try {
                        $details = $this->recipes->get($user, '/api/recipes/'.$recipe['recipe_id'], ['servings' => $recipe['servings']])['data'] ?? [];
                    } catch (RecipesException $e) {
                        throw new AiException(__('The recipes of :name could not be loaded.', ['name' => config('services.recipes.name')]), previous: $e);
                    }
                    $loaded[$index] = $details['ingredients'] ?? [];
                    $recipes[$index] = ['name' => $details['name'] ?? $recipe['name'], 'servings' => $recipe['servings']];
                } else {
                    $recipes[$index] = ['name' => $recipe['name'], 'servings' => $recipe['servings'], 'ingredients' => $recipe['ingredients'] ?? []];
                }
            }

            foreach (RecipeIngredients::unify($loaded) as $index => $ingredients) {
                $recipes[$index]['ingredients'] = $ingredients;
            }

            // The items name the recipes as the AI got them: the suggestion's name again (the
            // client links the items to the saved recipes by it).
            $names = [];
            foreach ($recipes as $index => $recipe) {
                $names[mb_strtolower($recipe['name'])] = $data['recipes'][$index]['name'];
            }
            $items = array_map(fn (array $item) => [
                ...$item,
                'recipes' => array_values(array_unique(array_map(fn (string $name) => $names[mb_strtolower($name)] ?? $name, $item['recipes'] ?? []))),
            ], $this->assistant->shoppingList(array_values($recipes), $data['offers'] ?? [], $data['pantry'] ?? [], $this->language()));

            // The recipes are saved (Recipes tab), own recipes with the ingredients loaded above.
            // They stay planned: they are on a list once the client added the items (POST
            // /planned-recipes/used). Recipes without key (older clients) are not saved.
            $saving = array_filter($data['recipes'], fn (array $recipe) => filled($recipe['key'] ?? null));
            foreach (array_keys($saving) as $index) {
                if (isset($loaded[$index])) {
                    $saving[$index]['ingredients'] = RecipeIngredients::forSaving($loaded[$index]);
                }
            }
            $saved = PlannedRecipe::remember($user, array_values($saving));

            return [
                'items' => $items,
                // Ids of the saved recipes: the items link to them (`items.recipes[].key`).
                'recipes' => array_map(fn (array $recipe, PlannedRecipe $planned) => ['key' => $recipe['key'], 'id' => $planned->id], array_values($saving), $saved),
            ];
        });
    }

    /**
     * Hand an AI recipe idea to the recipe app as a draft. The user checks and imports it there
     * (matching foods, units and the category), so nothing is created without a person.
     * An idea with a key is saved first if it is not yet (Recipes tab), so the import link is kept.
     */
    public function saveRecipe(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Saved entry of the idea (gets the import link, saved now if it is not yet).
            'key' => ['nullable', 'string', 'max:100', 'regex:/^idea-[A-Za-z0-9-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'servings' => ['required', 'integer', 'min:1', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
            'complexity' => ['nullable', 'string', 'max:20'],
            'instructions' => ['required', 'string', 'max:20000'],
            'ingredients' => ['required', 'array', 'min:1', 'max:100'],
            'ingredients.*.name' => ['required', 'string', 'max:50'],
            'ingredients.*.amount' => ['nullable', 'numeric', 'min:0'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:20'],
            // Only kept when the idea is saved here.
            'reason' => ['nullable', 'string', 'max:1000'],
            ...collect(['uses_offers', 'uses_pantry', 'uses_season'])->flatMap(fn (string $field) => [
                $field => ['nullable', 'array', 'max:50'],
                $field.'.*' => ['string', 'max:255'],
            ])->all(),
        ]);
        $user = $request->user();

        try {
            $result = $this->recipes->post($request->user(), '/api/recipe-drafts', [
                'name' => $data['name'],
                // Required by the import format; an unknown category is matched in the import wizard.
                'category' => filled($data['category'] ?? null) ? $data['category'] : __('Recipe ideas'),
                'complexity' => in_array($data['complexity'] ?? null, Assistant::COMPLEXITIES, true) ? $data['complexity'] : 'normal',
                'servings' => $data['servings'],
                'instructions' => $data['instructions'],
                'ingredients' => array_map(fn (array $i) => ['amount' => $i['amount'] ?? null, 'unit' => $i['unit'] ?? null, 'food' => $i['name']], $data['ingredients']),
            ]);
        } catch (RecipesException $e) {
            report($e);

            return response()->json(['message' => __('The recipe could not be handed over to :name.', ['name' => config('services.recipes.name')])], 502);
        }

        $url = $result['data']['url'] ?? null;
        $saved = null;
        if (filled($data['key'] ?? null) && $url !== null) {
            $saved = PlannedRecipe::where('user_id', $user->id)->where('key', $data['key'])->first()
                ?? PlannedRecipe::remember($user, [[...$data, 'source' => 'ai']])[0];
            $saved->update(['draft_url' => $url]);
        }

        return response()->json(['url' => $url, 'recipe' => $saved?->toClient()]);
    }

    /**
     * Categories, units and complexities of the recipe app, so AI ideas fit its import.
     *
     * @return array{categories?: list<string>, units?: list<string>, complexities?: list<string>}|null
     */
    private function vocabulary($user): ?array
    {
        if (! RecipesClient::connected($user)) {
            return null;
        }

        try {
            return $this->recipes->get($user, '/api/vocabulary')['data'] ?? null;
        } catch (RecipesException|ConnectionException $e) {
            // Optional: the ideas just fit the recipe app less well.
            report($e);

            return null;
        }
    }

    /**
     * Recipes of the recipe app that use the offers or the pantry; recipes using several offers come
     * first, then by pantry and by foods in season. Also returns the recipe app's foods (null if unknown).
     *
     * @return array{0: list<array{id: int, name: string, foods: list<string>, offers: list<string>, pantry: list<string>, season: list<string>}>, 1: array<int, string>, 2: ?string, 3: ?list<array<string, mixed>>}
     */
    private function candidates($user, array $offers, array $pantry, int $month): array
    {
        if (! RecipesClient::connected($user)) {
            return [[], [], null, null];
        }

        $foods = null;
        try {
            $foods = $this->recipes->get($user, '/api/foods')['data'] ?? [];
            $offerNames = array_values(array_unique(array_column($offers, 'name')));
            $pantryNames = array_values(array_unique(array_column($pantry, 'name')));
            $matches = $this->assistant->matchFoods(array_values(array_unique([...$offerNames, ...$pantryNames])), $foods);
            $offerFoods = array_filter(array_intersect_key($matches, array_flip($offerNames)));
            $pantryFoods = array_filter(array_intersect_key($matches, array_flip($pantryNames)));
            if ($offerFoods === [] && $pantryFoods === []) {
                return [[], [], null, $foods];
            }

            // The recipe app ranks by matched foods: search the offers on their own so recipes
            // combining several offers are not pushed out by pantry matches, then add pantry recipes.
            $found = [];
            foreach ([[$offerFoods, 25], [$pantryFoods, $offerFoods === [] ? 30 : 10]] as [$ids, $limit]) {
                $ids = array_values(array_unique($ids));
                if ($ids !== []) {
                    // season_month: among equally good matches the recipe app prefers seasonal recipes.
                    $found = [...$found, ...$this->recipes->post($user, '/api/recipes/search', ['food_ids' => array_slice($ids, 0, 200), 'limit' => $limit, 'season_month' => $month])['data'] ?? []];
                }
            }
            $found = collect($found)->unique('id')->values()->all();
        } catch (RecipesException $e) {
            report($e);

            return [[], [], __('The recipes of :name could not be loaded.', ['name' => config('services.recipes.name')]), $foods];
        }

        $foodNames = array_column($foods, 'name', 'id');
        $seasonal = Seasons::foodIds($month, $foods);
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
                    'season' => array_values(array_filter(array_map(fn ($id) => in_array($id, $seasonal, true) ? $foodNames[$id] ?? null : null, $recipe['food_ids'] ?? []))),
                ])
                // Offers first, then the pantry, then foods in season.
                ->sortByDesc(fn (array $recipe) => count($recipe['offers']) * 10000 + count($recipe['pantry']) * 100 + count($recipe['season']))
                ->values()
                ->all(),
            array_column($found, 'url', 'id'),
            null,
            $foods,
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
