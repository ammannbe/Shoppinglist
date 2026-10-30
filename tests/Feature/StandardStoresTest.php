<?php

namespace Tests\Feature;

use App\Console\Commands\SyncStandardStores;
use App\Models\ShoppingList;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardStoresTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_adds_the_standard_shops_with_icons_and_countries(): void
    {
        $this->assertSame(count(SyncStandardStores::shops()), Store::whereNull('owner_id')->count());
        $volg = Store::whereNull('owner_id')->where('name', 'Volg')->sole();
        $this->assertSame([null, 'volg', ['CH']], [$volg->code, $volg->icon, $volg->countries]);
        $this->assertSame('M', Store::whereNull('owner_id')->where('name', 'Migros')->value('code'));
    }

    public function test_every_icon_exists(): void
    {
        foreach (SyncStandardStores::shops() as $shop) {
            if ($shop['icon'] !== null) {
                $this->assertNotEmpty(glob(resource_path("images/shops/{$shop['icon']}.*")), $shop['name']);
            }
        }
    }

    public function test_own_duplicates_are_removed_and_their_items_retagged(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $volg = $this->own($user, 'volg', 'V');
        $landi = $this->own($other, 'Landi');
        $own = $this->own($user, 'Dorfladen');
        $list = new ShoppingList(['name' => 'Wochenende']);
        $list->owner()->associate($user);
        $list->save();
        $list->addMember($user);
        $item = $list->items()->forceCreate(['name' => 'Brot', 'stores' => ['V', 'M'], 'creator_id' => $user->id]);
        $untouched = $list->items()->forceCreate(['name' => 'Milch', 'stores' => ['M'], 'creator_id' => $user->id]);
        Store::whereNull('owner_id')->where('name', 'Spar')->forceDelete();

        $this->artisan('shoppinglist:standard-stores')->assertSuccessful();

        $this->assertSoftDeleted($volg);
        $this->assertSoftDeleted($landi);
        $this->assertNotSoftDeleted($own);
        $this->assertSame(2, $volg->fresh()->version);
        $this->assertSame(['Volg', 'M'], $item->fresh()->stores);
        $this->assertSame(2, $item->fresh()->version);
        $this->assertSame(1, $untouched->fresh()->version);
        $this->assertTrue(Store::whereNull('owner_id')->where('name', 'Spar')->exists());

        // Idempotent.
        $count = Store::withTrashed()->count();
        $this->artisan('shoppinglist:standard-stores')->assertSuccessful();
        $this->assertSame($count, Store::withTrashed()->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $store = $this->own(User::factory()->create(), 'Volg');

        $this->artisan('shoppinglist:standard-stores --dry-run')->assertSuccessful();

        $this->assertNotSoftDeleted($store);
    }

    private function own(User $user, string $name, ?string $code = null): Store
    {
        $store = new Store(['name' => $name, 'code' => $code]);
        $store->owner()->associate($user);
        $store->save();

        return $store;
    }
}
