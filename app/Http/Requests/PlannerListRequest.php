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
            // With all details of the suggestion: the recipes are saved (Recipes tab).
            ...StorePlannedRecipeRequest::recipe('recipes.*.'),
            // Older clients send neither the key of every recipe nor the preparation of AI ideas.
            'recipes.*.key' => ['nullable', 'string', 'max:100', 'regex:/^(recipe|idea)-[A-Za-z0-9-]+$/'],
            'recipes.*.instructions' => ['nullable', 'string', 'max:20000'],
            'recipes.*.servings' => ['required', 'numeric', 'min:0.5', 'max:100'],
        ];
    }
}
