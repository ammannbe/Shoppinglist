<?php

namespace Tests\Feature;

use App\Recipes\RecipeIngredients;
use Tests\TestCase;

class RecipeIngredientsTest extends TestCase
{
    public function test_foods_with_different_units_use_the_converted_amount(): void
    {
        $unified = RecipeIngredients::unify([
            0 => [
                ['amount' => 1, 'unit' => 'Tas', 'food_id' => 7, 'food' => 'Mehl', 'converted' => ['amount' => 110, 'amount_max' => null, 'unit' => 'g']],
                ['amount' => 2, 'unit' => 'Stk', 'food_id' => 9, 'food' => 'Zwiebeln', 'converted' => ['amount' => 200, 'amount_max' => null, 'unit' => 'g']],
                ['amount' => 3, 'unit' => 'dl', 'food_id' => 11, 'food' => 'Milch', 'converted' => ['amount' => 3, 'amount_max' => null, 'unit' => 'dl']],
            ],
            2 => [
                ['amount' => 0.2, 'unit' => 'kg', 'food_id' => 7, 'food' => 'Mehl', 'converted' => ['amount' => 200, 'amount_max' => null, 'unit' => 'g']],
                ['amount' => 1, 'unit' => 'Stk', 'food_id' => 9, 'food' => 'Zwiebeln', 'converted' => ['amount' => 100, 'amount_max' => null, 'unit' => 'g']],
                ['amount' => 2, 'unit' => 'EL', 'food_id' => 11, 'food' => 'Milch', 'converted' => ['amount' => 30, 'amount_max' => null, 'unit' => 'ml']],
                ['amount' => 1, 'unit' => 'Prise', 'food_id' => 12, 'food' => 'Salz', 'converted' => null],
            ],
        ]);

        $this->assertSame([0, 2], array_keys($unified));
        $this->assertSame([
            ['amount' => 110.0, 'unit' => 'g', 'name' => 'Mehl'],
            // Same unit in every recipe: kept, pieces stay pieces.
            ['amount' => 2, 'unit' => 'Stk', 'name' => 'Zwiebeln'],
            ['amount' => 300.0, 'unit' => 'ml', 'name' => 'Milch'],
        ], $unified[0]);
        $this->assertSame(['amount' => 200.0, 'unit' => 'g', 'name' => 'Mehl'], $unified[2][0]);
        $this->assertSame(['amount' => 30.0, 'unit' => 'ml', 'name' => 'Milch'], $unified[2][2]);
        $this->assertSame(['amount' => 1, 'unit' => 'Prise', 'name' => 'Salz'], $unified[2][3]);
    }

    public function test_older_recipe_apps_without_conversion_keep_the_units(): void
    {
        $unified = RecipeIngredients::unify([[
            ['amount' => 1, 'unit' => 'Tas', 'food' => 'Mehl', 'text' => '1 Tas Mehl'],
            ['amount' => 200, 'unit' => 'g', 'food' => 'Mehl', 'text' => '200 g Mehl'],
        ]]);

        $this->assertSame([
            ['amount' => 1, 'unit' => 'Tas', 'name' => 'Mehl'],
            ['amount' => 200, 'unit' => 'g', 'name' => 'Mehl'],
        ], $unified[0]);
    }
}
