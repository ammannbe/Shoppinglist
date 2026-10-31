<?php

namespace App\Support;

/**
 * Foods in season in Switzerland (fresh harvest; storage produce does not count), for the
 * week planner. Uses the season data of the connected recipe app when it has some, otherwise
 * the copy in database/data/food-seasons.php.
 */
class Seasons
{
    public const FILE = 'data/food-seasons.php';

    /** Current month in Switzerland. */
    public static function month(): int
    {
        return (int) now('Europe/Zurich')->format('n');
    }

    /**
     * Names of the foods in season in the month.
     *
     * @param  list<array<string, mixed>>|null  $foods  foods of the recipe app (/api/foods)
     * @return list<string>
     */
    public static function names(int $month, ?array $foods = null): array
    {
        $withData = array_filter($foods ?? [], fn (array $food) => ! empty($food['season_months']));
        if ($withData !== []) {
            return array_values(array_map(
                fn (array $food) => (string) $food['name'],
                array_filter($withData, fn (array $food) => in_array($month, $food['season_months'], true)),
            ));
        }

        return array_keys(array_filter(require database_path(self::FILE), fn (array $months) => in_array($month, $months[0], true)));
    }

    /**
     * Ids of the recipe app's foods in season in the month (none without season data).
     *
     * @param  list<array<string, mixed>>  $foods
     * @return list<int>
     */
    public static function foodIds(int $month, array $foods): array
    {
        return array_values(array_map(
            fn (array $food) => (int) $food['id'],
            array_filter($foods, fn (array $food) => in_array($month, $food['season_months'] ?? [], true)),
        ));
    }
}
