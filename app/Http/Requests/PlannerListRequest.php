<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlannerListRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...PlannerSuggestionsRequest::offersAndPantry(),
            'recipes' => ['required', 'array', 'min:1', 'max:15'],
            'recipes.*.source' => ['required', 'in:recipes,ai'],
            'recipes.*.recipe_id' => ['required_if:recipes.*.source,recipes', 'nullable', 'integer'],
            'recipes.*.name' => ['required', 'string', 'max:255'],
            'recipes.*.servings' => ['required', 'numeric', 'min:0.5', 'max:100'],
            'recipes.*.ingredients' => ['required_if:recipes.*.source,ai', 'array', 'max:100'],
            'recipes.*.ingredients.*.name' => ['required', 'string', 'max:255'],
            'recipes.*.ingredients.*.amount' => ['nullable', 'numeric', 'min:0'],
            'recipes.*.ingredients.*.unit' => ['nullable', 'string', 'max:50'],
        ];
    }
}
