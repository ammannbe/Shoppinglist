<?php

/**
 * Standard shops (public, no owner), synced by `shoppinglist:standard-stores`.
 *
 * name => [code, icon, countries]. The code (optional) is the tag on the items, otherwise the
 * name; keep the codes of the week planner (App\Ai\Assistant::SHOPS). The icon is the file name
 * without extension in resources/images/shops (null: the badge shows the tag); SVG where the shop's
 * mark exists as one, otherwise a small PNG. Countries: where the shop is offered in the shop
 * choice (users pick their country in the settings).
 */
$all = ['CH', 'DE', 'AT'];

return [
    // Switzerland, groceries
    'Migros' => ['M', 'migros', ['CH']],
    'Coop' => ['C', 'coop', ['CH']],
    'Denner' => ['D', 'denner', ['CH']],
    'Aldi' => ['A', 'aldi', $all],
    'Lidl' => ['L', 'lidl', $all],
    'Volg' => [null, 'volg', ['CH']],
    'Spar' => [null, 'spar', ['CH', 'AT']],
    'Manor' => [null, 'manor', ['CH']],
    'Globus' => [null, null, ['CH']],
    'Migrolino' => [null, null, ['CH']],
    'Coop Pronto' => [null, 'coop-pronto', ['CH']],
    'Avec' => [null, null, ['CH']],
    'Migros Online' => [null, 'migros', ['CH']],
    'Farmy' => [null, null, ['CH']],
    'Aligro' => [null, 'aligro', ['CH']],
    'Topcc' => [null, null, ['CH']],
    'Prodega' => [null, 'prodega', ['CH']],
    'Landi' => [null, 'landi', ['CH']],
    "Otto's" => [null, 'ottos', ['CH']],

    // Pharmacy and drugstore
    'Apotheke' => [null, 'apotheke', $all],
    'Drogerie' => [null, 'drogerie', $all],
    'Amavita' => [null, 'amavita', ['CH']],
    'Sun Store' => [null, null, ['CH']],
    'Coop Vitality' => [null, 'coop-vitality', ['CH']],
    'dm' => [null, 'dm', $all],
    'Müller' => [null, 'mueller', $all],
    'Rossmann' => [null, 'rossmann', ['DE']],

    // Home, DIY, electronics, pets, online
    'IKEA' => [null, 'ikea', $all],
    'Jysk' => [null, 'jysk', $all],
    'Jumbo' => [null, 'jumbo', ['CH']],
    'Do it + Garden' => [null, 'do-it-garden', ['CH']],
    'Bauhaus' => [null, 'bauhaus', $all],
    'Hornbach' => [null, 'hornbach', $all],
    'OBI' => [null, 'obi', $all],
    'Globus Baumarkt' => [null, 'globus-baumarkt', ['DE']],
    'Interdiscount' => [null, 'interdiscount', ['CH']],
    'MediaMarkt' => [null, 'mediamarkt', $all],
    'Fust' => [null, null, ['CH']],
    'Digitec' => [null, null, ['CH']],
    'Galaxus' => [null, 'galaxus', ['CH', 'DE', 'AT']],
    'Fressnapf' => [null, 'fressnapf', $all],
    'Qualipet' => [null, null, ['CH']],
    'Amazon' => [null, 'amazon', $all],
    'Temu' => [null, 'temu', $all],

    // Germany, groceries
    'Rewe' => [null, 'rewe', ['DE']],
    'Edeka' => [null, 'edeka', ['DE']],
    'Kaufland' => [null, 'kaufland', ['DE']],
    'Penny' => [null, 'penny', ['DE', 'AT']],
    'Netto' => [null, 'netto', ['DE']],
    'Hieber' => [null, 'hieber', ['DE']],
];
