<?php

namespace App\Console\Commands;

use App\Models\ShoppingList;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports the data of the old Laravel 7 API (users keyed by email,
 * auto increment IDs) from the "legacy" database connection.
 */
#[Signature('shoppinglist:import-legacy {--connection=legacy : Database connection of the old API}')]
#[Description('Import users, lists, items, products and units from the old Shoppinglist API database')]
class ImportLegacyData extends Command
{
    private Connection $legacy;

    /** @var array<string, int> legacy email => new user id */
    private array $users = [];

    /** @var array<int, string> legacy list id => new UUID */
    private array $lists = [];

    public function handle(): int
    {
        $this->legacy = DB::connection($this->option('connection'));

        if (ShoppingList::withTrashed()->exists()) {
            $this->error('The target database already contains shopping lists. Import aborted.');

            return self::FAILURE;
        }

        DB::transaction(function () {
            $this->importUsers();
            $this->importLists();
            $this->importMemberships();
            $this->importItems();
            $this->importProducts();
            $this->importUnits();
        });

        $this->table(['Table', 'Rows'], collect(['users', 'shopping_lists', 'shopping_list_user', 'items', 'products', 'units'])
            ->map(fn ($table) => [$table, DB::table($table)->count()]));

        return self::SUCCESS;
    }

    private function importUsers(): void
    {
        foreach ($this->legacy->table('users')->orderBy('created_at')->cursor() as $user) {
            $email = Str::lower($user->email);

            // Password hashes are copied as they are, Laravel rehashes them on the next login.
            $this->users[$user->email] = DB::table('users')->insertGetId([
                'email' => $email,
                'email_verified_at' => $user->email_verified_at,
                'password' => $user->password,
                'remember_token' => null,
                'created_at' => $user->created_at ?? now(),
                'updated_at' => $user->updated_at ?? now(),
            ]);
        }
    }

    private function importLists(): void
    {
        foreach ($this->legacy->table('shopping_lists')->orderBy('id')->cursor() as $list) {
            $this->lists[$list->id] = (string) Str::uuid7();

            DB::table('shopping_lists')->insert([
                'id' => $this->lists[$list->id],
                'owner_id' => $this->users[$list->owner_email],
                'name' => $list->name,
                ...$this->timestamps($list),
            ]);
        }
    }

    private function importMemberships(): void
    {
        $pairs = $this->legacy->table('shopping_list_user')->get()
            ->map(fn ($row) => [$row->shopping_list_id, $row->user_email]);

        // The owner has always been a member, make sure no list loses its owner.
        $owners = $this->legacy->table('shopping_lists')->get()
            ->map(fn ($list) => [$list->id, $list->owner_email]);

        $pairs->concat($owners)
            ->unique(fn ($pair) => $pair[0].'|'.$pair[1])
            ->each(fn ($pair) => DB::table('shopping_list_user')->insert([
                'id' => (string) Str::uuid7(),
                'shopping_list_id' => $this->lists[$pair[0]],
                'user_id' => $this->users[$pair[1]],
                ...$this->timestamps(null),
            ]));
    }

    private function importItems(): void
    {
        foreach ($this->legacy->table('items')->orderBy('id')->cursor() as $item) {
            DB::table('items')->insert([
                'id' => (string) Str::uuid7(),
                'shopping_list_id' => $this->lists[$item->shopping_list_id],
                'creator_id' => $this->users[$item->creator_email] ?? null,
                'name' => $item->product_name,
                'amount' => $item->amount,
                'unit' => $item->unit_name,
                'done' => (bool) $item->done,
                ...$this->timestamps($item),
            ]);
        }
    }

    private function importProducts(): void
    {
        foreach ($this->legacy->table('products')->orderBy('id')->cursor() as $product) {
            DB::table('products')->insert([
                'id' => (string) Str::uuid7(),
                'owner_id' => $product->owner_email ? $this->users[$product->owner_email] : null,
                'name' => $product->name,
                ...$this->timestamps($product),
            ]);
        }
    }

    private function importUnits(): void
    {
        foreach ($this->legacy->table('units')->orderBy('id')->cursor() as $unit) {
            DB::table('units')->insert([
                'id' => (string) Str::uuid7(),
                'name' => $unit->name,
                ...$this->timestamps($unit),
            ]);
        }
    }

    /**
     * Sync checkpoints need millisecond timestamps.
     *
     * @return array{created_at: string, updated_at: string}
     */
    private function timestamps(?object $row): array
    {
        $format = fn ($value) => Carbon::parse($value ?? now())->format(ShoppingList::SYNC_DATE_FORMAT);

        return [
            'created_at' => $format($row->created_at ?? null),
            'updated_at' => $format($row->updated_at ?? null),
        ];
    }
}
