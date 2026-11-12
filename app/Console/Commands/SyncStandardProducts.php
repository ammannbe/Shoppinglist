<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductCategories;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standard products (public, no owner) from database/data/standard-products.csv: adds missing
 * ones, sets their category, removes the ones no longer listed and deletes own products
 * duplicating a standard one. Own products with an image or an own category are kept, they
 * carry the user's photo or category of the standard product.
 * Idempotent; run by a migration whenever the list changes.
 */
#[Signature('shoppinglist:standard-products {--dry-run : Only report what would change}')]
#[Description('Sync the standard products with database/data/standard-products.csv and remove own duplicates')]
class SyncStandardProducts extends Command
{
    public const FILE = 'data/standard-products.csv';

    public function handle(): int
    {
        $products = self::products();
        $dry = (bool) $this->option('dry-run');
        // Earlier migrations run this command before the category column exists.
        $categories = Schema::hasColumn('products', 'category');
        $counts = ['added' => 0, 'category set' => 0, 'removed' => 0, 'duplicates' => 0, 'kept (image or category)' => 0];

        DB::transaction(function () use ($products, $dry, $categories, &$counts) {
            $public = Product::whereNull('owner_id')->get()->keyBy(fn (Product $product) => self::key($product->name));

            foreach ($products as $key => ['name' => $name, 'category' => $category]) {
                $product = $public->get($key);
                if ($product === null) {
                    $counts['added']++;
                    $dry || Product::create(['name' => $name, ...($categories ? ['category' => $category] : [])]);
                } elseif ($categories && $product->category !== $category) {
                    $counts['category set']++;
                    if (! $dry) {
                        // A new version and timestamp, so the clients pull it again.
                        $product->category = $category;
                        $product->version++;
                        $product->save();
                    }
                }
            }

            foreach ($public as $key => $product) {
                if (! isset($products[$key])) {
                    $counts['removed']++;
                    $dry || $product->delete();
                }
            }

            Product::whereNotNull('owner_id')->lazyById()->each(function (Product $product) use ($products, $dry, $categories, &$counts) {
                $standard = $products[self::key($product->name)] ?? null;
                if ($standard === null) {
                    return;
                }
                $ownCategory = $categories && $product->category !== null && $product->category !== $standard['category'];
                if ($product->image !== null || $ownCategory) {
                    $counts['kept (image or category)']++;

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
        return array_map(fn (array $product) => $product['name'], self::products());
    }

    /**
     * @return array<string, array{name: string, category: string|null}> key => name and category key
     */
    public static function products(): array
    {
        $rows = array_map('str_getcsv', file(database_path(self::FILE), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        array_shift($rows);

        return collect($rows)
            ->map(fn (array $row) => ['name' => trim($row[0]), 'category' => ProductCategories::fromCsv($row[1] ?? null)])
            ->filter(fn (array $product) => $product['name'] !== '')
            ->keyBy(fn (array $product) => self::key($product['name']))->all();
    }

    /** Same product: case and spaces are ignored (like the client's sameName). */
    public static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }
}
