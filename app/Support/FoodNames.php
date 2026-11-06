<?php

namespace App\Support;

/**
 * Matching of product names (offers, pantry) with recipe ingredients without the AI, like the
 * season matching (Seasons::among): a word of the product equals the ingredient's main word
 * (its last word), or one ends or starts with the other ("Rindshackfleisch" → "Hackfleisch",
 * "Cherrytomaten" → "Tomaten", "Rüebli 1 kg" → "Rüebli").
 */
class FoodNames
{
    /** Shorter words only match exactly ("Ei" is not in "Brei"). */
    private const MIN_PART = 4;

    /**
     * The products used by the ingredients, in the order of the products.
     *
     * @param  list<string>  $ingredients
     * @param  list<string>  $products
     * @return list<string>
     */
    public static function used(array $ingredients, array $products): array
    {
        $heads = array_values(array_filter(array_map(self::head(...), $ingredients)));
        $used = [];
        foreach ($products as $product) {
            foreach ($heads as $head) {
                if (self::matches($product, $head)) {
                    $used[$product] = true;
                    break;
                }
            }
        }

        return array_keys($used);
    }

    public static function matches(string $product, string $head): bool
    {
        foreach (self::words($product) as $word) {
            if ($word === $head) {
                return true;
            }
            if (mb_strlen($head) >= self::MIN_PART && (str_ends_with($word, $head) || str_starts_with($word, $head))) {
                return true;
            }
            if (mb_strlen($word) >= self::MIN_PART && (str_ends_with($head, $word) || str_starts_with($head, $word))) {
                return true;
            }
        }

        return false;
    }

    /** Main word of an ingredient: the last word ("Rote Linsen" → "linsen"), without notes in brackets. */
    private static function head(string $ingredient): ?string
    {
        $words = self::words(preg_replace('/\(.*?\)|,.*$/u', '', $ingredient));

        return $words === [] ? null : end($words);
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        return array_values(array_filter(
            preg_split('/[^\p{L}]+/u', mb_strtolower($name)) ?: [],
            fn (string $word) => mb_strlen($word) >= 2,
        ));
    }
}
