{{--
    Week planner (#/planner, resources/js/planner.js): offers and pantry read by the AI,
    recipe suggestions (recipe app and AI ideas), shopping list. Needs a connection.
--}}
<section x-show="route.view === 'planner'" class="space-y-4"
    x-data="planner({ addItems: (target, name, items) => addPlannedItems(target, name, items), lists: () => lists })">
    <div x-show="recipes && !recipes.connected" class="notice flex flex-wrap items-center justify-between gap-3">
        <span x-text="$t('Connect :name to get your own recipes suggested and to save new ones there.', { name: recipes?.name })"></span>
        <a href="/recipes/connect" data-online class="button-secondary shrink-0 text-sm" x-text="$t('Connect')"></a>
    </div>

    {{-- Progress and errors are shown at the button that started the request; screen readers get the progress here --}}
    <p class="sr-only" role="status" x-text="busy ?? ''"></p>

    {{-- 1. Offers: saved ones of the pool are offered per shop, new ones can be read --}}
    <x-planner-step number="1" title="$t('Offers')">
        <x-slot:actions>
            <a href="#/offers" class="shrink-0 text-sm text-stone-500 underline" x-text="$t('Manage')"></a>
        </x-slot:actions>

        <p x-show="busyKey === 'saved'" class="flex items-center gap-2 text-sm text-stone-500">
            <x-spinner />
            <span x-text="$t('Loading offers …')"></span>
        </p>
        <x-planner-error key="saved" />

        {{-- Saved offers not taken yet, per shop --}}
        <div x-show="savedGroups.length" class="overflow-hidden rounded-lg border border-orange-200 bg-orange-50/50">
            <div class="flex items-center justify-between gap-2 px-3 py-2">
                <span class="text-sm font-medium text-stone-700" x-text="$t('Available offers')"></span>
                <button type="button" x-show="savedGroups.length > 1" class="text-sm font-medium underline" @click="takeSaved(availableOffers)" x-text="$t('Take all')"></button>
            </div>
            <ul class="divide-y divide-orange-100 border-t border-orange-100">
                <template x-for="group in savedGroups" :key="group.store">
                    <li class="flex items-center gap-3 px-3 py-2">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium" x-text="group.store ? storeLabel(group.store) : $t('Other shops')"></span>
                            <span class="block truncate text-xs text-stone-500"
                                x-text="[$t(group.offers.length === 1 ? ':count offer' : ':count offers', { count: group.offers.length }), groupValidity(group.offers)].filter(Boolean).join(' · ')"></span>
                        </span>
                        <button type="button" class="button-secondary shrink-0 px-3 py-1.5 text-sm" @click="takeSaved(group.offers)" x-text="$t('Take')"></button>
                    </li>
                </template>
            </ul>
        </div>

        {{-- Taken offers per shop: collapsible, (de)select or remove a shop at once --}}
        <div x-show="offers.length" class="space-y-2">
            <template x-for="group in offerGroups" :key="group.store">
                <details class="group/shop overflow-hidden rounded-lg border border-stone-200" x-init="$el.open = group.offers.length <= 8">
                    <summary class="flex cursor-pointer list-none items-center gap-3 bg-stone-50 px-3 py-2">
                        <input type="checkbox" class="size-5 shrink-0 accent-emerald-700" @click.stop
                            :checked="group.offers.every((offer) => offer.selected)"
                            :indeterminate="group.offers.some((offer) => offer.selected) && !group.offers.every((offer) => offer.selected)"
                            @change="selectGroup(group, $event.target.checked)" :aria-label="$t('Select all')">
                        <span class="min-w-0 flex-1 truncate font-medium" x-text="group.store ? storeLabel(group.store) : $t('Other shops')"></span>
                        <span class="shrink-0 text-sm tabular-nums text-stone-500" x-text="`${group.offers.filter((offer) => offer.selected).length}/${group.offers.length}`"></span>
                        <button type="button" class="icon-button text-stone-400" @click.prevent.stop="removeOffers(group.offers)" :aria-label="$t('Remove')" :title="$t('Remove')">✕</button>
                        <span class="text-stone-400 transition-transform group-open/shop:rotate-90" aria-hidden="true">›</span>
                    </summary>
                    <ul class="divide-y divide-stone-100 border-t border-stone-200">
                        <template x-for="(offer, index) in group.offers" :key="offer.id ?? `${group.store}-${index}`">
                            <li class="flex items-center gap-3 px-3 py-2" :class="offer.selected || 'text-stone-400'">
                                <input type="checkbox" class="size-5 shrink-0 accent-emerald-700" x-model="offer.selected" :aria-label="offer.name">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate" x-text="offer.name"></span>
                                    <span class="block truncate text-xs text-stone-500" x-text="[offer.brand, offer.discount, offer.price != null ? 'CHF ' + Number(offer.price).toFixed(2) : null].filter(Boolean).join(' · ')"></span>
                                </span>
                                <button type="button" class="icon-button text-stone-400" @click="removeOfferItem(offer)" :aria-label="$t('Remove')">✕</button>
                            </li>
                        </template>
                    </ul>
                </details>
            </template>
            <div class="flex items-center justify-between text-sm text-stone-500">
                <span x-text="$t(':selected of :total offers selected', { selected: selectedOffers.length, total: offers.length })"></span>
                <button type="button" class="underline" @click="removeOffers()" x-text="$t('Remove all')"></button>
            </div>
        </div>

        {{-- Reading new offers is the exception: collapsed unless there is nothing else --}}
        <details class="group/read rounded-lg border border-dashed border-stone-300"
            x-effect="if (savedOffers !== null && !offers.length && !savedGroups.length) $el.open = true">
            <summary class="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-sm font-medium text-stone-600">
                <span x-text="$t('Read new offers')"></span>
                <span class="transition-transform group-open/read:rotate-90" aria-hidden="true">›</span>
            </summary>
            <div class="border-t border-dashed border-stone-300 p-3">
                <x-offer-reader />
            </div>
        </details>
    </x-planner-step>

    {{-- 2. Pantry --}}
    <x-planner-step number="2" title="$t('At home (optional)')">
        <x-slot:actions>
            <button type="button" x-show="pantry.length" class="shrink-0 text-sm text-stone-500 underline" @click="clearPantry()" x-text="$t('Remove all')"></button>
        </x-slot:actions>
        <p class="-mt-2 text-sm text-stone-500" x-text="$t('Used first and left off the list.')"></p>
        <div x-show="pantry.length" class="flex flex-wrap gap-2">
            <template x-for="(item, index) in pantry" :key="index">
                <label class="flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1 text-sm"
                    :class="item.selected ? 'border-emerald-600 bg-emerald-50' : 'border-stone-300 bg-white text-stone-400 line-through'">
                    <input type="checkbox" class="sr-only" x-model="item.selected">
                    <span x-text="item.name + (item.amount ? ' (' + item.amount + ')' : '')"></span>
                </label>
            </template>
        </div>
        <div class="flex flex-wrap gap-2">
            <form @submit.prevent="addPantryItem()" class="flex min-w-0 flex-1 basis-60 gap-2">
                <input x-model="newPantryItem" class="field min-w-0 flex-1" :placeholder="$t('e.g. rice')" :aria-label="$t('Add by hand')">
                <button class="button-secondary" x-text="$t('Add')"></button>
            </form>
            <label class="button-secondary inline-flex cursor-pointer items-center gap-2" :class="busy && 'pointer-events-none opacity-50'" :title="$t('Photos of the fridge, freezer or pantry')">
                <input type="file" accept="image/*" multiple class="sr-only" :disabled="busy" @change="readPantry($event)">
                <x-spinner x-show="busyKey === 'pantry'" />
                <span x-text="label('pantry', $t('Read from photos'))"></span>
            </label>
        </div>
        <x-planner-error key="pantry" />
    </x-planner-step>

    {{-- 3. Recipes --}}
    <x-planner-step number="3" title="$t('Recipes')">
        <div class="grid grid-cols-2 gap-3">
            <label class="block">
                <span class="label" x-text="$t('Servings')"></span>
                <input x-model.number="servings" type="number" min="1" max="20" inputmode="numeric" class="field w-full">
            </label>
            <label class="block">
                <span class="label" x-text="$t('Number of recipes')"></span>
                <input x-model.number="count" type="number" min="1" max="10" inputmode="numeric" class="field w-full">
            </label>
        </div>
        <input x-model="wishes" class="field w-full" maxlength="500" :placeholder="$t('Wishes, e.g. vegetarian, quick, no fish')">
        <label x-show="recipes?.connected" class="flex items-center gap-2 text-sm">
            <input type="checkbox" class="size-4 accent-emerald-700" x-model="ideas">
            <span x-text="$t('Also new recipe ideas of the AI')"></span>
        </label>
        <button type="button" class="button flex w-full items-center justify-center gap-2" :disabled="busy || (!selectedOffers.length && !selectedPantry.length)" @click="suggest()">
            <x-spinner x-show="busyKey === 'suggest'" />
            <span x-text="label('suggest', $t('Suggest recipes'))"></span>
        </button>
        <x-planner-error key="suggest" />
        <p x-show="warning && !warningMore" class="notice" x-text="warning"></p>

        <div class="space-y-3">
            <template x-for="suggestion in suggestions" :key="suggestion.key">
                <div class="rounded-lg border bg-white p-4" :class="suggestion.selected ? 'border-emerald-600' : 'border-stone-200'">
                    <div class="flex items-start gap-3">
                        <input type="checkbox" class="mt-1 size-5 shrink-0 accent-emerald-700" x-model="suggestion.selected" :aria-label="suggestion.name">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a x-show="suggestion.url" :href="suggestion.url" target="_blank" rel="noopener" class="font-medium underline" x-text="suggestion.name"></a>
                                <span x-show="!suggestion.url" class="font-medium" x-text="suggestion.name"></span>
                                <span class="rounded-full px-2 py-0.5 text-xs"
                                    :class="suggestion.source === 'ai' ? 'bg-violet-100 text-violet-800' : 'bg-emerald-100 text-emerald-800'"
                                    x-text="suggestion.source === 'ai' ? $t('AI idea') : recipes?.name"></span>
                            </div>
                            <p class="text-sm text-stone-600" x-text="suggestion.reason"></p>
                            <p x-show="suggestion.uses_offers.length" class="text-xs text-orange-800" x-text="$t('Offers') + ': ' + suggestion.uses_offers.join(', ')"></p>
                            <p x-show="suggestion.uses_pantry.length" class="text-xs text-emerald-800" x-text="$t('At home') + ': ' + suggestion.uses_pantry.join(', ')"></p>
                            <template x-if="suggestion.source === 'ai'">
                                <details class="text-sm">
                                    <summary class="cursor-pointer text-stone-500" x-text="$t('Ingredients and preparation')"></summary>
                                    <ul class="mt-2 list-disc pl-5">
                                        <template x-for="ingredient in suggestion.ingredients" :key="ingredient.name">
                                            <li x-text="[ingredient.amount, ingredient.unit, ingredient.name].filter((part) => part !== null && part !== '').join(' ')"></li>
                                        </template>
                                    </ul>
                                    <p class="mt-2 whitespace-pre-line" x-text="suggestion.instructions"></p>
                                    <template x-if="recipes?.connected">
                                        <p class="mt-2">
                                            <a x-show="suggestion.savedUrl" :href="suggestion.savedUrl" target="_blank" rel="noopener" class="font-medium underline" x-text="$t('Check and import in :name', { name: recipes.name })"></a>
                                            <button x-show="!suggestion.savedUrl" type="button" class="inline-flex items-center gap-2 font-medium underline disabled:opacity-50" :disabled="busy" @click="saveIdea(suggestion)">
                                                <x-spinner x-show="busyKey === `idea:${suggestion.key}`" />
                                                <span x-text="label(`idea:${suggestion.key}`, $t('Import into :name', { name: recipes.name }))"></span>
                                            </button>
                                        </p>
                                        <p x-show="error && errorKey === `idea:${suggestion.key}`" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-red-800" role="alert" x-text="error"></p>
                                    </template>
                                </details>
                            </template>
                        </div>
                        <label class="w-16 shrink-0">
                            <span class="sr-only" x-text="$t('Servings')"></span>
                            <input x-model.number="suggestion.servings" type="number" min="1" max="20" inputmode="numeric" class="field w-full px-2 text-center text-sm" :title="$t('Servings')">
                        </label>
                    </div>
                </div>
            </template>
        </div>
        <button type="button" x-show="suggestions.length" class="button-secondary flex w-full items-center justify-center gap-2" :disabled="busy" @click="suggest(true)">
            <x-spinner x-show="busyKey === 'more'" />
            <span x-text="label('more', $t('Suggest more recipes'))"></span>
        </button>
        <x-planner-error key="more" />
        <p x-show="warning && warningMore" class="notice" x-text="warning"></p>
    </x-planner-step>

    {{-- 4. Shopping list --}}
    <x-planner-step number="4" title="$t('Shopping list')" x-show="suggestions.length">
        <button type="button" class="button flex w-full items-center justify-center gap-2" :disabled="busy || !selectedSuggestions.length" @click="buildList()">
            <x-spinner x-show="busyKey === 'list'" />
            <span x-text="label('list', $t('Create shopping list'))"></span>
        </button>
        <x-planner-error key="list" />

        <template x-if="items.length">
            <div class="space-y-3">
                <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                    <template x-for="(item, index) in items" :key="index">
                        <li class="flex items-center gap-3 px-3 py-2" :class="item.selected || 'text-stone-400'">
                            <input type="checkbox" class="size-5 shrink-0 accent-emerald-700" x-model="item.selected" :aria-label="item.name">
                            <span x-show="item.store" class="grid h-5 min-w-5 shrink-0 place-items-center rounded bg-orange-100 px-1 text-xs font-semibold text-orange-800" :title="storeLabel(item.store)" x-text="item.store"></span>
                            <span x-show="item.amount != null" class="shrink-0 tabular-nums text-stone-500" x-text="[item.amount, item.unit].filter((part) => part != null).join(' ')"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate" x-text="item.name"></span>
                                <span class="block truncate text-xs text-stone-500" x-text="[item.at_home ? $t('At home') : null, item.note, item.recipes.join(', ')].filter(Boolean).join(' · ')"></span>
                            </span>
                        </li>
                    </template>
                </ul>
                <label class="block">
                    <span class="label" x-text="$t('Add to')"></span>
                    <select x-model="target" class="field w-full">
                        <option value="" x-text="$t('New list')"></option>
                        <template x-for="list in lists" :key="list.id">
                            <option :value="list.id" x-text="list.name"></option>
                        </template>
                    </select>
                </label>
                <input x-show="!target" x-model="listName" class="field w-full" :aria-label="$t('Name')">
                <button type="button" class="button w-full" :disabled="!selectedItems.length" @click="addToList()"
                    x-text="$t('Add :count items', { count: selectedItems.length })"></button>
            </div>
        </template>
    </x-planner-step>

    <button type="button" x-show="offers.length || pantry.length || suggestions.length" class="text-sm text-stone-500 underline"
        @click="confirm($t('Start over?')) && reset()" x-text="$t('Start over')"></button>
</section>
