{{--
    Week planner (#/planner/<step>, resources/js/planner.js): offers and pantry read by the AI,
    recipe suggestions (recipe app and AI ideas), shopping list, one step per page with a step
    indicator on top and "Back"/"Next" at the bottom. Needs a connection.
--}}
<section x-show="route.view === 'planner'" class="space-y-4 pb-20"
    x-data="planner({ addItems: (target, name, items, merge) => addPlannedItems(target, name, items, merge), merges: (target, items) => plannedMerges(target, items).size, lists: () => lists, open: (hash) => openFromLists(hash), navigate: (hash, options) => navigate(hash, options) })">
    {{-- Steps: done and possible ones can be opened directly; "Start over" clears everything --}}
    <nav class="flex items-start gap-2" :aria-label="$t('Steps')">
        <ol class="grid min-w-0 flex-1 grid-cols-4 gap-1">
            <template x-for="(each, index) in steps" :key="each">
                <li>
                    <button type="button" class="flex w-full flex-col items-center gap-1 rounded-lg px-1 py-1 text-xs disabled:opacity-40"
                        :disabled="!canOpen(each)" :aria-current="step === each && 'step'" @click="goStep(each)">
                        <span class="grid size-7 place-items-center rounded-full text-sm font-semibold"
                            :class="step === each ? 'bg-emerald-700 text-white' : (index < stepIndex ? 'bg-emerald-100 text-emerald-800' : 'border border-stone-300 bg-white text-stone-600')"
                            x-text="index + 1"></span>
                        <span class="w-full truncate text-center" :class="step === each ? 'font-semibold text-stone-900' : 'text-stone-600'"
                            x-text="{ offers: $t('Offers'), pantry: $t('At home'), recipes: $t('Recipes'), list: $t('List') }[each]"></span>
                    </button>
                </li>
            </template>
        </ol>
        <button type="button" x-show="offerIds.length || pantry.length || suggestions.length || items.length"
            class="button-secondary mt-0.5 flex shrink-0 items-center gap-1.5 px-2.5 py-1.5 text-sm min-[480px]:px-3" :disabled="busy" @click="startOver()"
            :title="$t('Start over')" :aria-label="$t('Start over')">
            <svg viewBox="0 0 24 24" class="size-5 min-[480px]:size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.6M4 4v5h5"/></svg>
            <span class="hidden min-[480px]:inline" x-text="$t('Start over')"></span>
        </button>
    </nav>

    <div x-show="step === 'recipes' && recipes && !recipes.connected" class="notice flex flex-wrap items-center justify-between gap-3">
        <span x-text="$t('Connect :name to get your own recipes suggested and to save new ones there.', { name: recipes?.name })"></span>
        <a href="/recipes/connect?from=planner" data-online class="button-secondary shrink-0 text-sm" x-text="$t('Connect')"></a>
    </div>

    {{-- Progress and errors are shown at the button that started the request; screen readers get the progress here --}}
    <p class="sr-only" role="status" x-text="busy ?? ''"></p>

    {{-- 1. Offers: saved ones of the pool are offered per shop, new ones can be read --}}
    <x-planner-step number="1" title="$t('Offers')" x-show="step === 'offers'">
        <x-slot:actions>
            <a href="#/offers" class="shrink-0 text-sm text-stone-500 underline" x-text="$t('Manage')"></a>
        </x-slot:actions>

        <p x-show="busyKey === 'saved'" class="flex items-center gap-2 text-sm text-stone-500">
            <x-spinner />
            <span x-text="$t('Loading offers …')"></span>
        </p>
        <x-planner-error key="saved" />

        {{-- All offers of the pool per shop: collapsible, tick the ones to use (new ones are not ticked) --}}
        <div x-show="offerGroups.length" class="space-y-2">
            <template x-for="group in offerGroups" :key="group.store">
                <details class="group/shop overflow-hidden rounded-lg border border-stone-200" x-init="$el.open = offerGroups.length === 1 || selectedIn(group) > 0">
                    <summary class="flex cursor-pointer list-none items-center gap-3 bg-stone-50 px-3 py-2">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium" x-text="group.store ? storeLabel(group.store) : $t('Other shops')"></span>
                            <span class="block truncate text-xs text-stone-500"
                                x-text="[$t(':selected of :total selected', { selected: selectedIn(group), total: group.offers.length }), groupValidity(group.offers)].filter(Boolean).join(' · ')"></span>
                        </span>
                        <span class="flex shrink-0 gap-1 text-sm">
                            <button type="button" class="rounded px-2 py-1 font-medium underline disabled:no-underline disabled:opacity-40" :disabled="selectedIn(group) === group.offers.length"
                                @click.prevent.stop="selectOffers(group.offers, true)" :aria-label="$t('Select all of :shop', { shop: group.store ? storeLabel(group.store) : $t('Other shops') })" x-text="$t('All')"></button>
                            <button type="button" class="rounded px-2 py-1 font-medium underline disabled:no-underline disabled:opacity-40" :disabled="!selectedIn(group)"
                                @click.prevent.stop="selectOffers(group.offers, false)" :aria-label="$t('Select none of :shop', { shop: group.store ? storeLabel(group.store) : $t('Other shops') })" x-text="$t('None')"></button>
                        </span>
                        <span class="text-stone-400 transition-transform group-open/shop:rotate-90" aria-hidden="true">›</span>
                    </summary>
                    <ul class="divide-y divide-stone-100 border-t border-stone-200">
                        <template x-for="offer in group.offers" :key="offer.id">
                            <li>
                                <label class="flex cursor-pointer items-center gap-3 px-3 py-2" :class="isSelected(offer) || 'text-stone-500'">
                                    <input type="checkbox" class="size-5 shrink-0 accent-emerald-700" :checked="isSelected(offer)" @change="selectOffers([offer], $event.target.checked)">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate" x-text="offer.name"></span>
                                        <span class="block truncate text-xs text-stone-500" x-text="[offer.brand, offer.discount, offer.price != null ? 'CHF ' + Number(offer.price).toFixed(2) : null].filter(Boolean).join(' · ')"></span>
                                    </span>
                                </label>
                            </li>
                        </template>
                    </ul>
                </details>
            </template>
            <p class="text-sm text-stone-500" x-text="$t(':selected of :total offers selected', { selected: selectedIds.length, total: savedOffers?.length ?? 0 })"></p>
        </div>

        {{-- Reading new offers is the exception: collapsed unless there is nothing else --}}
        <details class="group/read rounded-lg border border-dashed border-stone-300"
            x-effect="if (savedOffers !== null && !savedOffers.length) $el.open = true">
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
    <x-planner-step number="2" title="$t('At home (optional)')" x-show="step === 'pantry'">
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
    <x-planner-step number="3" title="$t('Recipes')" x-show="step === 'recipes'">
        <button type="button" class="button flex w-full items-center justify-center gap-2 py-3 text-lg" :disabled="busy || (!selectedOffers.length && !selectedPantry.length)" @click="suggest()">
            <x-spinner x-show="busyKey === 'suggest'" />
            <span x-text="label('suggest', $t('Suggest recipes'))"></span>
        </button>
        {{-- Rarely changed: servings, number, wishes and AI ideas --}}
        <details class="group/options rounded-lg border border-stone-200">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2 text-sm text-stone-600">
                <span class="min-w-0 truncate">
                    <span class="font-medium" x-text="$t('Options')"></span>
                    <span class="text-stone-500" x-text="' · ' + [$t(':count servings', { count: servings }), $t(count === 1 ? ':count recipe' : ':count recipes', { count }), wishes.trim()].filter(Boolean).join(' · ')"></span>
                </span>
                <span class="transition-transform group-open/options:rotate-90" aria-hidden="true">›</span>
            </summary>
            <div class="space-y-3 border-t border-stone-200 p-3">
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
                <input x-model="wishes" class="field w-full" maxlength="500" :placeholder="$t('Wishes, e.g. vegetarian, quick, no fish')" :aria-label="$t('Wishes')">
                <label x-show="recipes?.connected" class="flex items-center gap-2 text-sm">
                    <input type="checkbox" class="size-4 accent-emerald-700" x-model="ideas">
                    <span x-text="$t('Also new recipe ideas of the AI')"></span>
                </label>
            </div>
        </details>
        <x-planner-error key="suggest" />
        <p x-show="warning && !warningMore" class="notice" x-text="warning"></p>

        {{-- Season calendar of the recipe app (the suggestions prefer foods in season) --}}
        <a x-show="recipes?.url" :href="`${recipes?.url}/season`" target="_blank" rel="noopener"
            class="flex items-center gap-3 rounded-lg border border-lime-200 bg-lime-50 px-4 py-3 text-sm text-lime-900 hover:bg-lime-100">
            <svg viewBox="0 0 24 24" fill="none" class="size-5 shrink-0 text-lime-700" aria-hidden="true">
                <path d="M11 20A7 7 0 0 1 4 13c0-5 4-9 16-9 0 12-4 16-9 16Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M4 20c4-4 7-7 11-10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <span class="min-w-0 flex-1">
                <span class="block font-medium" x-text="$t('Season calendar')"></span>
                <span class="block text-xs text-lime-800" x-text="$t('What is in season now; the suggestions prefer it.')"></span>
            </span>
            <span aria-hidden="true">↗</span>
        </a>

        {{-- Planned recipes (saved; the list is built from them) --}}
        <x-planned-recipes />

        {{-- Suggestions: tap opens the details; "Plan" saves one (Recipes tab), the list is built from the planned ones --}}
        <div class="space-y-3">
            <template x-for="suggestion in suggestions" :key="suggestion.key">
                <div class="rounded-lg border border-stone-200 bg-white p-4">
                    <div class="flex items-start gap-3">
                        <button type="button" class="min-w-0 flex-1 space-y-1 text-left" @click="openDetail(suggestion)" :aria-label="$t('Show “:name”', { name: suggestion.name })">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-medium underline decoration-stone-300 underline-offset-2" x-text="suggestion.name"></span>
                                <span class="rounded-full px-2 py-0.5 text-xs"
                                    :class="suggestion.source === 'ai' ? 'bg-violet-100 text-violet-800' : 'bg-emerald-100 text-emerald-800'"
                                    x-text="suggestion.source === 'ai' ? $t('AI idea') : recipes?.name"></span>
                            </span>
                            <span class="block text-sm text-stone-600" x-text="suggestion.reason"></span>
                            <span x-show="suggestion.uses_offers?.length" class="block text-xs text-orange-800" x-text="$t('Offers') + ': ' + (suggestion.uses_offers ?? []).join(', ')"></span>
                            <span x-show="suggestion.uses_pantry?.length" class="block text-xs text-emerald-800" x-text="$t('At home') + ': ' + (suggestion.uses_pantry ?? []).join(', ')"></span>
                            <span x-show="suggestion.uses_season?.length" class="block text-xs text-lime-800" x-text="$t('In season') + ': ' + (suggestion.uses_season ?? []).join(', ')"></span>
                        </button>
                        <label class="w-16 shrink-0">
                            <span class="sr-only" x-text="$t('Servings')"></span>
                            <input x-model.number="suggestion.servings" type="number" min="1" max="20" inputmode="numeric" class="field w-full px-2 text-center text-sm" :title="$t('Servings')">
                        </label>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="button-secondary inline-flex items-center gap-2 px-3 py-1.5 text-sm" :disabled="busy" @click="plan(suggestion)"
                            :aria-label="$t('Plan “:name”', { name: suggestion.name })">
                            <x-spinner x-show="busyKey === `plan:${suggestion.key}`" />
                            <span x-text="label(`plan:${suggestion.key}`, $t('Plan'))"></span>
                        </button>
                    </div>
                    <p x-show="error && errorKey === `plan:${suggestion.key}` && detail?.key !== suggestion.key" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" x-text="error"></p>
                </div>
            </template>
        </div>
        <button type="button" x-show="suggestions.length" class="button-secondary flex w-full items-center justify-center gap-2" :disabled="busy" @click="suggest(true)">
            <x-spinner x-show="busyKey === 'more'" />
            <span x-text="label('more', $t('Suggest more recipes'))"></span>
        </button>
        <x-planner-error key="more" />
        <p x-show="warning && warningMore" class="notice" x-text="warning"></p>

        {{-- Details of a suggestion or a planned recipe: plan it (or take it out), import an AI idea into the recipe app --}}
        <x-recipe-dialog>
            <template x-if="detail.source === 'ai' && recipes?.connected">
                <span class="mr-auto">
                    <a x-show="detail.savedUrl || detail.draft_url" :href="detail.savedUrl || detail.draft_url" target="_blank" rel="noopener" class="button-secondary inline-flex" x-text="$t('Check and import in :name', { name: recipes.name })"></a>
                    <button x-show="!detail.savedUrl && !detail.draft_url" type="button" class="button-secondary inline-flex items-center gap-2" :disabled="busy" @click="saveIdea(detail)">
                        <x-spinner x-show="busyKey === `idea:${detail.key}`" />
                        <span x-text="label(`idea:${detail.key}`, $t('Import into :name', { name: recipes.name }))"></span>
                    </button>
                </span>
            </template>
            <button type="button" x-show="detail.planned" class="button-secondary ml-auto inline-flex items-center gap-2" :disabled="busy" @click="unplan(detail)">
                <x-spinner x-show="busyKey === `unplan:${detail.id}`" />
                <span x-text="label(`unplan:${detail.id}`, $t('Unplan'))"></span>
            </button>
            <button type="button" x-show="!detail.planned" class="button ml-auto inline-flex items-center gap-2" :disabled="busy" @click="plan(detail)">
                <x-spinner x-show="busyKey === `plan:${detail.key}`" />
                <span x-text="label(`plan:${detail.key}`, $t('Plan'))"></span>
            </button>
            <p x-show="error && [`plan:${detail.key}`, `idea:${detail.key}`, `unplan:${detail.id}`].includes(errorKey)" class="w-full rounded-lg bg-red-50 px-3 py-2 text-red-800" role="alert" x-text="error"></p>
        </x-recipe-dialog>
    </x-planner-step>

    {{-- 4. Shopping list --}}
    <x-planner-step number="4" title="$t('Shopping list')" x-show="step === 'list'">
        {{-- The list is built from all planned recipes; each can be taken out --}}
        <x-planned-recipes />
        <button type="button" x-show="planned.length" class="button flex w-full items-center justify-center gap-2" :disabled="busy" @click="buildList()">
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
                            <template x-if="item.store"><x-store-badge tag="item.store" /></template>
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
                <button type="button" x-show="mergeCount === null" class="button w-full" :disabled="!selectedItems.length" @click="addToList()"
                    x-text="$t('Add :count items', { count: selectedItems.length })"></button>
                {{-- Some ingredients are already open on the chosen list: merge or add them anyway --}}
                <div x-show="mergeCount !== null" class="notice space-y-3" role="alertdialog" aria-live="polite">
                    <p x-text="$t('Already on the list: :count. Merge them (amounts added, recipes kept)?', { count: mergeCount })"></p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="button" @click="addToList(true)" x-text="$t('Merge')"></button>
                        <button type="button" class="button-secondary" @click="addToList(false)" x-text="$t('Add separately')"></button>
                        <button type="button" class="button-secondary" @click="mergeCount = null" x-text="$t('Cancel')"></button>
                    </div>
                </div>
            </div>
        </template>
    </x-planner-step>

    {{-- Back and next (the back gesture also goes back one step); "Next" waits for what the next step needs --}}
    <div x-show="route.view === 'planner' && step" class="fixed inset-x-0 bottom-0 z-20 border-t border-stone-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur">
        <div class="mx-auto flex max-w-xl items-center gap-3 px-3 py-2">
            <button type="button" class="button-secondary shrink-0" :disabled="stepIndex <= 0" @click="goStep(steps[stepIndex - 1])" x-text="$t('Back')"></button>
            <p class="min-w-0 flex-1 text-center text-xs text-stone-500" x-text="nextBlocked ?? ''"></p>
            <button type="button" x-show="nextStep" class="button shrink-0" :disabled="!nextStep || !canOpen(nextStep)" @click="goStep(nextStep)" x-text="$t('Next')"></button>
        </div>
    </div>
</section>
