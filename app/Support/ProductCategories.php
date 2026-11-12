<?php

namespace App\Support;

/**
 * Product categories (`products.category`) in the default walking order of a shop, from
 * database/data/product-categories.json (also imported by the client, resources/js/categories.js).
 * `csv` is the German name used in database/data/standard-products.csv.
 */
class ProductCategories
{
    public const FILE = 'data/product-categories.json';

    /** @var list<array{key: string, label: string, csv: string}>|null */
    private static ?array $all = null;

    /**
     * @return list<array{key: string, label: string, csv: string}>
     */
    public static function all(): array
    {
        return self::$all ??= json_decode(file_get_contents(database_path(self::FILE)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string> keys in the default order
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /** Key of a category name of the CSV file, null if unknown or empty. */
    public static function fromCsv(?string $name): ?string
    {
        $name = trim((string) $name);

        return collect(self::all())->first(fn (array $category) => mb_strtolower($category['csv']) === mb_strtolower($name))['key'] ?? null;
    }
}
