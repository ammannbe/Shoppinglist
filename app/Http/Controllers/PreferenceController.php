<?php

namespace App\Http\Controllers;

use App\Support\ShopRegions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PreferenceController extends Controller
{
    /**
     * Defaults of the app settings stored per user.
     */
    public const DEFAULTS = [
        // Row actions on touch devices: swipe left, or long press for a menu.
        'row_actions' => 'swipe',
        // Order of the lists overview.
        'list_sort' => 'name_asc',
        // Tags (code or name) of the standard shops hidden in the shop choice and the filter.
        'hidden_stores' => [],
        // Country of the shop choice; null until the client sets it from the device (time zone).
        'country' => null,
        // Region per shop with regional offers (tag => App\Support\ShopRegions key); offers of other regions are hidden.
        'regions' => [],
    ];

    public const COUNTRIES = ['CH', 'DE', 'AT'];

    public const SORTS = ['manual', 'name_asc', 'name_desc', 'newest', 'oldest'];

    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'row_actions' => ['sometimes', Rule::in(['swipe', 'longpress'])],
            'list_sort' => ['sometimes', Rule::in(self::SORTS)],
            'hidden_stores' => ['sometimes', 'array', 'max:50'],
            'hidden_stores.*' => ['string', 'max:255'],
            'country' => ['sometimes', Rule::in(self::COUNTRIES)],
            'regions' => ['sometimes', 'array:'.implode(',', array_keys(ShopRegions::ALL))],
            ...collect(ShopRegions::ALL)->mapWithKeys(fn (array $regions, string $tag) => ["regions.{$tag}" => ['nullable', Rule::in(array_keys($regions))]])->all(),
        ]);
        if (isset($validated['regions'])) {
            // Shops set back to "all regions" are left out.
            $validated['regions'] = array_filter($validated['regions']);
        }

        $user = $request->user();
        $user->forceFill(['preferences' => [...($user->preferences ?? []), ...$validated]])->save();

        return response()->noContent();
    }
}
