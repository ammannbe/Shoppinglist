<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlannerSuggestionsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...self::offersAndPantry(),
            'servings' => ['required', 'integer', 'min:1', 'max:20'],
            'count' => ['required', 'integer', 'min:1', 'max:10'],
            'ideas' => ['required', 'boolean'],
            'wishes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function offersAndPantry(): array
    {
        return [
            'offers' => ['present', 'array', 'max:300'],
            'offers.*.name' => ['required', 'string', 'max:255'],
            'offers.*.brand' => ['nullable', 'string', 'max:255'],
            'offers.*.price' => ['nullable', 'numeric'],
            'offers.*.regular_price' => ['nullable', 'numeric'],
            'offers.*.discount' => ['nullable', 'string', 'max:100'],
            'offers.*.valid_until' => ['nullable', 'string', 'max:20'],
            'offers.*.store' => ['nullable', 'string', 'max:10'],
            'pantry' => ['present', 'array', 'max:200'],
            'pantry.*.name' => ['required', 'string', 'max:255'],
            'pantry.*.amount' => ['nullable', 'string', 'max:50'],
        ];
    }
}
