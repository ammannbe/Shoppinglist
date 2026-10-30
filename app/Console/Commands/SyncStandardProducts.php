<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Standard products (public, no owner) from database/data/standard-products.csv: adds missing
 * ones, removes the ones no longer listed and deletes own products duplicating a standard one.
 * Own products with an image are kept, they carry the user's photo of the standard product.
 * Idempotent; run by a migration whenever the list changes.
 */
#[Signature('shoppinglist:standard-products {--dry-run : Only report what would change}')]
#[Description('Sync the standard products with database/data/standard-products.csv and remove own duplicates')]
class SyncStandardProducts extends Command
{
    public const FILE = 'data/standard-products.csv';

    public function handle(): int
    {
        $names = self::names();
        $dry = (bool) $this->option('dry-run');
        $counts = ['added' => 0, 'removed' => 0, 'duplicates' => 0, 'kept with image' => 0];

        DB::transaction(function () use ($names, $dry, &$counts) {
            $public = Product::whereNull('owner_id')->get()->keyBy(fn (Product $product) => self::key($product->name));

            foreach ($names as $key => $name) {
                if (! $public->has($key)) {
                    $counts['added']++;
                    $dry || Product::create(['name' => $name]);
                }
            }

            foreach ($public as $key => $product) {
                if (! isset($names[$key])) {
                    $counts['removed']++;
                    $dry || $product->delete();
                }
            }

            Product::whereNotNull('owner_id')->lazyById()->each(function (Product $product) use ($names, $dry, &$counts) {
                if (! isset($names[self::key($product->name)])) {
                    return;
                }
                if ($product->image !== null) {
                    $counts['kept with image']++;

                    return;
                }
                $counts['duplicates']++;
                if (! $dry) {
                    // A new version, so clients take the deletion instead of seeing a conflict.
                    $product->version++;
                    $product->save();
                    $product->delete();
                }
            });
        });

        $this->table(['', 'Products'], collect($counts)->map(fn (int $count, string $label) => [$label, $count])->values());

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> key => name
     */
    public static function names(): array
    {
        $rows = array_map('str_getcsv', file(database_path(self::FILE), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        array_shift($rows);

        return collect($rows)->map(fn (array $row) => trim($row[0]))->filter()
            ->keyBy(fn (string $name) => self::key($name))->all();
    }

    /** Same product: case and spaces are ignored (like the client's sameName). */
    public static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }
}
