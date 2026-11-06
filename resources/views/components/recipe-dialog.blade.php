{{--
    Recipe details as dialog (week planner suggestions, Recipes tab): the Alpine component has
    `detail` (the recipe, null when closed) and opens `$refs.recipeDialog`. Back closes it
    (resources/js/layers.js). The slot holds the actions, `meta` optional lines below the title,
    `linked` optional content above the details (Recipes tab: the items linked to the recipe).
--}}
<dialog x-ref="recipeDialog" class="dialog p-0" @close="detail = null" aria-labelledby="recipe-dialog-title">
    <template x-if="detail">
        <div>
            <div class="sticky top-0 z-10 flex items-start gap-2 border-b border-stone-200 bg-white px-5 py-3">
                <div class="min-w-0 flex-1 space-y-1">
                    <h2 id="recipe-dialog-title" class="text-lg font-semibold leading-snug" x-text="detail.name"></h2>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full px-2 py-0.5"
                            :class="detail.source === 'ai' ? 'bg-violet-100 text-violet-800' : 'bg-emerald-100 text-emerald-800'"
                            x-text="detail.source === 'ai' ? $t('AI idea') : ($store.sync.user?.recipes?.name ?? $t('Recipe'))"></span>
                        <span x-show="detail.servings" class="text-stone-500" x-text="$t(':count servings', { count: detail.servings })"></span>
                        <span x-show="detail.category" class="text-stone-500" x-text="detail.category"></span>
                    </div>
                    {{ $meta ?? '' }}
                </div>
                <button type="button" class="icon-button -mr-2 shrink-0 text-stone-500" @click="$refs.recipeDialog.close()" :aria-label="$t('Close')" :title="$t('Close')">✕</button>
            </div>

            <div class="space-y-4 px-5 py-4 text-sm">
                {{ $linked ?? '' }}
                <p x-show="detail.reason" class="text-stone-600" x-text="detail.reason"></p>
                <div x-show="detail.uses_offers?.length || detail.uses_pantry?.length || detail.uses_season?.length" class="space-y-1 text-xs">
                    <p x-show="detail.uses_offers?.length" class="text-orange-800" x-text="$t('Offers') + ': ' + (detail.uses_offers ?? []).join(', ')"></p>
                    <p x-show="detail.uses_pantry?.length" class="text-emerald-800" x-text="$t('At home') + ': ' + (detail.uses_pantry ?? []).join(', ')"></p>
                    <p x-show="detail.uses_season?.length" class="text-lime-800" x-text="$t('In season') + ': ' + (detail.uses_season ?? []).join(', ')"></p>
                </div>

                <template x-if="detail.ingredients?.length">
                    <section>
                        <h3 class="mb-1 font-semibold" x-text="$t('Ingredients')"></h3>
                        <ul class="list-disc space-y-0.5 pl-5">
                            <template x-for="(ingredient, index) in detail.ingredients" :key="index">
                                <li x-text="[ingredient.amount, ingredient.unit, ingredient.name].filter((part) => part !== null && part !== undefined && part !== '').join(' ')"></li>
                            </template>
                        </ul>
                    </section>
                </template>
                <template x-if="detail.instructions">
                    <section>
                        <h3 class="mb-1 font-semibold" x-text="$t('Preparation')"></h3>
                        <p class="whitespace-pre-line" x-text="detail.instructions"></p>
                    </section>
                </template>

                {{-- Own recipes: ingredients and preparation are in the recipe app --}}
                <a x-show="detail.url" :href="detail.url" target="_blank" rel="noopener" class="button-secondary inline-flex items-center gap-2"
                    x-text="$t('Open in :name', { name: $store.sync.user?.recipes?.name ?? $t('Recipe app') }) + ' ↗'"></a>
            </div>

            <div class="sticky bottom-0 flex flex-wrap items-center gap-2 border-t border-stone-200 bg-white px-5 py-3 text-sm">
                {{ $slot }}
            </div>
        </div>
    </template>
</dialog>
