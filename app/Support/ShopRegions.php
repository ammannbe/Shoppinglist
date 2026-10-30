<?php

namespace App\Support;

/**
 * Shops whose offers differ by region, by shop tag (code). Offers of these shops carry the
 * region they apply to (`offers.region`); users pick theirs per shop in the settings
 * (`preferences.regions`) and then only see offers of their region and offers without one.
 *
 * Migros: the ten regional cooperatives have their own offers (besides the national ones).
 * Coop has national offers, its sales regions are logistics only. Migros plans to merge the
 * cooperatives (vote mid 2027); then remove its entry here.
 */
class ShopRegions
{
    /**
     * Tag => region key => name (with the cantons it mainly covers, the names stay as the shop uses them).
     */
    public const ALL = [
        'M' => [
            'migros-aare' => 'Migros Aare (AG, BE, SO)',
            'migros-basel' => 'Migros Basel (BL, BS, JU)',
            'migros-geneve' => 'Migros Genève (GE)',
            'migros-luzern' => 'Migros Luzern (LU, NW, OW, SZ, UR, ZG)',
            'migros-neuchatel-fribourg' => 'Migros Neuchâtel-Fribourg (FR, NE)',
            'migros-ostschweiz' => 'Migros Ostschweiz (AI, AR, GR, SG, SH, TG, Winterthur)',
            'migros-ticino' => 'Migros Ticino (TI)',
            'migros-vaud' => 'Migros Vaud (VD)',
            'migros-wallis' => 'Migros Wallis (VS)',
            'migros-zuerich' => 'Migros Zürich (GL, ZH)',
        ],
    ];

    /**
     * Region keys of a shop tag (empty: the shop's offers apply everywhere).
     *
     * @return list<string>
     */
    public static function of(?string $tag): array
    {
        return array_keys(self::ALL[$tag ?? ''] ?? []);
    }

    /**
     * All region keys.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_merge(...array_values(array_map(array_keys(...), self::ALL)));
    }

    /**
     * The region if it belongs to the shop, otherwise null.
     */
    public static function valid(?string $tag, mixed $region): ?string
    {
        return is_string($region) && in_array($region, self::of($tag), true) ? $region : null;
    }

    /**
     * Regions a user does not see: the other regions of the shops they picked a region for.
     *
     * @param  array<string, mixed>  $chosen  tag => region key (preferences.regions)
     * @return list<string>
     */
    public static function hidden(array $chosen): array
    {
        $hidden = [];
        foreach ($chosen as $tag => $region) {
            if (self::valid((string) $tag, $region) !== null) {
                $hidden = [...$hidden, ...array_diff(self::of((string) $tag), [$region])];
            }
        }

        return array_values($hidden);
    }
}
