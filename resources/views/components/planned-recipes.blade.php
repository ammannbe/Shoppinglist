{{-- Planned recipes in the week planner (steps 3 and 4): tap opens the details, ✕ takes one out of the plan; marked once items were built from it (step 4) --}}
<section x-show="planned.length" class="overflow-hidden rounded-lg border border-emerald-200 bg-emerald-50/50">
    <h3 class="px-3 py-2 text-sm font-medium text-emerald-900" x-text="`${$t('Planned')} (${planned.length})`"></h3>
    <ul class="divide-y divide-emerald-100 border-t border-emerald-100">
        <template x-for="recipe in planned" :key="recipe.id">
            <li class="flex items-center gap-1 pl-3">
                <button type="button" class="min-w-0 flex-1 py-2 text-left" @click="openDetail(recipe)" :aria-label="$t('Show “:name”', { name: recipe.name })">
                    <span class="block truncate font-medium" x-text="recipe.name"></span>
                    <span class="block truncate text-xs text-stone-500"
                        x-text="[$recipeSource(recipe.source).label, recipe.servings ? $t(':count servings', { count: recipe.servings }) : null, isBuilt(recipe) ? $t('Ingredients in step 4') : null].filter(Boolean).join(' · ')"></span>
                </button>
                <x-spinner x-show="busyKey === `unplan:${recipe.id}`" />
                <button type="button" class="icon-button shrink-0 text-stone-400" :disabled="busy" @click="unplan(recipe)"
                    :aria-label="$t('Unplan “:name”', { name: recipe.name })" :title="$t('Unplan')">✕</button>
            </li>
        </template>
    </ul>
    <p x-show="error && planned.some((recipe) => errorKey === `unplan:${recipe.id}`) && !detail" class="border-t border-emerald-100 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" x-text="error"></p>
</section>
