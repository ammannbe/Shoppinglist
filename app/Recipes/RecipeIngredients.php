<?php

namespace App\Recipes;

/**
 * Ingredients of the recipe app's recipes for the shopping list. A food used with different units
 * (1 Tasse Mehl, 200 g Mehl) gets the recipe app's conversion (`converted`, g/kg or ml/dl/l) in
 * g or ml, so the amounts can be added up; foods with one unit keep it (2 Stk. Zwiebeln).
 */
class RecipeIngredients
{
    private const BASE = ['g' => 1, 'kg' => 1000, 'ml' => 1, 'dl' => 100, 'l' => 1000];

    /**
     * @param  array<int, list<array<string, mixed>>>  $recipes  API ingredients per recipe key
     * @return array<int, list<array{amount: int|float|null, unit: ?string, name: string}>>
     */
    public static function unify(array $recipes): array
    {
        $units = [];
        foreach ($recipes as $ingredients) {
            foreach ($ingredients as $ingredient) {
                $units[self::key($ingredient)][mb_strtolower((string) ($ingredient['unit'] ?? ''))] = true;
            }
        }

        return array_map(fn (array $ingredients) => array_map(function (array $ingredient) use ($units) {
            $name = $ingredient['food'] ?? $ingredient['text'] ?? '';
            $converted = $ingredient['converted'] ?? null;

            if (count($units[self::key($ingredient)]) > 1 && is_array($converted) && isset(self::BASE[$converted['unit'] ?? ''], $converted['amount'])) {
                $base = self::BASE[$converted['unit']];

                return [
                    'amount' => round($converted['amount'] * $base, 2),
                    'unit' => in_array($converted['unit'], ['g', 'kg'], true) ? 'g' : 'ml',
                    'name' => $name,
                ];
            }

            return ['amount' => $ingredient['amount'] ?? null, 'unit' => $ingredient['unit'] ?? null, 'name' => $name];
        }, $ingredients), $recipes);
    }

    /**
     * Ingredients of a recipe app recipe as saved with the recipe (amounts for its servings).
     *
     * @param  list<array<string, mixed>>  $ingredients  API ingredients
     * @return list<array{amount: mixed, unit: ?string, name: string}>
     */
    public static function forSaving(array $ingredients): array
    {
        $saved = [];
        foreach (array_slice($ingredients, 0, 100) as $ingredient) {
            $name = trim((string) ($ingredient['food'] ?? $ingredient['text'] ?? ''));
            if ($name !== '') {
                $saved[] = [
                    'amount' => is_numeric($ingredient['amount'] ?? null) ? $ingredient['amount'] : null,
                    'unit' => filled($ingredient['unit'] ?? null) ? mb_substr((string) $ingredient['unit'], 0, 50) : null,
                    'name' => mb_substr($name, 0, 255),
                ];
            }
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $ingredient
     */
    private static function key(array $ingredient): string
    {
        return isset($ingredient['food_id']) ? 'id:'.$ingredient['food_id'] : 'name:'.mb_strtolower((string) ($ingredient['food'] ?? $ingredient['text'] ?? ''));
    }
}
