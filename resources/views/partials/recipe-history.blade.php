{{--
    Recipes tab (#/recipes, resources/js/recipe-history.js): planner suggestions saved by the user
    or put on a shopping list. Tap opens the details (ingredients and preparation of AI ideas),
    there it can be planned again, imported into the recipe app or removed. Needs a connection.
--}}
<section x-show="route.view === 'recipes'" class="space-y-4" x-data="recipeHistory({ open: (hash) => openTab(hash) })">
    <p class="sr-only" role="status" x-text="busy ?? ''"></p>

    <div class="flex items-center gap-2">
        <p class="min-w-0 flex-1 text-sm text-stone-500" x-text="$t('Recipes saved in the week planner, newest first.')"></p>
        <button type="button" class="icon-button" :disabled="busy" @click="load()" :aria-label="$t('Reload')" :title="$t('Reload')">
            <x-spinner x-show="busyKey === 'load'" />
            <svg x-show="busyKey !== 'load'" viewBox="0 0 24 24" class="size-5"><path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </div>
    <x-planner-error key="load" />

    <p x-show="loaded && !recipes.length" class="py-6 text-center text-stone-500" x-text="$t('No recipes yet. Save suggestions of the week planner, recipes on a shopping list are kept here too.')"></p>

    <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white" x-show="recipes.length">
        <template x-for="recipe in recipes" :key="recipe.id">
            <li>
                <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-stone-50" @click="openDetail(recipe)">
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-medium" x-text="recipe.name"></span>
                            <span class="rounded-full px-2 py-0.5 text-xs"
                                :class="recipe.source === 'ai' ? 'bg-violet-100 text-violet-800' : 'bg-emerald-100 text-emerald-800'"
                                x-text="recipe.source === 'ai' ? $t('AI idea') : ($store.sync.user?.recipes?.name ?? $t('Recipe'))"></span>
                            <span x-show="recipe.used_at" class="rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600"
                                x-text="$t('On a list :date', { date: date(recipe.used_at) })"></span>
                        </span>
                        <span class="mt-0.5 block truncate text-sm text-stone-500" x-text="[date(recipe.updated_at), recipe.servings ? $t(':count servings', { count: recipe.servings }) : null, recipe.reason].filter(Boolean).join(' · ')"></span>
                    </span>
                    <span class="shrink-0 text-stone-400" aria-hidden="true">›</span>
                </button>
            </li>
        </template>
    </ul>

    <button type="button" x-show="recipes.length" class="inline-flex items-center gap-2 text-sm text-stone-500 underline" :disabled="busy" @click="clear()">
        <x-spinner x-show="busyKey === 'clear'" />
        <span x-text="$t('Clear history')"></span>
    </button>
    <x-planner-error key="clear" />

    {{-- Details: plan again, import an AI idea into the recipe app, remove --}}
    <x-recipe-dialog>
        <x-slot:meta>
            <p class="text-xs text-stone-500" x-text="[$t('Saved :date', { date: date(detail.updated_at) }), detail.used_at ? $t('On a list :date', { date: date(detail.used_at) }) : null].filter(Boolean).join(' · ')"></p>
        </x-slot:meta>
        <button type="button" class="mr-auto inline-flex items-center gap-2 text-stone-600 underline" :disabled="busy" @click="remove(detail)">
            <x-spinner x-show="busyKey === `remove:${detail.id}`" />
            <span x-text="$t('Remove')"></span>
        </button>
        <template x-if="detail.source === 'ai' && $store.sync.user?.recipes?.connected">
            <span>
                <a x-show="detail.draft_url" :href="detail.draft_url" target="_blank" rel="noopener" class="button-secondary inline-flex"
                    x-text="$t('Check and import in :name', { name: $store.sync.user.recipes.name })"></a>
                <button x-show="!detail.draft_url" type="button" class="button-secondary inline-flex items-center gap-2" :disabled="busy" @click="importIdea(detail)">
                    <x-spinner x-show="busyKey === `idea:${detail.id}`" />
                    <span x-text="label(`idea:${detail.id}`, $t('Import into :name', { name: $store.sync.user.recipes.name }))"></span>
                </button>
            </span>
        </template>
        <button type="button" class="button" @click="plan(detail)" x-text="$t('Plan again')"></button>
        <p x-show="error && (errorKey === `idea:${detail.id}` || errorKey === `remove:${detail.id}`)" class="w-full rounded-lg bg-red-50 px-3 py-2 text-red-800" role="alert" x-text="error"></p>
    </x-recipe-dialog>
</section>
