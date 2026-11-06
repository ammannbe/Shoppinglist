<?php

namespace Tests\Feature;

use App\Support\FoodNames;
use Tests\TestCase;

class FoodNamesTest extends TestCase
{
    public function test_products_are_matched_with_ingredient_names(): void
    {
        $offers = ['Rindshackfleisch', 'Bio-Kartoffeln 1 kg', 'Tomaten', 'Rüebli', 'Kaffee', 'Eierbrei'];

        $this->assertSame(
            ['Rindshackfleisch', 'Bio-Kartoffeln 1 kg', 'Tomaten', 'Rüebli'],
            FoodNames::used(['Hackfleisch', 'Kartoffel', 'Cherrytomaten', 'Rüebli (in Stücken)', 'Ei', 'Salz'], $offers),
        );
        // The main word counts: "Rote Linsen" is not "Rote Zwiebeln".
        $this->assertSame(['Linsen'], FoodNames::used(['Rote Linsen'], ['Linsen', 'Rote Zwiebeln']));
        $this->assertSame([], FoodNames::used([], $offers));
    }
}
