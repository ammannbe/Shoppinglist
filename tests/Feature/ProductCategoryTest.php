<?php

namespace Tests\Feature;

use App\Console\Commands\SyncStandardProducts;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Product categories (sorting items by category): standard categories from the CSV, own
 * categories synced, the user's category order in the preferences.
 */
class ProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function push(User $user, string $collection, array $rows): TestResponse
    {
        return $this->actingAs($user)->postJson("/sync/{$collection}/push", $rows);
    }

    private function productDocument(User $user, string $id): ?array
    {
        return collect($this->actingAs($user)->getJson('/sync/products/pull?limit=500')->json('documents'))->firstWhere('id', $id);
    }

    private function own(User $user, string $name, ?string $category = null, ?string $image = null): Product
    {
        $product = new Product(['name' => $name, 'category' => $category]);
        $product->owner()->associate($user);
        $product->image = $image;
        $product->save();

        return $product;
    }

    public function test_the_csv_categories_are_known(): void
    {
        foreach (SyncStandardProducts::products() as ['name' => $name, 'category' => $category]) {
            $this->assertContains($category, ProductCategories::keys(), $name);
        }
        $this->assertSame('dairy', ProductCategories::fromCsv('Milchprodukte'));
        $this->assertNull(ProductCategories::fromCsv('Unbekannt'));
    }

    public function test_the_migration_sets_the_categories_of_the_standard_products(): void
    {
        $this->assertSame('dairy', Product::whereNull('owner_id')->where('name', 'Milch')->sole()->category);
        $this->assertSame('fruit', Product::whereNull('owner_id')->where('name', 'Äpfel')->sole()->category);
        $this->assertSame(0, Product::whereNull('owner_id')->whereNull('category')->count());
    }

    public function test_the_command_updates_changed_categories_and_keeps_own_categories(): void
    {
        $user = User::factory()->create();
        $milk = Product::whereNull('owner_id')->where('name', 'Milch')->sole();
        $milk->forceFill(['category' => 'drinks'])->save();
        $version = $milk->version;
        $override = $this->own($user, 'Butter', 'spreads');
        $sameCategory = $this->own($user, 'Eier', 'dairy');
        $withImage = $this->own($user, 'Brot', image: '0198f0f5-3b8f-7a8e-9c3e-1d2f3a4b5c6d');

        $this->artisan('shoppinglist:standard-products --dry-run')->assertSuccessful();
        $this->assertSame('drinks', $milk->fresh()->category);

        $this->artisan('shoppinglist:standard-products')->assertSuccessful();

        $this->assertSame('dairy', $milk->fresh()->category);
        $this->assertSame($version + 1, $milk->fresh()->version);
        $this->assertNotSoftDeleted($override);
        $this->assertSame('spreads', $override->fresh()->category);
        // An own copy with the standard category is a duplicate.
        $this->assertSame('dairy', Product::whereNull('owner_id')->where('name', 'Eier')->sole()->category);
        $this->assertSoftDeleted($sameCategory);
        $this->assertNotSoftDeleted($withImage);
    }

    public function test_categories_are_pulled_and_pushed(): void
    {
        $user = User::factory()->create();
        $milk = Product::whereNull('owner_id')->where('name', 'Milch')->sole();
        $this->assertSame('dairy', $this->productDocument($user, $milk->id)['category']);

        $id = (string) Str::uuid();
        $this->push($user, 'products', [['newDocumentState' => ['id' => $id, 'name' => 'Tofu', 'category' => 'pantry']]])
            ->assertOk()->assertExactJson([]);
        $doc = $this->productDocument($user, $id);
        $this->assertSame('pantry', $doc['category']);

        $this->push($user, 'products', [[
            'assumedMasterState' => $doc,
            'newDocumentState' => [...$doc, 'category' => 'frozen', 'version' => $doc['version'] + 1],
        ]])->assertOk()->assertExactJson([]);
        $this->assertSame('frozen', Product::find($id)->category);

        $this->push($user, 'products', [['newDocumentState' => ['id' => (string) Str::uuid(), 'name' => 'Seitan', 'category' => 'unknown']]])
            ->assertUnprocessable();
    }

    public function test_older_clients_keep_the_category(): void
    {
        $user = User::factory()->create();
        $product = $this->own($user, 'Tofu', 'pantry');
        $doc = $this->productDocument($user, $product->id);
        unset($doc['category']);

        // Pushed by a client from before the categories: renamed, no category.
        $this->push($user, 'products', [[
            'assumedMasterState' => $doc,
            'newDocumentState' => [...$doc, 'name' => 'Räuchertofu', 'version' => $doc['version'] + 1],
        ]])->assertOk()->assertExactJson([]);

        $this->assertSame('Räuchertofu', $product->fresh()->name);
        $this->assertSame('pantry', $product->fresh()->category);
    }

    public function test_lists_can_be_sorted_by_category(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::uuid();

        $this->push($user, 'lists', [['newDocumentState' => ['id' => $id, 'name' => 'Migros', 'item_sort' => 'category']]])
            ->assertOk()->assertExactJson([]);
        $this->assertSame('category', collect($this->actingAs($user)->getJson('/sync/lists/pull')->json('documents'))->firstWhere('id', $id)['item_sort']);

        // Only items are grouped by category, not the lists overview.
        $this->actingAs($user)->putJson('/preferences', ['list_sort' => 'category'])->assertUnprocessable();
    }

    public function test_the_category_order_is_a_preference(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/me')->assertJsonPath('preferences.category_order', []);

        $this->actingAs($user)->putJson('/preferences', ['category_order' => ['bread', 'fruit']])->assertNoContent();
        $this->actingAs($user->fresh())->getJson('/me')->assertJsonPath('preferences.category_order', ['bread', 'fruit']);

        $this->actingAs($user)->putJson('/preferences', ['category_order' => ['bread', 'cars']])->assertUnprocessable();
        $this->actingAs($user)->putJson('/preferences', ['category_order' => ['bread', 'bread']])->assertUnprocessable();
        $this->actingAs($user)->putJson('/preferences', ['category_order' => 'bread'])->assertUnprocessable();

        $this->actingAs($user)->putJson('/preferences', ['category_order' => []])->assertNoContent();
        $this->actingAs($user->fresh())->getJson('/me')->assertJsonPath('preferences.category_order', []);
    }
}
