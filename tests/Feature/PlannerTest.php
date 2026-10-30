<?php

namespace Tests\Feature;

use App\Ai\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

        // Offers and pantry are searched separately.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/recipes/search') && $request['food_ids'] === [1, 2]);
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
            ->assertJsonPath('items.0.note', 'Aktion −30 %, CHF 9.95 (statt 14.20)')
            ->assertJsonPath('items.1.at_home', true)
            ->assertJsonPath('items.1.store', null);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/recipes/5?servings=4'));
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
}
