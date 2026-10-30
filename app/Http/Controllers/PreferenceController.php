<?php

namespace App\Http\Controllers;

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
        // Codes of the standard shops hidden in the shop choice and the filter.
        'hidden_stores' => [],
    ];

    public const SORTS = ['manual', 'name_asc', 'name_desc', 'newest', 'oldest'];

    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'row_actions' => ['sometimes', Rule::in(['swipe', 'longpress'])],
            'list_sort' => ['sometimes', Rule::in(self::SORTS)],
            'hidden_stores' => ['sometimes', 'array', 'max:50'],
            'hidden_stores.*' => ['string', 'max:10'],
        ]);

        $user = $request->user();
        $user->forceFill(['preferences' => [...($user->preferences ?? []), ...$validated]])->save();

        return response()->noContent();
    }
}
