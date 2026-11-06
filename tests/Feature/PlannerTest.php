<?php

namespace Tests\Feature;

use App\Ai\Assistant;
use App\Models\PlannedRecipe;
use App\Models\User;
use App\Support\Seasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openai.key' => 'test-key',
            'services.openai.url' => 'https://openai.test/v1',
            'services.recipes.url' => 'https://recipes.test',
            'services.recipes.client_id' => '1',
            'services.recipes.client_secret' => 'secret',
            'services.recipes.name' => 'Kochbuch',
        ]);
    }

    /**
     * OpenAI answer in the Responses API shape.
     *
     * @param  array<string, mixed>  $data
     */
    private function ai(array $data): array
    {
        return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]]]];
    }

    private function connected(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['recipes_connection' => [
            'access_token' => 'token', 'refresh_token' => 'refresh', 'expires_at' => now()->addHour()->timestamp,
            'account' => ['name' => 'Beni', 'email' => 'beni@example.ch'],
        ]])->save();

        return $user;
    }

    public function test_me_reports_assistant_and_recipe_app(): void
    {
        $this->actingAs($this->connected())->getJson('/me')
            ->assertJsonPath('assistant', true)
            ->assertJsonPath('recipes.name', 'Kochbuch')
            ->assertJsonPath('recipes.connected', true)
            ->assertJsonMissingPath('recipes.access_token');

        config(['services.openai.key' => null, 'services.recipes.url' => '']);
        $this->actingAs(User::factory()->create())->getJson('/me')
            ->assertJsonPath('assistant', false)
            ->assertJsonPath('recipes', null);
    }

    public function test_offers_are_read_from_a_pdf_with_shop_codes(): void
    {
        Http::fake(['openai.test/*' => Http::response($this->ai(['offers' => [
            ['name' => 'Rindshackfleisch', 'brand' => 'M-Classic', 'price' => 9.95, 'regular_price' => 14.2, 'discount' => '−30 %', 'valid_until' => '2026-11-02', 'shop' => 'Migros'],
            ['name' => 'Avocados', 'brand' => null, 'price' => 2.49, 'regular_price' => null, 'discount' => null, 'valid_until' => null, 'shop' => 'Lidl'],
            ['name' => 'Kaffee', 'brand' => null, 'price' => null, 'regular_price' => null, 'discount' => null, 'valid_until' => null, 'shop' => null],
        ]]))]);

        $this->actingAs(User::factory()->create())
            ->post('/planner/offers', ['file' => UploadedFile::fake()->create('aktionen.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('offers.0.store', 'M')
            ->assertJsonPath('offers.1.store', 'L')
            ->assertJsonPath('offers.2.store', null);

        Http::assertSent(function (Request $request) {
            $content = $request['input'][0]['content'];

            return $request->hasHeader('Authorization', 'Bearer test-key')
                && $content[1]['type'] === 'input_file'
                && str_starts_with($content[1]['file_data'], 'data:application/pdf;base64,')
                && $request['text']['format']['strict'] === true;
        });
    }

    public function test_pantry_photos_are_read(): void
    {
        Http::fake(['openai.test/*' => Http::response($this->ai(['items' => [['name' => 'Eier', 'amount' => '6 Stk.']]]))]);

        $this->actingAs(User::factory()->create())
            ->post('/planner/pantry', ['photos' => [UploadedFile::fake()->image('fridge.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('items.0.name', 'Eier');
    }

    public function test_the_feature_needs_a_key_and_a_verified_account(): void
    {
        config(['services.openai.key' => null]);
        $this->actingAs(User::factory()->create())->postJson('/planner/offers', ['text' => 'Milch −20 %'])
            ->assertStatus(503)->assertJsonStructure(['message']);

        $this->actingAs(User::factory()->unverified()->create())->postJson('/planner/offers', ['text' => 'x'])->assertForbidden();
    }

    public function test_suggestions_combine_own_recipes_and_ideas(): void
    {
        Http::fake([
            'recipes.test/api/foods' => Http::response(['data' => [['id' => 1, 'name' => 'Hackfleisch'], ['id' => 2, 'name' => 'Tomaten']]]),
            'recipes.test/api/vocabulary' => Http::response(['data' => ['categories' => ['Eierspeisen', 'Suppen'], 'units' => ['g', 'Stk'], 'complexities' => ['simple', 'normal', 'difficult']]]),
            'recipes.test/api/recipes/search' => Http::response(['data' => [
                ['id' => 5, 'name' => 'Lasagne', 'url' => 'https://recipes.test/recipes/5', 'food_ids' => [1, 2], 'matched_food_ids' => [1]],
            ]]),
            'openai.test/*' => Http::sequence()
                ->push($this->ai(['matches' => [['product' => 'Rindshackfleisch', 'food_id' => 1], ['product' => 'Eier', 'food_id' => 999]]]))
                ->push($this->ai([
                    'picks' => [['recipe_id' => 5, 'reason' => 'Hackfleisch in Aktion', 'uses_offers' => ['Rindshackfleisch'], 'uses_pantry' => []], ['recipe_id' => 77, 'reason' => 'invented', 'uses_offers' => [], 'uses_pantry' => []]],
                    'ideas' => [['name' => 'Omelette', 'reason' => 'Eier sind da', 'servings' => 2, 'ingredients' => [['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier']], 'instructions' => '1. Braten', 'uses_offers' => [], 'uses_pantry' => ['Eier']]],
                ])),
        ]);

        $this->actingAs($this->connected())->postJson('/planner/suggestions', [
            'offers' => [['name' => 'Rindshackfleisch', 'store' => 'M']],
            'pantry' => [['name' => 'Eier', 'amount' => null]],
            'servings' => 2, 'count' => 3, 'ideas' => true,
        ])
            ->assertOk()
            ->assertJsonCount(2, 'suggestions')
            ->assertJsonPath('suggestions.0.source', 'recipes')
            ->assertJsonPath('suggestions.0.name', 'Lasagne')
            ->assertJsonPath('suggestions.0.url', 'https://recipes.test/recipes/5')
            ->assertJsonPath('suggestions.1.source', 'ai')
            ->assertJsonPath('suggestions.1.name', 'Omelette');

        // Ideas use the categories and units of the recipe app.
        Http::assertSent(fn (Request $request) => str_contains($request['instructions'] ?? '', 'one of: Eierspeisen, Suppen')
            && str_contains($request['instructions'], 'units where possible: g, Stk')
            && $request['text']['format']['schema']['properties']['ideas']['items']['properties']['complexity']['enum'] === ['simple', 'normal', 'difficult']);

        // Only matched, known food ids are searched.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/recipes/search')
            && $request['food_ids'] === [1] && $request->hasHeader('Authorization', 'Bearer token'));
    }

    public function test_candidates_combining_several_offers_come_first(): void
    {
        Http::fake([
            'recipes.test/api/foods' => Http::response(['data' => [['id' => 1, 'name' => 'Hackfleisch'], ['id' => 2, 'name' => 'Tomaten'], ['id' => 3, 'name' => 'Eier']]]),
            'recipes.test/api/vocabulary' => Http::response(['data' => ['categories' => [], 'units' => [], 'complexities' => []]]),
            'recipes.test/api/recipes/search' => Http::sequence()
                ->push(['data' => [
                    ['id' => 6, 'name' => 'Tomatensalat', 'url' => 'https://recipes.test/recipes/6', 'food_ids' => [2]],
                    ['id' => 5, 'name' => 'Lasagne', 'url' => 'https://recipes.test/recipes/5', 'food_ids' => [1, 2, 3]],
                ]])
                ->push(['data' => [
                    ['id' => 5, 'name' => 'Lasagne', 'url' => 'https://recipes.test/recipes/5', 'food_ids' => [1, 2, 3]],
                    ['id' => 7, 'name' => 'Omelette', 'url' => 'https://recipes.test/recipes/7', 'food_ids' => [3]],
                ]]),
            'openai.test/*' => Http::sequence()
                ->push($this->ai(['matches' => [
                    ['product' => 'Rindshackfleisch', 'food_id' => 1], ['product' => 'Cherry-Tomaten', 'food_id' => 2], ['product' => 'Eier', 'food_id' => 3],
                ]]))
                ->push($this->ai(['picks' => [], 'ideas' => []])),
        ]);

        $this->actingAs($this->connected())->postJson('/planner/suggestions', [
            'offers' => [['name' => 'Rindshackfleisch'], ['name' => 'Cherry-Tomaten']],
            'pantry' => [['name' => 'Eier']],
            'servings' => 2, 'count' => 3, 'ideas' => false,
        ])->assertOk();

        // Offers and pantry are searched separately, seasonal recipes preferred.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/recipes/search') && $request['food_ids'] === [1, 2] && $request['season_month'] === Seasons::month());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/recipes/search') && $request['food_ids'] === [3]);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'openai.test') || ! str_contains($request['instructions'] ?? '', 'combine several offers')) {
                return false;
            }
            $candidates = json_decode($request['input'][0]['content'][0]['text'], true)['candidates'];

            return array_column($candidates, 'name') === ['Lasagne', 'Tomatensalat', 'Omelette']
                && $candidates[0]['offers'] === ['Rindshackfleisch', 'Cherry-Tomaten']
                && $candidates[0]['pantry'] === ['Eier'];
        });
    }

    public function test_seasonal_foods_rank_candidates_and_go_to_the_ai(): void
    {
        $this->travelTo(Carbon::parse('2026-07-10 12:00', 'Europe/Zurich'));
        Http::fake([
            'recipes.test/api/foods' => Http::response(['data' => [
                ['id' => 1, 'name' => 'Hackfleisch', 'season_months' => [], 'storage_months' => []],
                ['id' => 2, 'name' => 'Zucchetti', 'season_months' => [6, 7, 8, 9], 'storage_months' => []],
                ['id' => 3, 'name' => 'Rosenkohl', 'season_months' => [10, 11, 12, 1], 'storage_months' => []],
            ]]),
            'recipes.test/api/vocabulary' => Http::response(['data' => ['categories' => [], 'units' => [], 'complexities' => []]]),
            'recipes.test/api/recipes/search' => Http::response(['data' => [
                ['id' => 8, 'name' => 'Rosenkohl-Gratin', 'url' => null, 'food_ids' => [1, 3]],
                ['id' => 9, 'name' => 'Zucchetti-Lasagne', 'url' => null, 'food_ids' => [1, 2]],
            ]]),
            'openai.test/*' => Http::sequence()
                ->push($this->ai(['matches' => [['product' => 'Rindshackfleisch', 'food_id' => 1]]]))
                ->push($this->ai(['picks' => [['recipe_id' => 9, 'reason' => 'x', 'uses_offers' => [], 'uses_pantry' => []]], 'ideas' => [[
                    'name' => 'Sommerpfanne', 'reason' => 'y', 'servings' => 2, 'category' => 'Hauptgang', 'complexity' => 'simple', 'instructions' => '1. Braten',
                    'ingredients' => [['amount' => 2, 'unit' => null, 'name' => 'Zucchetti'], ['amount' => 1, 'unit' => 'Stk.', 'name' => 'Rosenkohl']],
                    'uses_offers' => [], 'uses_pantry' => [],
                ]]])),
        ]);

        // The suggestions name the foods in season they use.
        $this->actingAs($this->connected())->postJson('/planner/suggestions', [
            'offers' => [['name' => 'Rindshackfleisch']], 'pantry' => [], 'servings' => 2, 'count' => 3, 'ideas' => true,
        ])->assertOk()
            ->assertJsonPath('suggestions.0.uses_season', ['Zucchetti'])
            ->assertJsonPath('suggestions.1.uses_season', ['Zucchetti']);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'openai.test') || ! str_contains($request['instructions'] ?? '', 'in season in Switzerland')) {
                return false;
            }
            $input = json_decode($request['input'][0]['content'][0]['text'], true);

            // Same offers: the recipe with foods in season first; the recipe app's season data is used.
            return array_column($input['candidates'], 'name') === ['Zucchetti-Lasagne', 'Rosenkohl-Gratin']
                && $input['candidates'][0]['season'] === ['Zucchetti']
                && $input['seasonal'] === ['Zucchetti'];
        });
    }

    public function test_without_recipe_app_the_season_list_of_the_app_is_used(): void
    {
        $this->travelTo(Carbon::parse('2026-12-10 12:00', 'Europe/Zurich'));
        Http::fake(['openai.test/*' => Http::response($this->ai(['picks' => [], 'ideas' => []]))]);

        $this->actingAs(User::factory()->create())->postJson('/planner/suggestions', [
            'offers' => [['name' => 'Rindshackfleisch']], 'pantry' => [], 'servings' => 2, 'count' => 3, 'ideas' => true,
        ])->assertOk();

        Http::assertSent(function (Request $request) {
            $seasonal = json_decode($request['input'][0]['content'][0]['text'] ?? '{}', true)['seasonal'] ?? [];

            return in_array('Rosenkohl', $seasonal, true) && ! in_array('Tomaten', $seasonal, true);
        });
    }

    public function test_more_suggestions_leave_out_the_shown_recipes(): void
    {
        Http::fake([
            'recipes.test/api/foods' => Http::response(['data' => [['id' => 1, 'name' => 'Hackfleisch']]]),
            'recipes.test/api/vocabulary' => Http::response(['data' => ['categories' => [], 'units' => [], 'complexities' => []]]),
            'recipes.test/api/recipes/search' => Http::response(['data' => [
                ['id' => 5, 'name' => 'Lasagne', 'url' => 'https://recipes.test/recipes/5', 'food_ids' => [1]],
                ['id' => 6, 'name' => 'Chili', 'url' => 'https://recipes.test/recipes/6', 'food_ids' => [1]],
            ]]),
            'openai.test/*' => Http::sequence()
                ->push($this->ai(['matches' => [['product' => 'Rindshackfleisch', 'food_id' => 1]]]))
                ->push($this->ai(['picks' => [['recipe_id' => 5, 'reason' => 'shown before', 'uses_offers' => [], 'uses_pantry' => []]], 'ideas' => [
                    ['name' => 'Burger', 'reason' => 'x', 'servings' => 2, 'ingredients' => [], 'instructions' => '1.', 'uses_offers' => [], 'uses_pantry' => []],
                    ['name' => 'Tacos', 'reason' => 'x', 'servings' => 2, 'ingredients' => [], 'instructions' => '1.', 'uses_offers' => [], 'uses_pantry' => []],
                ]])),
        ]);

        $suggestions = $this->actingAs($this->connected())->postJson('/planner/suggestions', [
            'offers' => [['name' => 'Rindshackfleisch']], 'pantry' => [], 'servings' => 2, 'count' => 3, 'ideas' => true,
            'exclude' => ['recipe_ids' => [5], 'names' => ['Lasagne', 'Omelette']],
        ])->assertOk()->json('suggestions');

        // A shown recipe is neither offered to the AI nor accepted as a pick.
        $this->assertSame(['Burger', 'Tacos'], array_column($suggestions, 'name'));
        $this->assertNotSame($suggestions[0]['key'], $suggestions[1]['key']);
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'openai.test') || ! str_contains($request['instructions'] ?? '', 'combine several offers')) {
                return false;
            }

            return array_column(json_decode($request['input'][0]['content'][0]['text'], true)['candidates'], 'id') === [6]
                && str_contains($request['instructions'], 'suggest different ones: Lasagne; Omelette');
        });
    }

    public function test_suggestions_without_recipe_app_are_ideas_only(): void
    {
        Http::fake(['openai.test/*' => Http::response($this->ai(['picks' => [], 'ideas' => []]))]);

        $this->actingAs(User::factory()->create())->postJson('/planner/suggestions', [
            'offers' => [['name' => 'Avocados']], 'pantry' => [], 'servings' => 4, 'count' => 5, 'ideas' => false,
        ])->assertOk()->assertJsonPath('suggestions', []);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'recipes.test'));
        Http::assertSent(fn (Request $request) => str_contains($request['instructions'], 'fill up with new recipe ideas'));
    }

    public function test_the_shopping_list_marks_offers_and_pantry(): void
    {
        Http::fake([
            'recipes.test/api/recipes/5*' => Http::response(['data' => ['name' => 'Lasagne', 'ingredients' => [
                ['amount' => 500, 'unit' => 'g', 'food' => 'Hackfleisch', 'text' => '500 g Hackfleisch'],
            ]]]),
            'openai.test/*' => Http::response($this->ai(['items' => [
                ['name' => 'Hackfleisch', 'amount' => 500, 'unit' => 'g', 'offer' => 'Rindshackfleisch', 'at_home' => false, 'recipes' => ['Lasagne']],
                ['name' => 'Eier', 'amount' => 4, 'unit' => 'Stk.', 'offer' => null, 'at_home' => true, 'recipes' => ['Omelette']],
            ]])),
        ]);

        $this->actingAs($this->connected())->withHeader('Accept-Language', 'de-CH')->postJson('/planner/list', [
            'offers' => [['name' => 'Rindshackfleisch', 'store' => 'M', 'discount' => '−30 %', 'price' => 9.95, 'regular_price' => 14.2]],
            'pantry' => [['name' => 'Eier']],
            'recipes' => [
                ['source' => 'recipes', 'recipe_id' => 5, 'name' => 'Lasagne', 'servings' => 4],
                ['source' => 'ai', 'name' => 'Omelette', 'servings' => 2, 'ingredients' => [['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier']]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('items.0.store', 'M')
            // The item keeps the generic name, the note names the product on offer.
            ->assertJsonPath('items.0.name', 'Hackfleisch')
            ->assertJsonPath('items.0.note', 'Aktion −30 %, Rindshackfleisch, CHF 9.95 (statt 14.20)')
            ->assertJsonPath('items.1.at_home', true)
            ->assertJsonPath('items.1.store', null);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/recipes/5?servings=4'));
    }

    public function test_suggestions_are_saved_on_click_or_with_the_list(): void
    {
        Http::fake([
            'recipes.test/api/foods' => Http::response(['data' => [['id' => 1, 'name' => 'Hackfleisch']]]),
            'recipes.test/api/vocabulary' => Http::response(['data' => []]),
            'recipes.test/api/recipes/search' => Http::response(['data' => [
                ['id' => 5, 'name' => 'Lasagne', 'url' => 'https://recipes.test/recipes/5', 'food_ids' => [1], 'matched_food_ids' => [1]],
            ]]),
            'recipes.test/api/recipes/5*' => Http::response(['data' => ['name' => 'Lasagne', 'ingredients' => []]]),
            'recipes.test/api/recipe-drafts' => Http::response(['data' => ['id' => 'abc', 'url' => 'https://recipes.test/import?draft=abc']], 201),
            'openai.test/*' => Http::sequence()
                ->push($this->ai(['matches' => [['product' => 'Rindshackfleisch', 'food_id' => 1]]]))
                ->push($this->ai([
                    'picks' => [['recipe_id' => 5, 'reason' => 'Aktion', 'uses_offers' => ['Rindshackfleisch'], 'uses_pantry' => []]],
                    'ideas' => [
                        ['name' => 'Omelette', 'reason' => 'Eier', 'servings' => 2, 'ingredients' => [['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier']], 'instructions' => '1. Braten', 'uses_offers' => [], 'uses_pantry' => []],
                        ['name' => 'Rösti', 'reason' => 'Kartoffeln', 'servings' => 2, 'ingredients' => [['amount' => 800, 'unit' => 'g', 'name' => 'Kartoffeln']], 'instructions' => '1. Reiben', 'uses_offers' => [], 'uses_pantry' => []],
                    ],
                ]))
                ->push($this->ai(['items' => [['name' => 'Hackfleisch', 'amount' => 500, 'unit' => 'g', 'offer' => null, 'at_home' => false, 'recipes' => ['Lasagne', 'Rösti']]]])),
        ]);
        $user = $this->connected();
        $other = User::factory()->create();

        [$lasagne, $omelette, $roesti] = $this->actingAs($user)->postJson('/planner/suggestions', [
            'offers' => [['name' => 'Rindshackfleisch', 'store' => 'M']], 'pantry' => [], 'servings' => 4, 'count' => 3, 'ideas' => true,
        ])->assertOk()->json('suggestions');

        // Suggestions are not saved by themselves.
        $this->actingAs($user)->getJson('/planned-recipes')->assertOk()->assertJsonCount(0, 'recipes');

        // "Save": an AI idea keeps ingredients and preparation.
        $saved = $this->actingAs($user)->postJson('/planned-recipes', [...$omelette, 'servings' => 2])
            ->assertCreated()->json('recipe');
        $this->assertSame(['Omelette', 2, '1. Braten', $omelette['key'], 'Eier'], [$saved['name'], $saved['servings'], $saved['instructions'], $saved['key'], $saved['ingredients'][0]['name']]);
        $this->assertNull($saved['used_at']);

        // Own recipes once per recipe id, whatever key the client sends.
        $this->actingAs($user)->postJson('/planned-recipes', [...$lasagne, 'servings' => 4])->assertCreated();
        $this->actingAs($user)->postJson('/planned-recipes', [...$lasagne, 'key' => 'recipe-999', 'servings' => 6])->assertOk()
            ->assertJsonPath('recipe.key', 'recipe-5')->assertJsonPath('recipe.servings', 6)->assertJsonPath('recipe.url', 'https://recipes.test/recipes/5');
        $this->actingAs($user)->getJson('/planned-recipes')->assertJsonCount(2, 'recipes');

        // The recipes on a list are saved (also the ones not saved before) and marked as used.
        $this->actingAs($user)->postJson('/planner/list', ['offers' => [], 'pantry' => [], 'recipes' => [
            [...$lasagne, 'servings' => 4],
            [...$roesti, 'servings' => 2],
        ]])->assertOk();
        $history = collect($this->actingAs($user)->getJson('/planned-recipes')->json('recipes'))->keyBy('name');
        $this->assertCount(3, $history);
        $this->assertNotNull($history['Lasagne']['used_at']);
        $this->assertSame([$roesti['key'], '1. Reiben', 2], [$history['Rösti']['key'], $history['Rösti']['instructions'], $history['Rösti']['servings']]);
        $this->assertNotNull($history['Rösti']['used_at']);
        $this->assertNull($history['Omelette']['used_at']);

        $this->actingAs($user)->postJson('/planner/recipes', [
            'key' => $omelette['key'], 'name' => 'Omelette', 'servings' => 2, 'instructions' => '1. Braten', 'ingredients' => [['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier']],
        ])->assertOk();
        $history = collect($this->actingAs($user)->getJson('/planned-recipes')->json('recipes'))->keyBy('name');
        $this->assertSame('https://recipes.test/import?draft=abc', $history['Omelette']['draft_url']);

        // Private: others neither see nor remove them.
        $this->actingAs($other)->getJson('/planned-recipes')->assertJsonCount(0, 'recipes');
        $this->actingAs($other)->deleteJson('/planned-recipes/'.$history['Omelette']['id'])->assertForbidden();

        $this->actingAs($user)->deleteJson('/planned-recipes/'.$history['Omelette']['id'])->assertNoContent();
        $this->actingAs($user)->getJson('/planned-recipes')->assertJsonCount(2, 'recipes');
        $this->actingAs($user)->deleteJson('/planned-recipes')->assertNoContent();
        $this->actingAs($user)->getJson('/planned-recipes')->assertJsonCount(0, 'recipes');
    }

    public function test_the_list_returns_the_saved_recipes_the_items_link_to(): void
    {
        Http::fake([
            'recipes.test/api/recipes/5*' => Http::response(['data' => ['name' => 'Lasagne al forno', 'ingredients' => [
                ['food' => 'Hackfleisch', 'amount' => 500, 'unit' => 'g'], ['text' => 'Salz', 'amount' => null, 'unit' => null],
            ]]]),
            'openai.test/*' => Http::response($this->ai(['items' => [
                ['name' => 'Hackfleisch', 'amount' => 500, 'unit' => 'g', 'offer' => null, 'at_home' => false, 'recipes' => ['Lasagne al forno', 'Omelette']],
            ]])),
        ]);
        $user = $this->connected();
        $idea = 'idea-0190a0b0-0000-7000-8000-000000000001';

        $response = $this->actingAs($user)->postJson('/planner/list', ['offers' => [], 'pantry' => [], 'recipes' => [
            ['source' => 'recipes', 'key' => 'recipe-5', 'recipe_id' => 5, 'name' => 'Lasagne', 'url' => 'https://recipes.test/recipes/5', 'servings' => 4],
            ['source' => 'ai', 'key' => $idea, 'name' => 'Omelette', 'servings' => 2, 'ingredients' => [['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier']], 'instructions' => '1. Braten'],
        ]])->assertOk();

        // The items name the suggestions, the ids are the saved recipes.
        $response->assertJsonPath('items.0.recipes', ['Lasagne', 'Omelette']);
        $saved = PlannedRecipe::where('user_id', $user->id)->pluck('id', 'key');
        $this->assertSame([['key' => 'recipe-5', 'id' => $saved['recipe-5']], ['key' => $idea, 'id' => $saved[$idea]]], $response->json('recipes'));

        // Own recipes keep the ingredients of the recipe app (Recipes tab).
        $this->assertSame([['amount' => 500, 'unit' => 'g', 'name' => 'Hackfleisch'], ['amount' => null, 'unit' => null, 'name' => 'Salz']],
            PlannedRecipe::find($saved['recipe-5'])->data['ingredients']);
    }

    public function test_older_clients_without_keys_build_lists_without_saving(): void
    {
        Http::fake(['openai.test/*' => Http::response($this->ai(['items' => []]))]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/planner/list', ['offers' => [], 'pantry' => [], 'recipes' => [
            ['source' => 'ai', 'name' => 'Omelette', 'servings' => 2, 'ingredients' => [['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier']]],
        ]])->assertOk();

        $this->assertSame(0, PlannedRecipe::count());
    }

    public function test_saving_a_recipe_is_validated(): void
    {
        $user = User::factory()->create();
        $idea = ['key' => 'idea-0190a0b0-0000-7000-8000-000000000001', 'source' => 'ai', 'name' => 'Omelette', 'servings' => 2,
            'ingredients' => [['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier']], 'instructions' => '1. Braten'];

        $this->actingAs($user)->postJson('/planned-recipes', [...$idea, 'ingredients' => []])->assertJsonValidationErrors('ingredients');
        $this->actingAs($user)->postJson('/planned-recipes', [...$idea, 'instructions' => null])->assertJsonValidationErrors('instructions');
        $this->actingAs($user)->postJson('/planned-recipes', [...$idea, 'key' => 'other<script>'])->assertJsonValidationErrors('key');
        $this->actingAs($user)->postJson('/planned-recipes', ['source' => 'recipes', 'name' => 'Lasagne'])->assertJsonValidationErrors('recipe_id');
        $this->actingAs($user)->postJson('/planned-recipes', ['source' => 'recipes', 'recipe_id' => 5, 'name' => 'Lasagne', 'url' => 'javascript:alert(1)'])
            ->assertJsonValidationErrors('url');
        $this->actingAs($user)->postJson('/planned-recipes', [...$idea, 'source' => 'other'])->assertJsonValidationErrors('source');

        // AI ideas get no URL.
        $this->actingAs($user)->postJson('/planned-recipes', [...$idea, 'url' => 'https://example.com'])->assertCreated()->assertJsonPath('recipe.url', null);

        $this->actingAs(User::factory()->unverified()->create())->postJson('/planned-recipes', $idea)->assertForbidden();
        auth()->logout();
        $this->postJson('/planned-recipes', $idea)->assertUnauthorized();
    }

    public function test_saved_recipes_are_limited_per_user(): void
    {
        $user = User::factory()->create();
        $now = now();
        PlannedRecipe::insert(collect(range(1, PlannedRecipe::LIMIT))->map(fn (int $i) => [
            'id' => (string) Str::uuid7(), 'user_id' => $user->id, 'key' => 'recipe-'.$i, 'source' => 'recipes', 'recipe_id' => $i,
            'name' => 'Rezept '.$i, 'data' => '{}', 'created_at' => $now->copy()->subMinutes($i), 'updated_at' => $now->copy()->subMinutes($i),
        ])->all());

        $this->actingAs($user)->postJson('/planned-recipes', ['source' => 'recipes', 'recipe_id' => 999, 'name' => 'Neu'])->assertCreated();

        $this->assertSame(PlannedRecipe::LIMIT, PlannedRecipe::where('user_id', $user->id)->count());
        $this->assertFalse(PlannedRecipe::where('key', 'recipe-'.PlannedRecipe::LIMIT)->exists());
        $this->assertTrue(PlannedRecipe::where('key', 'recipe-999')->exists());
    }

    public function test_amounts_are_rounded_to_what_one_buys(): void
    {
        $assistant = app(Assistant::class);

        $this->assertSame(4.0, $assistant->roundForShopping(3.33, 'Stk.'));
        $this->assertSame(1.0, $assistant->roundForShopping(0.5, 'Wf'));
        $this->assertSame(70.0, $assistant->roundForShopping(66.67, 'g'));
        $this->assertSame(1950.0, $assistant->roundForShopping(1940, 'g'));
        $this->assertSame(0.7, $assistant->roundForShopping(0.67, 'kg'));
        $this->assertSame(2.0, $assistant->roundForShopping(1.33, null));
        $this->assertNull($assistant->roundForShopping(null, 'g'));
    }

    public function test_ai_recipes_are_handed_over_as_a_draft(): void
    {
        Http::fake(['recipes.test/api/recipe-drafts' => Http::response(['data' => ['id' => 'abc', 'url' => 'https://recipes.test/admin/import-recipe?draft=abc']], 201)]);

        $this->actingAs($this->connected())->postJson('/planner/recipes', [
            'name' => 'Omelette', 'servings' => 2, 'category' => 'Eierspeisen', 'complexity' => 'simple', 'instructions' => '1. Braten',
            'ingredients' => [['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier']],
        ])->assertOk()->assertJsonPath('url', 'https://recipes.test/admin/import-recipe?draft=abc');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://recipes.test/api/recipe-drafts'
            && $request['name'] === 'Omelette' && $request['category'] === 'Eierspeisen' && $request['complexity'] === 'simple'
            && $request['ingredients'] === [['amount' => 4, 'unit' => 'Stk.', 'food' => 'Eier']]);
    }

    public function test_drafts_get_a_default_category_and_complexity(): void
    {
        Http::fake(['recipes.test/api/recipe-drafts' => Http::response(['data' => ['url' => 'https://recipes.test/x']], 201)]);

        $user = $this->connected();
        $user->forceFill(['locale' => 'de_CH'])->save();

        $this->actingAs($user)->postJson('/planner/recipes', [
            'name' => 'Omelette', 'servings' => 2, 'complexity' => 'very hard', 'instructions' => '1. Braten',
            'ingredients' => [['name' => 'Eier']],
        ])->assertOk();

        Http::assertSent(fn (Request $request) => $request['category'] === 'Rezeptideen' && $request['complexity'] === 'normal');
    }

    public function test_an_expired_token_is_refreshed(): void
    {
        $user = $this->connected();
        $user->forceFill(['recipes_connection' => [...$user->recipes_connection, 'expires_at' => now()->subMinute()->timestamp]])->save();

        Http::fake([
            'recipes.test/oauth/token' => Http::response(['access_token' => 'new', 'refresh_token' => 'refresh2', 'expires_in' => 3600]),
            'recipes.test/api/recipe-drafts' => Http::response(['data' => ['url' => 'https://recipes.test/x']], 201),
        ]);
        $this->actingAs($user)->postJson('/planner/recipes', [
            'name' => 'X', 'servings' => 1, 'instructions' => 'y', 'ingredients' => [['name' => 'Eier']],
        ])->assertOk();
        $this->assertSame('new', $user->fresh()->recipes_connection['access_token']);
    }

    public function test_a_revoked_connection_is_dropped(): void
    {
        $user = $this->connected();
        Http::fake(['recipes.test/*' => Http::sequence()->push([], 401)->push(['error' => 'invalid_grant'], 400)]);
        $this->actingAs($user)->postJson('/planner/recipes', [
            'name' => 'X', 'servings' => 1, 'instructions' => 'y', 'ingredients' => [['name' => 'Eier']],
        ])->assertStatus(502);
        $this->assertNull($user->fresh()->recipes_connection);
    }

    public function test_connecting_with_the_recipe_app(): void
    {
        $user = User::factory()->create();
        $redirect = $this->actingAs($user)->get('/recipes/connect')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://recipes.test/oauth/authorize?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame(route('recipes.callback'), $query['redirect_uri']);
        // Only SSO users are sent straight to the recipe app's OIDC login.
        $this->assertArrayNotHasKey('sso', $query);
        $ssoUser = User::factory()->create(['oidc_sub' => 'sub-1']);
        $ssoRedirect = $this->actingAs($ssoUser)->get('/recipes/connect')->headers->get('Location');
        parse_str((string) parse_url($ssoRedirect, PHP_URL_QUERY), $ssoQuery);
        $this->assertSame('1', $ssoQuery['sso']);

        // A wrong state is refused.
        $this->actingAs($user)->get('/recipes/callback?code=abc&state=wrong')->assertRedirect('/?recipes=failed#/settings');

        Http::fake([
            'recipes.test/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600]),
            'recipes.test/api/user' => Http::response(['id' => 3, 'name' => 'Beni', 'email' => 'beni@example.ch']),
        ]);
        $this->actingAs($user)->get('/recipes/connect');
        $state = session('recipes_oauth_state');
        $this->actingAs($user)->get('/recipes/callback?code=abc&state='.$state)->assertRedirect('/?recipes=connected#/settings');

        $this->assertSame('at', $user->fresh()->recipes_connection['access_token']);
        $this->assertSame('Beni', $user->fresh()->recipes_connection['account']['name']);
        $this->assertNotSame('at', $user->fresh()->getRawOriginal('recipes_connection'), 'tokens are stored encrypted');

        $this->actingAs($user)->deleteJson('/recipes/connection')->assertNoContent();
        $this->assertNull($user->fresh()->recipes_connection);
    }

    public function test_connecting_returns_to_where_it_was_started(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'recipes.test/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600]),
            'recipes.test/api/user' => Http::response(['id' => 3, 'name' => 'Beni', 'email' => 'beni@example.ch']),
        ]);

        $this->actingAs($user)->get('/recipes/connect?from=planner');
        $this->actingAs($user)->get('/recipes/callback?error=access_denied&state=x')->assertRedirect('/?recipes=denied#/planner');

        $this->actingAs($user)->get('/recipes/connect?from=planner');
        $this->actingAs($user)->get('/recipes/callback?code=abc&state='.session('recipes_oauth_state'))->assertRedirect('/?recipes=connected#/planner');

        // Unknown views fall back to the settings.
        $this->actingAs($user)->get('/recipes/connect?from=list/123');
        $this->actingAs($user)->get('/recipes/callback?code=abc&state='.session('recipes_oauth_state'))->assertRedirect('/?recipes=connected#/settings');
    }
}
