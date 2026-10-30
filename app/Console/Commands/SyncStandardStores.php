<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Standard shops (public, no owner) from database/data/standard-stores.php: adds missing ones,
 * updates code, icon and countries, removes the ones no longer listed. Own shops with the name
 * or code of a standard shop are deleted, the items of their owner get the standard shop's tag.
 * Idempotent; run by a migration whenever the list changes.
 */
#[Signature('shoppinglist:standard-stores {--dry-run : Only report what would change}')]
#[Description('Sync the standard shops with database/data/standard-stores.php and remove own duplicates')]
class SyncStandardStores extends Command
{
    public const FILE = 'data/standard-stores.php';

    public function handle(): int
    {
        $shops = self::shops();
        $dry = (bool) $this->option('dry-run');
        $counts = ['added' => 0, 'updated' => 0, 'removed' => 0, 'duplicates' => 0, 'items retagged' => 0];

        DB::transaction(function () use ($shops, $dry, &$counts) {
            $public = Store::whereNull('owner_id')->get()->keyBy(fn (Store $store) => self::key($store->name));

            foreach ($shops as $key => $shop) {
                $store = $public->get($key) ?? new Store;
                $store->fill(['name' => $shop['name'], 'code' => $shop['code'], 'icon' => $shop['icon'], 'countries' => $shop['countries']]);
                if (! $store->exists) {
                    $counts['added']++;
                } elseif ($store->isDirty()) {
                    $counts['updated']++;
                    $store->version++;
                } else {
                    continue;
                }
                $dry || $store->save();
            }

            foreach ($public as $key => $store) {
                if (! isset($shops[$key])) {
                    $counts['removed']++;
                    if (! $dry) {
                        $store->version++;
                        $store->save();
                        $store->delete();
                    }
                }
            }

            // Name or code of a standard shop (both are tags) => the own shop is a duplicate.
            $tags = [];
            foreach ($shops as $key => $shop) {
                $tags[$key] = $shop['code'] ?? $shop['name'];
                if ($shop['code'] !== null) {
                    $tags[self::key($shop['code'])] = $shop['code'];
                }
            }

            Store::whereNotNull('owner_id')->with('owner')->lazyById()->each(function (Store $store) use ($tags, $dry, &$counts) {
                $tag = $tags[self::key($store->name)] ?? ($store->code !== null ? $tags[self::key($store->code)] ?? null : null);
                if ($tag === null) {
                    return;
                }
                $counts['duplicates']++;
                $counts['items retagged'] += $this->retag($store->owner, $store->code ?? $store->name, $tag, $dry);
                if (! $dry) {
                    // A new version, so clients take the deletion instead of seeing a conflict.
                    $store->version++;
                    $store->save();
                    $store->delete();
                }
            });
        });

        $this->table(['', 'Shops'], collect($counts)->map(fn (int $count, string $label) => [$label, $count])->values());

        return self::SUCCESS;
    }

    /**
     * Replaces the tag of a removed own shop on the items of the owner's lists.
     */
    private function retag(?User $owner, string $old, string $new, bool $dry): int
    {
        if ($owner === null) {
            return 0;
        }
        $count = 0;
        Item::whereIn('shopping_list_id', $owner->accessibleListIds())->whereNotNull('stores')->lazyById()
            ->each(function (Item $item) use ($old, $new, $dry, &$count) {
                $stores = $item->stores ?? [];
                if (! collect($stores)->contains(fn (string $tag) => self::key($tag) === self::key($old))) {
                    return;
                }
                $count++;
                if (! $dry) {
                    $item->stores = collect($stores)->map(fn (string $tag) => self::key($tag) === self::key($old) ? $new : $tag)
                        ->unique(fn (string $tag) => self::key($tag))->values()->all();
                    $item->version++;
                    $item->save();
                }
            });

        return $count;
    }

    /**
     * @return array<string, array{name: string, code: ?string, icon: string, countries: list<string>}> key => shop
     */
    public static function shops(): array
    {
        return collect(require database_path(self::FILE))
            ->map(fn (array $shop, string $name) => ['name' => $name, 'code' => $shop[0], 'icon' => $shop[1], 'countries' => $shop[2]])
            ->keyBy(fn (array $shop) => self::key($shop['name']))->all();
    }

    /** Same shop: case and spaces are ignored (like the client's sameName). */
    public static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }
}
