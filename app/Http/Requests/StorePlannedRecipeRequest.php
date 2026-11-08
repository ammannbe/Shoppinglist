<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A planner suggestion saved into the recipes (history): an own recipe of the recipe app
 * (by its id) or an AI idea with ingredients and preparation.
 */
class StorePlannedRecipeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::recipe();
    }

    /**
     * Rules of one recipe, also used for the recipes of the planner's list (`recipes.*.`).
     *
     * @return array<string, mixed>
     */
    public static function recipe(string $prefix = ''): array
    {
        return [
            // recipes: own recipe of the recipe app, ai: AI idea, custom: a dish typed in the planner (name, optional ingredients).
            $prefix.'source' => ['required', 'in:recipes,ai,custom'],
            // Own recipes are saved once per recipe id ("recipe-<id>"), AI ideas and typed dishes by their key.
            $prefix.'key' => ['required_unless:'.$prefix.'source,recipes', 'nullable', 'string', 'max:100', 'regex:/^(recipe|idea|custom)-[A-Za-z0-9-]+$/'],
            $prefix.'recipe_id' => ['required_if:'.$prefix.'source,recipes', 'nullable', 'integer', 'min:1'],
            $prefix.'name' => ['required', 'string', 'max:255'],
            $prefix.'url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            $prefix.'servings' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            $prefix.'reason' => ['nullable', 'string', 'max:1000'],
            $prefix.'category' => ['nullable', 'string', 'max:50'],
            $prefix.'complexity' => ['nullable', 'string', 'max:20'],
            $prefix.'instructions' => ['required_if:'.$prefix.'source,ai', 'nullable', 'string', 'max:20000'],
            $prefix.'ingredients' => ['required_if:'.$prefix.'source,ai', 'array', 'max:100'],
            $prefix.'ingredients.*.name' => ['required', 'string', 'max:255'],
            $prefix.'ingredients.*.amount' => ['nullable', 'numeric', 'min:0'],
            $prefix.'ingredients.*.unit' => ['nullable', 'string', 'max:50'],
            ...collect(['uses_offers', 'uses_pantry', 'uses_season'])->flatMap(fn (string $field) => [
                $prefix.$field => ['nullable', 'array', 'max:50'],
                $prefix.$field.'.*' => ['string', 'max:255'],
            ])->all(),
        ];
    }
}
