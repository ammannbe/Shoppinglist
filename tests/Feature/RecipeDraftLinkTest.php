<?php

namespace Tests\Feature;

use App\Models\PlannedRecipe;
use App\Models\User;
use App\Recipes\RecipeDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AI ideas handed to the recipe app as drafts become the own recipe once imported there
 * (RecipeDrafts, asked for by GET /planned-recipes).
 */
class RecipeDraftLinkTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFT = '0190a0b0-0000-7000-8000-00000000d1a1';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.recipes.url' => 'https://recipes.test',
            'services.recipes.client_id' => '1',
            'services.recipes.client_secret' => 'secret',
            'services.recipes.name' => 'Kochbuch',
        ]);
    }

    private function connected(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['recipes_connection' => [
            'access_token' => 'token', 'refresh_token' => 'refresh', 'expires_at' => now()->addHour()->timestamp,
        ]])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function idea(User $user, array $overrides = []): PlannedRecipe
    {
        $recipe = new PlannedRecipe([
            'key' => 'idea-0190a0b0-0000-7000-8000-000000000001', 'source' => 'ai', 'name' => 'Omelette', 'servings' => 2,
            'data' => ['reason' => 'Eier', 'uses_pantry' => ['Eier'], 'uses_offers' => [], 'uses_season' => [], 'instructions' => '1. Braten',
                'ingredients' => [['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier']], 'category' => 'Eierspeisen', 'complexity' => 'simple'],
            'draft_url' => 'https://recipes.test/admin/import-recipe?draft='.self::DRAFT,
            'draft_id' => self::DRAFT,
            'draft_expires_at' => now()->addDay(),
            'planned' => true,
            'planned_at' => now()->subHour(),
            ...$overrides,
        ]);
        $recipe->user()->associate($user);
        $recipe->save();

        return $recipe;
    }

    /**
     * @param  array<string, mixed>|null  $recipe
     */
    private function draft(string $status, ?array $recipe = null): array
    {
        return ['data' => ['id' => self::DRAFT, 'status' => $status, 'expires_at' => now()->addDay()->toIso8601String(), 'recipe' => $recipe]];
    }

    public function test_handing_over_an_idea_keeps_the_draft_id(): void
    {
        $expires = now()->addDay()->startOfSecond();
        Http::fake(['recipes.test/api/recipe-drafts' => Http::response(['data' => [
            'id' => self::DRAFT, 'url' => 'https://recipes.test/admin/import-recipe?draft='.self::DRAFT, 'expires_at' => $expires->toIso8601String(),
        ]], 201)]);

        $this->actingAs($this->connected())->postJson('/planner/recipes', [
            'key' => 'idea-0190a0b0-0000-7000-8000-000000000001', 'name' => 'Omelette', 'servings' => 2, 'instructions' => '1. Braten',
            'ingredients' => [['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier']],
        ])->assertOk();

        $saved = PlannedRecipe::sole();
        $this->assertSame(self::DRAFT, $saved->draft_id);
        $this->assertTrue($saved->draft_expires_at->equalTo($expires));
    }

    public function test_an_imported_idea_becomes_the_own_recipe(): void
    {
        $user = $this->connected();
        $idea = $this->idea($user);
        Http::fake([
            'recipes.test/api/recipe-drafts/*' => Http::response($this->draft('imported', ['id' => 42, 'name' => 'Omelette mit Kräutern', 'url' => 'https://recipes.test/recipes/42'])),
            'recipes.test/api/recipes/42*' => Http::response(['data' => ['id' => 42, 'name' => 'Omelette mit Kräutern', 'ingredients' => [
                ['amount' => 4, 'unit' => 'Stk.', 'food' => 'Eier', 'text' => '4 Stk. Eier'],
                ['amount' => 1, 'unit' => 'Bund', 'food' => 'Schnittlauch', 'text' => '1 Bund Schnittlauch'],
            ]]]),
        ]);

        $recipes = $this->actingAs($user)->getJson('/planned-recipes')->assertOk()->json('recipes');

        // Same row (items link to its id), now the own recipe with the recipe app's ingredients.
        $this->assertSame($idea->id, $recipes[0]['id']);
        $this->assertSame(
            ['recipe-42', 'recipes', 42, 'Omelette mit Kräutern', 'https://recipes.test/recipes/42', null, true, $idea->key],
            [$recipes[0]['key'], $recipes[0]['source'], $recipes[0]['recipe_id'], $recipes[0]['name'], $recipes[0]['url'], $recipes[0]['draft_url'], $recipes[0]['planned'], $recipes[0]['idea_key']],
        );
        $this->assertSame([['amount' => 4, 'unit' => 'Stk.', 'name' => 'Eier'], ['amount' => 1, 'unit' => 'Bund', 'name' => 'Schnittlauch']], $recipes[0]['ingredients']);
        $this->assertSame(['Eier'], $recipes[0]['uses_pantry']);
        $this->assertArrayNotHasKey('instructions', $recipes[0]);
        $this->assertNull($idea->fresh()->draft_id);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://recipes.test/api/recipe-drafts/'.self::DRAFT && $request->hasHeader('Authorization', 'Bearer token'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://recipes.test/api/recipes/42?servings=2'));

        // Planning it again later works like an own recipe (one entry per recipe id).
        Http::fake(['*' => Http::response([], 500)]);
        $this->actingAs($user)->postJson('/planned-recipes', ['key' => 'recipe-42', 'source' => 'recipes', 'recipe_id' => 42, 'name' => 'Omelette mit Kräutern'])->assertOk();
        $this->assertSame(1, PlannedRecipe::count());
    }

    public function test_another_entry_of_the_recipe_is_merged(): void
    {
        $user = $this->connected();
        $idea = $this->idea($user, ['planned' => false]);
        $other = new PlannedRecipe(['key' => 'recipe-42', 'source' => 'recipes', 'recipe_id' => 42, 'name' => 'Omelette', 'data' => [], 'planned' => true, 'planned_at' => now()->subMinutes(5)]);
        $other->user()->associate($user)->save();
        Http::fake([
            'recipes.test/api/recipe-drafts/*' => Http::response($this->draft('imported', ['id' => 42, 'name' => 'Omelette', 'url' => 'https://recipes.test/recipes/42'])),
            // Ingredients not available: loaded when needed.
            'recipes.test/api/recipes/42*' => Http::response([], 500),
        ]);

        $this->actingAs($user)->getJson('/planned-recipes')->assertOk()->assertJsonCount(1, 'recipes');

        $this->assertModelMissing($other);
        $idea->refresh();
        $this->assertSame(['recipe-42', true], [$idea->key, $idea->planned]);
        $this->assertArrayNotHasKey('ingredients', $idea->data);
    }

    public function test_pending_drafts_stay_and_are_asked_for_again_after_a_while(): void
    {
        $user = $this->connected();
        $idea = $this->idea($user);
        Http::fake(['recipes.test/api/recipe-drafts/*' => Http::response($this->draft('pending'))]);

        $this->actingAs($user)->getJson('/planned-recipes')->assertOk()->assertJsonPath('recipes.0.key', $idea->key);
        $this->actingAs($user)->getJson('/planned-recipes')->assertOk();
        Http::assertSentCount(1);

        Cache::forget('recipe-drafts:'.$user->id);
        $this->travel(RecipeDrafts::CHECK_SECONDS + 1)->seconds();
        $this->actingAs($user)->getJson('/planned-recipes')->assertOk();
        Http::assertSentCount(2);
        $this->assertSame([self::DRAFT, $idea->draft_url], [$idea->fresh()->draft_id, $idea->fresh()->draft_url]);
    }

    public function test_an_expired_draft_can_be_handed_over_again(): void
    {
        $user = $this->connected();
        $idea = $this->idea($user);
        Http::fake(['recipes.test/api/recipe-drafts/*' => Http::response($this->draft('expired'))]);

        $this->actingAs($user)->getJson('/planned-recipes')->assertOk()->assertJsonPath('recipes.0.draft_url', null);

        $this->assertSame([null, 'ai'], [$idea->fresh()->draft_id, $idea->fresh()->source]);
    }

    public function test_older_recipe_apps_and_failures_leave_the_entry_as_it_is(): void
    {
        $user = $this->connected();
        $idea = $this->idea($user);
        $old = $this->idea($user, ['key' => 'idea-0190a0b0-0000-7000-8000-000000000002', 'draft_id' => (string) Str::uuid(), 'draft_expires_at' => now()->subDay()]);
        Http::fake(['recipes.test/api/recipe-drafts/*' => Http::response(['message' => 'Not Found.'], 404)]);

        $this->actingAs($user)->getJson('/planned-recipes')->assertOk()->assertJsonCount(2, 'recipes');

        // Not known (yet): kept; after the expiry it cannot be imported anymore, it is no longer asked for.
        $this->assertSame([self::DRAFT, $idea->draft_url], [$idea->fresh()->draft_id, $idea->fresh()->draft_url]);
        $this->assertSame([null, $old->draft_url], [$old->fresh()->draft_id, $old->fresh()->draft_url]);

        // Recipe app down: the list still loads.
        Cache::flush();
        Http::fake(['recipes.test/*' => Http::response([], 503)]);
        $this->actingAs($user)->getJson('/planned-recipes')->assertOk()->assertJsonCount(2, 'recipes');
        $this->assertSame(self::DRAFT, $idea->fresh()->draft_id);
    }

    public function test_nothing_is_asked_without_drafts_or_connection(): void
    {
        Http::fake();
        $user = $this->connected();
        $this->idea($user, ['draft_id' => null]);
        $this->actingAs($user)->getJson('/planned-recipes')->assertOk();

        $other = User::factory()->create();
        $this->idea($other);
        $this->actingAs($other)->getJson('/planned-recipes')->assertOk();

        Http::assertNothingSent();
    }
}
