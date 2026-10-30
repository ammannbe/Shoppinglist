<?php

namespace App\Ai;

use App\Support\ShopRegions;
use Illuminate\Http\UploadedFile;

/**
 * The AI tasks of the planner: read offers (flyers, app screenshots, PDFs, text), read the
 * pantry from photos, suggest recipes and turn the chosen recipes into a shopping list.
 * Prompts in English, answers in the user's language (German by default).
 */
class Assistant
{
    /**
     * Shops recognised in offers and their codes (items.store).
     */
    public const SHOPS = ['Migros' => 'M', 'Coop' => 'C', 'Aldi' => 'A', 'Lidl' => 'L', 'Denner' => 'D'];

    /** Complexities of the recipe app's import format, used when it cannot be asked. */
    public const COMPLEXITIES = ['simple', 'normal', 'difficult'];

    public function __construct(private OpenAiClient $client) {}

    /**
     * @return list<array{name: string, brand: ?string, price: ?float, regular_price: ?float, discount: ?string, valid_from: ?string, valid_until: ?string, store: ?string, region: ?string}>
     */
    public function readOffers(?UploadedFile $file, ?string $text, string $language): array
    {
        $content = [OpenAiClient::text('Extract all offers from this material.')];
        if ($file) {
            $content[] = OpenAiClient::file($file);
        }
        if (filled($text)) {
            $content[] = OpenAiClient::text("Offers as text:\n".$text);
        }

        $result = $this->client->structured(
            'You read weekly grocery offers (promotions) of Swiss supermarkets from flyers, app screenshots, PDFs or text. '
            .'Return every food or household product with a visible promotion (discount, reduced price, "Hit", "2 für 1"), '
            .'one entry per product; skip products without a visible promotion and cut-off cards. Use a short, generic product name a shopper '
            .'would write on a list (e.g. "Rindshackfleisch" instead of a long marketing title) and put the brand or product line '
            .'separately. Prices in CHF as numbers. "discount" is the offer as written, e.g. "−30 %" or "2 für 1". '
            .'"valid_from" and "valid_until" as YYYY-MM-DD if visible (the current year is '.now()->year.'). "shop" is the retailer of the offer if recognisable from logos, '
            .'brands (e.g. M-Classic, Migros Bio, Sélection → Migros; Naturaplan, Prix Garantie → Coop) or the app design; '
            .'one flyer or screenshot usually belongs to one retailer, so use the same shop for all its offers unless clearly different. '
            .'"region" is the regional company the offers apply to, only if the material names it (e.g. "Migros Aare", '
            .'"Genossenschaft Migros Zürich", "aare.migros.ch"), otherwise null; offers valid in all of Switzerland have none. Regions: '
            .json_encode(ShopRegions::ALL, JSON_UNESCAPED_UNICODE).'. '
            .'Ignore non-offer content. Write names in '.$language.'.',
            $content,
            'offers',
            $this->object(['offers' => $this->list($this->object([
                'name' => ['type' => 'string'],
                'brand' => $this->nullable('string'),
                'price' => $this->nullable('number'),
                'regular_price' => $this->nullable('number'),
                'discount' => $this->nullable('string'),
                'valid_from' => $this->nullable('string'),
                'valid_until' => $this->nullable('string'),
                'shop' => ['type' => ['string', 'null'], 'enum' => [...array_keys(self::SHOPS), null]],
                'region' => ['type' => ['string', 'null'], 'enum' => [...ShopRegions::keys(), null]],
            ]))]),
        );

        return collect($result['offers'] ?? [])
            ->filter(fn ($offer) => filled($offer['name'] ?? null))
            ->map(fn (array $offer) => [
                'name' => $offer['name'],
                'brand' => $offer['brand'] ?? null,
                'price' => $offer['price'] ?? null,
                'regular_price' => $offer['regular_price'] ?? null,
                'discount' => $offer['discount'] ?? null,
                'valid_from' => $offer['valid_from'] ?? null,
                'valid_until' => $offer['valid_until'] ?? null,
                'store' => $store = self::SHOPS[$offer['shop'] ?? ''] ?? null,
                'region' => ShopRegions::valid($store, $offer['region'] ?? null),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<UploadedFile>  $photos
     * @return list<array{name: string, amount: ?string}>
     */
    public function readPantry(array $photos, string $language): array
    {
        $content = [OpenAiClient::text('What food is in these photos of a fridge, freezer or pantry?')];
        foreach ($photos as $photo) {
            $content[] = OpenAiClient::file($photo);
        }

        $result = $this->client->structured(
            'You list the food visible in photos of a fridge, freezer or pantry for a meal planner. Use short, generic names '
            .'(e.g. "Milch", "Eier", "Rüebli"), one entry per food, merge duplicates. "amount" is a rough amount if visible '
            .'(e.g. "1 l", "6 Stk.", "wenig"), otherwise null. Skip what you cannot recognise. Write names in '.$language.'.',
            $content,
            'pantry',
            $this->object(['items' => $this->list($this->object([
                'name' => ['type' => 'string'],
                'amount' => $this->nullable('string'),
            ]))]),
        );

        return collect($result['items'] ?? [])
            ->filter(fn ($item) => filled($item['name'] ?? null))
            ->map(fn (array $item) => ['name' => $item['name'], 'amount' => $item['amount'] ?? null])
            ->values()
            ->all();
    }

    /**
     * Food ids of the recipe app for offers and pantry items (null if nothing fits).
     *
     * @param  list<string>  $names
     * @param  list<array{id: int, name: string}>  $foods
     * @return array<string, int|null> name => food id
     */
    public function matchFoods(array $names, array $foods): array
    {
        if ($names === [] || $foods === []) {
            return [];
        }

        $result = $this->client->structured(
            'Match grocery product names to the foods of a recipe database. For each product return the id of the food it is '
            .'(or mainly consists of), e.g. "Rindshackfleisch" → "Hackfleisch", "Cherry-Tomaten" → "Tomaten". Use null when no '
            .'food fits. Only use ids from the list.',
            [OpenAiClient::text(json_encode([
                'products' => $names,
                'foods' => array_map(fn (array $food) => [$food['id'], $food['name']], $foods),
            ], JSON_UNESCAPED_UNICODE))],
            'food_matches',
            $this->object(['matches' => $this->list($this->object([
                'product' => ['type' => 'string'],
                'food_id' => $this->nullable('integer'),
            ]))]),
        );

        $ids = array_column($foods, 'id');

        return collect($result['matches'] ?? [])
            ->mapWithKeys(fn (array $match) => [
                $match['product'] => in_array($match['food_id'] ?? null, $ids, true) ? $match['food_id'] : null,
            ])
            ->all();
    }

    /**
     * Recipe suggestions: picks from the recipe app (candidates) and new ideas.
     *
     * @param  list<array<string, mixed>>  $offers
     * @param  list<array<string, mixed>>  $pantry
     * @param  list<array{id: int, name: string, foods: list<string>}>  $candidates
     * @param  array{categories?: list<string>, units?: list<string>, complexities?: list<string>}|null  $vocabulary  of the recipe app
     * @param  list<string>  $exclude  names of dishes already suggested
     * @return array{picks: list<array<string, mixed>>, ideas: list<array<string, mixed>>}
     */
    public function suggest(array $offers, array $pantry, array $candidates, int $servings, int $count, bool $ideas, ?string $wishes, string $language, ?array $vocabulary = null, array $exclude = []): array
    {
        $complexities = ($vocabulary['complexities'] ?? []) ?: self::COMPLEXITIES;
        $categories = $vocabulary['categories'] ?? [];
        $units = $vocabulary['units'] ?? [];

        $result = $this->client->structured(
            'You are a meal planner. Suggest '.$count.' dinners for the coming week that use the current supermarket offers and '
            .'what is already at home (pantry) as much as possible. Be efficient: prefer dishes that combine several offers at once '
            .'(and pantry items) over one offer per dish, and spread the offers over the week so most of them get used. '
            .'Prefer the user\'s own recipes ("candidates", pick by id; their "offers" and "pantry" list what they use, best first); '
            .($ideas ? 'fill up with new recipe ideas if fewer than '.$count.' candidates fit well. ' : 'do not invent new recipes, return no ideas. ')
            .'Ideas should combine at least two offers that go well together. '
            .'Ideas are complete recipes for '.$servings.' servings with ingredients (amount as number or null, unit like g, kg, '
            .'ml, dl, l, Stk., EL, TL, Prise or null) and short step-by-step instructions as a numbered list. '
            .'Ingredients have short, generic names without brand, label or quality (e.g. "Kartoffeln", not "Bio-Kartoffeln" or '
            .'"M-Classic Rindshackfleisch"), even if an offer is used for them. '
            .($units !== [] ? 'Use these units where possible: '.implode(', ', $units).'. ' : '')
            .'"category" is '.($categories !== [] ? 'one of: '.implode(', ', $categories) : 'a short dish category like "Suppen"').'. '
            .'"complexity" is how hard the recipe is. "reason" is one short sentence why it fits. '
            .'"uses_offers" and "uses_pantry" contain the exact names from the input. Vary the dishes. '
            .($exclude !== [] ? 'The user already has these dishes, suggest different ones: '.implode('; ', $exclude).'. ' : '')
            .'Write in '.$language.'.',
            [OpenAiClient::text(json_encode(compact('offers', 'pantry', 'candidates', 'wishes'), JSON_UNESCAPED_UNICODE))],
            'suggestions',
            $this->object([
                'picks' => $this->list($this->object([
                    'recipe_id' => ['type' => 'integer'],
                    'reason' => ['type' => 'string'],
                    'uses_offers' => $this->list(['type' => 'string']),
                    'uses_pantry' => $this->list(['type' => 'string']),
                ])),
                'ideas' => $this->list($this->object([
                    'name' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                    'servings' => ['type' => 'integer'],
                    'category' => ['type' => 'string'],
                    'complexity' => ['type' => 'string', 'enum' => array_values($complexities)],
                    'ingredients' => $this->list($this->ingredient()),
                    'instructions' => ['type' => 'string'],
                    'uses_offers' => $this->list(['type' => 'string']),
                    'uses_pantry' => $this->list(['type' => 'string']),
                ])),
            ]),
        );

        $ids = array_column($candidates, 'id');

        return [
            'picks' => collect($result['picks'] ?? [])->filter(fn ($pick) => in_array($pick['recipe_id'] ?? null, $ids, true))->unique('recipe_id')->values()->all(),
            'ideas' => $ideas ? array_values($result['ideas'] ?? []) : [],
        ];
    }

    /**
     * Shopping list for the chosen recipes: merged ingredients, offers marked (shop, note),
     * what is at home flagged.
     *
     * @param  list<array{name: string, servings: int|float|null, ingredients: list<array<string, mixed>>}>  $recipes
     * @param  list<array<string, mixed>>  $offers
     * @param  list<array<string, mixed>>  $pantry
     * @return list<array{name: string, amount: ?float, unit: ?string, store: ?string, note: ?string, at_home: bool, recipes: list<string>}>
     */
    public function shoppingList(array $recipes, array $offers, array $pantry, string $language): array
    {
        $result = $this->client->structured(
            'Turn the ingredients of these recipes into one shopping list. Merge the same ingredient across recipes and add up the '
            .'amounts when the units fit (convert g/kg, ml/dl/l); keep separate entries otherwise. Use short, generic names. '
            .'Skip water, salt, pepper and other basics a household always has unless listed with a large amount. '
            .'If an offer can be bought for an entry, set "offer" to the exact offer name (also for a more specific product, '
            .'e.g. "Kürbis" ↔ "Butternusskürbis", "Kartoffeln" ↔ "Bio-Kartoffeln"), but keep the generic name for the entry '
            .'(e.g. "Kartoffeln", not "Bio-Kartoffeln"; the offer is linked by "offer"). '
            .'If it is in the pantry, set "at_home" true. '
            .'"recipes" lists the recipe names that need it. Write in '.$language.'.',
            [OpenAiClient::text(json_encode(compact('recipes', 'offers', 'pantry'), JSON_UNESCAPED_UNICODE))],
            'shopping_list',
            $this->object(['items' => $this->list($this->object([
                'name' => ['type' => 'string'],
                'amount' => $this->nullable('number'),
                'unit' => $this->nullable('string'),
                'offer' => $this->nullable('string'),
                'at_home' => ['type' => 'boolean'],
                'recipes' => $this->list(['type' => 'string']),
            ]))]),
        );

        $offersByName = collect($offers)->keyBy('name');

        return collect($result['items'] ?? [])
            ->filter(fn ($item) => filled($item['name'] ?? null))
            ->map(function (array $item) use ($offersByName) {
                $offer = $offersByName->get($item['offer'] ?? '');

                return [
                    'name' => $item['name'],
                    'amount' => $this->roundForShopping($item['amount'] ?? null, $item['unit'] ?? null),
                    'unit' => $item['unit'] ?? null,
                    'store' => $offer['store'] ?? null,
                    'note' => $offer ? $this->offerNote($offer, $item['name']) : null,
                    'at_home' => (bool) ($item['at_home'] ?? false),
                    'recipes' => array_values($item['recipes'] ?? []),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Scaled recipes give amounts like 3.33 onions or 66.67 g: round up to what one buys.
     * Pieces (no unit, Stk., Bund, …) to whole numbers, weights and volumes to sensible steps.
     */
    public function roundForShopping(int|float|null $amount, ?string $unit): int|float|null
    {
        if ($amount === null || $amount <= 0) {
            return null;
        }

        $unit = mb_strtolower(trim((string) $unit, ' .'));
        $step = match (true) {
            in_array($unit, ['g', 'ml'], true) => $amount >= 200 ? 50 : ($amount >= 50 ? 10 : 5),
            in_array($unit, ['kg', 'l'], true) => 0.1,
            in_array($unit, ['dl', 'cl'], true) => 0.5,
            default => 1,
        };

        return round(ceil(round($amount / $step, 6)) * $step, 2);
    }

    /**
     * "Aktion −30 %, Bio-Kartoffeln, CHF 3.95 (statt 5.65)"; the product only if it is not the item's name.
     *
     * @param  array<string, mixed>  $offer
     */
    private function offerNote(array $offer, string $name): string
    {
        // The item has the generic name ("Kartoffeln"), the note says which product is on offer ("Bio-Kartoffeln").
        $product = trim(($offer['brand'] ?? '').' '.($offer['name'] ?? ''));
        $parts = array_filter([
            __('Offer').(filled($offer['discount'] ?? null) ? ' '.$offer['discount'] : ''),
            mb_strtolower($product) !== mb_strtolower(trim($name)) ? $product : null,
            isset($offer['price']) ? 'CHF '.number_format((float) $offer['price'], 2, '.', "'")
                .(isset($offer['regular_price']) ? ' ('.__('instead of :price', ['price' => number_format((float) $offer['regular_price'], 2, '.', "'")]).')' : '') : null,
        ]);

        return mb_substr(implode(', ', $parts), 0, 255);
    }

    /**
     * @return array<string, mixed>
     */
    private function ingredient(): array
    {
        return $this->object([
            'amount' => $this->nullable('number'),
            'unit' => $this->nullable('string'),
            'name' => ['type' => 'string'],
        ]);
    }

    /**
     * Strict JSON schema object: every property required, nothing else allowed.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function list(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function nullable(string $type): array
    {
        return ['type' => [$type, 'null']];
    }
}
