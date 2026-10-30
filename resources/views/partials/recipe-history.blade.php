{{--
    Recipe history (#/recipes, resources/js/recipe-history.js): recipes the week planner suggested.
    Cook one (ingredients and preparation of AI ideas), plan it again or import an AI idea into
    the recipe app afterwards. Needs a connection.
--}}
<section x-show="route.view === 'recipes'" class="space-y-4" x-data="recipeHistory()">
    <p class="sr-only" role="status" x-text="busy ?? ''"></p>

    <div class="flex items-center gap-2">
        <p class="min-w-0 flex-1 text-sm text-stone-500" x-text="$t('Recipes the week planner suggested, newest first.')"></p>
        <button type="button" class="icon-button" :disabled="busy" @click="load()" :aria-label="$t('Reload')" :title="$t('Reload')">
            <x-spinner x-show="busyKey === 'load'" />
            <svg x-show="busyKey !== 'load'" viewBox="0 0 24 24" class="size-5"><path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </div>
    <x-planner-error key="load" />

    <p x-show="loaded && !recipes.length" class="py-6 text-center text-stone-500" x-text="$t('No recipes yet. The week planner keeps the recipes it suggests here.')"></p>

    <ul class="space-y-3">
        <template x-for="recipe in recipes" :key="recipe.id">
            <li class="rounded-lg border border-stone-200 bg-white p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <a x-show="recipe.url" :href="recipe.url" target="_blank" rel="noopener" class="font-medium underline" x-text="recipe.name"></a>
                    <span x-show="!recipe.url" class="font-medium" x-text="recipe.name"></span>
                    <span class="rounded-full px-2 py-0.5 text-xs"
                        :class="recipe.source === 'ai' ? 'bg-violet-100 text-violet-800' : 'bg-emerald-100 text-emerald-800'"
                        x-text="recipe.source === 'ai' ? $t('AI idea') : ($store.sync.user?.recipes?.name ?? $t('Recipe'))"></span>
                    <span x-show="recipe.used_at" class="rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600"
                        x-text="$t('On a list :date', { date: date(recipe.used_at) })"></span>
                </div>
                <p class="mt-1 text-sm text-stone-500" x-text="[date(recipe.updated_at), recipe.servings ? $t(':count servings', { count: recipe.servings }) : null, recipe.reason].filter(Boolean).join(' · ')"></p>

                {{-- Cooking view: AI ideas keep their ingredients and preparation --}}
                <template x-if="recipe.source === 'ai'">
                    <details class="mt-2 text-sm" :open="open === recipe.id" @toggle="open = $event.target.open ? recipe.id : (open === recipe.id ? null : open)">
                        <summary class="cursor-pointer text-stone-600" x-text="$t('Ingredients and preparation')"></summary>
                        <ul class="mt-2 list-disc pl-5">
                            <template x-for="(ingredient, index) in recipe.ingredients ?? []" :key="index">
                                <li x-text="[ingredient.amount, ingredient.unit, ingredient.name].filter((part) => part !== null && part !== '').join(' ')"></li>
                            </template>
                        </ul>
                        <p class="mt-2 whitespace-pre-line" x-text="recipe.instructions"></p>
                    </details>
                </template>

                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <button type="button" class="font-medium underline" @click="plan(recipe)" x-text="$t('Plan again')"></button>
                    <template x-if="recipe.source === 'ai' && $store.sync.user?.recipes?.connected">
                        <span>
                            <a x-show="recipe.draft_url" :href="recipe.draft_url" target="_blank" rel="noopener" class="font-medium underline"
                                x-text="$t('Check and import in :name', { name: $store.sync.user.recipes.name })"></a>
                            <button x-show="!recipe.draft_url" type="button" class="inline-flex items-center gap-2 font-medium underline disabled:opacity-50" :disabled="busy" @click="importIdea(recipe)">
                                <x-spinner x-show="busyKey === `idea:${recipe.id}`" />
                                <span x-text="label(`idea:${recipe.id}`, $t('Import into :name', { name: $store.sync.user.recipes.name }))"></span>
                            </button>
                        </span>
                    </template>
                    <button type="button" class="ml-auto inline-flex items-center gap-2 text-stone-600 underline" :disabled="busy" @click="remove(recipe)">
                        <x-spinner x-show="busyKey === `remove:${recipe.id}`" />
                        <span x-text="$t('Remove')"></span>
                    </button>
                </div>
                <p x-show="error && (errorKey === `idea:${recipe.id}` || errorKey === `remove:${recipe.id}`)" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" x-text="error"></p>
            </li>
        </template>
    </ul>

    <button type="button" x-show="recipes.length" class="inline-flex items-center gap-2 text-sm text-stone-500 underline" :disabled="busy" @click="clear()">
        <x-spinner x-show="busyKey === 'clear'" />
        <span x-text="$t('Clear history')"></span>
    </button>
    <x-planner-error key="clear" />
</section>
