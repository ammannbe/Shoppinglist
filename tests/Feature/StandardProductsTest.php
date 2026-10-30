<?php

namespace Tests\Feature;

use App\Console\Commands\SyncStandardProducts;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_adds_the_standard_products(): void
    {
        $this->assertSame(count(SyncStandardProducts::names()), Product::whereNull('owner_id')->count());
        $this->assertTrue(Product::whereNull('owner_id')->where('name', 'Milch')->exists());
    }

    public function test_own_duplicates_without_image_are_removed_and_the_list_is_synced(): void
    {
        $user = User::factory()->create();
        $duplicate = $this->own($user, ' milch ');
        $withImage = $this->own($user, 'Eier', image: '0198f0f5-3b8f-7a8e-9c3e-1d2f3a4b5c6d');
        $other = $this->own($user, 'Grissini');
        $retired = Product::create(['name' => 'Gibt es nicht mehr']);
        Product::whereNull('owner_id')->where('name', 'Butter')->forceDelete();

        $this->artisan('shoppinglist:standard-products')->assertSuccessful();

        $this->assertSoftDeleted($duplicate);
        $this->assertSame(2, $duplicate->fresh()->version);
        $this->assertNotSoftDeleted($withImage);
        $this->assertNotSoftDeleted($other);
        $this->assertSoftDeleted($retired);
        $this->assertTrue(Product::whereNull('owner_id')->where('name', 'Butter')->exists());

        // Idempotent.
        $count = Product::count();
        $this->artisan('shoppinglist:standard-products')->assertSuccessful();
        $this->assertSame($count, Product::count());

        // The deletion reaches the client.
        $this->actingAs($user)->getJson('/sync/products/pull?limit=500')->assertOk()
            ->assertJsonFragment(['id' => $duplicate->id, '_deleted' => true]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $product = $this->own(User::factory()->create(), 'Milch');

        $this->artisan('shoppinglist:standard-products --dry-run')->assertSuccessful();

        $this->assertNotSoftDeleted($product);
    }

    private function own(User $user, string $name, ?string $image = null): Product
    {
        $product = new Product(['name' => $name]);
        $product->owner()->associate($user);
        $product->image = $image;
        $product->save();

        return $product;
    }
}
