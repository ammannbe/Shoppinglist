<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Product;
use App\Models\ShoppingList;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportLegacyDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->createLegacySchema();
    }

    /**
     * Schema of the old API (database/migrations of the Laravel 7 version).
     */
    private function createLegacySchema(): void
    {
        $schema = Schema::connection('legacy');

        $schema->create('users', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        $schema->create('shopping_lists', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('owner_email');
            $table->timestamps();
        });
        $schema->create('shopping_list_user', function (Blueprint $table) {
            $table->unsignedBigInteger('shopping_list_id');
            $table->string('user_email');
        });
        $schema->create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('owner_email')->nullable();
            $table->timestamps();
        });
        $schema->create('units', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->timestamps();
        });
        $schema->create('items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shopping_list_id');
            $table->string('product_name');
            $table->string('unit_name')->nullable();
            $table->string('creator_email');
            $table->decimal('amount', 8, 4)->nullable();
            $table->boolean('done')->default(0);
            $table->timestamps();
        });
    }

    private function seedLegacyData(): void
    {
        $legacy = DB::connection('legacy');
        $time = '2020-10-01 12:00:00';

        $legacy->table('users')->insert([
            ['email' => 'Anna@example.com', 'email_verified_at' => $time, 'password' => Hash::make('secret-anna'), 'created_at' => $time, 'updated_at' => $time],
            ['email' => 'ben@example.com', 'email_verified_at' => null, 'password' => Hash::make('secret-ben'), 'created_at' => $time, 'updated_at' => $time],
        ]);
        $legacy->table('shopping_lists')->insert([
            ['id' => 7, 'name' => 'Wocheneinkauf', 'owner_email' => 'Anna@example.com', 'created_at' => $time, 'updated_at' => $time],
        ]);
        $legacy->table('shopping_list_user')->insert([
            ['shopping_list_id' => 7, 'user_email' => 'ben@example.com'],
        ]);
        $legacy->table('items')->insert([
            ['shopping_list_id' => 7, 'product_name' => 'Milch', 'unit_name' => 'l', 'creator_email' => 'ben@example.com', 'amount' => 1.5, 'done' => 1, 'created_at' => $time, 'updated_at' => $time],
        ]);
        $legacy->table('products')->insert([
            ['name' => 'Brot', 'owner_email' => null, 'created_at' => $time, 'updated_at' => $time],
            ['name' => 'Tofu', 'owner_email' => 'ben@example.com', 'created_at' => $time, 'updated_at' => $time],
        ]);
        $legacy->table('units')->insert([
            ['name' => 'kg', 'created_at' => $time, 'updated_at' => $time],
        ]);
    }

    public function test_it_imports_all_legacy_data(): void
    {
        $this->seedLegacyData();

        $this->artisan('shoppinglist:import-legacy')->assertSuccessful();

        $anna = User::firstWhere('email', 'anna@example.com');
        $ben = User::firstWhere('email', 'ben@example.com');
        $this->assertTrue(Hash::check('secret-anna', $anna->password));
        $this->assertNotNull($anna->email_verified_at);
        $this->assertNull($ben->email_verified_at);

        $list = ShoppingList::sole();
        $this->assertSame('Wocheneinkauf', $list->name);
        $this->assertTrue($list->isOwnedBy($anna));
        $this->assertTrue($list->hasMember($anna));
        $this->assertTrue($list->hasMember($ben));

        $item = Item::sole();
        $this->assertSame($list->id, $item->shopping_list_id);
        $this->assertSame(['Milch', 1.5, 'l', true, $ben->id], [$item->name, $item->amount, $item->unit, $item->done, $item->creator_id]);
        $this->assertSame('2020-10-01 12:00:00.000', $item->syncTimestamp());

        $this->assertNull(Product::firstWhere('name', 'Brot')->owner_id);
        $this->assertSame($ben->id, Product::firstWhere('name', 'Tofu')->owner_id);
        $this->assertSame('kg', Unit::sole()->name);
    }

    public function test_imported_users_can_log_in_with_their_old_password(): void
    {
        $this->seedLegacyData();
        $this->artisan('shoppinglist:import-legacy')->assertSuccessful();

        $this->post('/login', ['email' => 'anna@example.com', 'password' => 'secret-anna'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_it_refuses_to_import_twice(): void
    {
        $this->seedLegacyData();
        $this->artisan('shoppinglist:import-legacy')->assertSuccessful();

        $this->artisan('shoppinglist:import-legacy')->assertFailed();
        $this->assertSame(1, ShoppingList::count());
    }
}
