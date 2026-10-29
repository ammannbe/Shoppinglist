{{--
    Week planner (#/planner, resources/js/planner.js): offers and pantry read by the AI,
    recipe suggestions (recipe app and AI ideas), shopping list. Needs a connection.
--}}
<section x-show="route.view === 'planner'" class="space-y-6"
    x-data="planner({ addItems: (target, name, items) => addPlannedItems(target, name, items), lists: () => lists })">
    <p class="text-stone-600" x-text="$t('Read the offers of the week, check what you have at home and get recipe ideas with a shopping list.')"></p>
    <div x-show="recipes && !recipes.connected" class="notice flex flex-wrap items-center justify-between gap-3">
        <span x-text="$t('Not connected with :name yet: connect to get your own recipes suggested and to create new recipes there right away.', { name: recipes?.name })"></span>
        <a href="/recipes/connect" data-online class="button-secondary shrink-0 text-sm" x-text="$t('Connect with :name', { name: recipes?.name })"></a>
    </div>

    <p x-show="error" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" x-text="error"></p>
    <p x-show="busy" class="flex items-center gap-3 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
        <span class="size-4 shrink-0 animate-spin rounded-full border-2 border-emerald-700 border-t-transparent" aria-hidden="true"></span>
        <span x-text="busy"></span>
    </p>

    {{-- 1. Offers --}}
    <div class="space-y-3">
        <h2 class="font-semibold" x-text="'1. ' + $t('Offers')"></h2>
        <p class="text-sm text-stone-500" x-text="$t('Screenshots from the shop app, the flyer as PDF or photos of it. Migros, Coop, Aldi, Lidl and Denner get their shop code automatically.')"></p>
        <label class="button-secondary inline-flex cursor-pointer items-center gap-2" :class="busy && 'pointer-events-none opacity-50'">
            <input type="file" accept="image/*,application/pdf" multiple class="sr-only" :disabled="busy" @change="readOfferFiles($event)">
            <span x-text="$t('Add screenshots, photos or PDF')"></span>
        </label>
        @php
            // How to get the offers of each shop (shop pages are German, the labels quoted here too).
            $offerSources = [
                ['Migros', 'https://www.migros.ch/de/offers/home', [
                    'Open the offers page.',
                    'Select all text (Ctrl+A, on the Mac Cmd+A) and copy it.',
                    'Paste it into the text field below and tap “Read text”.',
                ]],
                ['Coop', 'https://www.coop.ch/de/aktionen/aktuelle-aktionen/c/m_1011', [
                    'Open the offers page and choose your store.',
                    'Click “Aktionsmagazin auswählen”.',
                    'Menu → “Speichern”, choose “Alle” pages.',
                    'Add the PDF above.',
                ]],
                ['Aldi', 'https://www.aldi-suisse.ch/de/broschuere', [
                    'Open the flyer and click “Durchblättern”.',
                    'Download it with “PDF herunterladen”.',
                    'Add the PDF above.',
                ]],
                ['Lidl', 'https://www.lidl.ch/de/unternehmen/werbeprospekte-als-pdf', [
                    'Open the flyer, then “Menü” → “PDF Download”.',
                    'Add the PDF above.',
                ]],
                ['Denner', 'https://www.denner.ch/de/aktionen/aktuelle-aktionen', [
                    'Open the offers page.',
                    'For more offers, click “Weitere Produkte anzeigen” at the end of the page (repeatedly if needed).',
                    'Select all text (Ctrl+A, on the Mac Cmd+A) and copy it.',
                    'Paste it into the text field below and tap “Read text”.',
                ]],
            ];
        @endphp
        <details class="group/details overflow-hidden rounded-lg border border-stone-200 bg-white text-sm">
            <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 hover:bg-stone-50">
                <span x-text="$t('How to get the offers of each shop')"></span>
                <span class="transition-transform group-open/details:rotate-90" aria-hidden="true">›</span>
            </summary>
            <div class="space-y-4 border-t border-stone-200 px-4 py-3">
                @foreach ($offerSources as [$shop, $url, $steps])
                    <div>
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="font-medium underline">{{ $shop }}</a>
                        <ol class="mt-1 list-decimal space-y-0.5 pl-5 text-stone-600">
                            @foreach ($steps as $step)
                                <li x-text="$t(@js($step))"></li>
                            @endforeach
                        </ol>
                    </div>
                @endforeach
                <p class="text-stone-500" x-text="$t('Copying works best on a computer. Long texts are read in several parts.')"></p>
            </div>
        </details>
        <form @submit.prevent="readOfferText()" class="space-y-2">
            <textarea x-model="offerText" rows="2" class="field w-full text-sm" :placeholder="$t('… or paste the offers as text')"></textarea>
            <button x-show="offerText.trim()" class="button-secondary text-sm" :disabled="busy" x-text="$t('Read text')"></button>
        </form>

        <ul x-show="offers.length" class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
            <template x-for="(offer, index) in offers" :key="index">
                <li class="flex items-center gap-3 px-3 py-2">
                    <input type="checkbox" class="size-5 shrink-0 accent-emerald-700" x-model="offer.selected" :aria-label="offer.name">
                    <span x-show="offer.store" class="grid h-5 min-w-5 shrink-0 place-items-center rounded bg-orange-100 px-1 text-xs font-semibold text-orange-800" :title="storeLabel(offer.store)" x-text="offer.store"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate" x-text="offer.name"></span>
                        <span class="block truncate text-xs text-stone-500" x-text="[offer.brand, offer.discount, offer.price != null ? 'CHF ' + Number(offer.price).toFixed(2) : null].filter(Boolean).join(' · ')"></span>
                    </span>
                    <button type="button" class="icon-button text-stone-400" @click="removeOffer(index)" :aria-label="$t('Remove')">✕</button>
                </li>
            </template>
        </ul>
        <p x-show="offers.length" class="text-sm text-stone-500" x-text="$t(':selected of :total offers selected', { selected: selectedOffers.length, total: offers.length })"></p>
    </div>

    {{-- 2. Pantry --}}
    <div class="space-y-3">
        <h2 class="font-semibold" x-text="'2. ' + $t('At home (optional)')"></h2>
        <p class="text-sm text-stone-500" x-text="$t('Photos of the fridge, freezer or pantry: what is already there is used first and left off the list.')"></p>
        <label class="button-secondary inline-flex cursor-pointer items-center gap-2" :class="busy && 'pointer-events-none opacity-50'">
            <input type="file" accept="image/*" multiple class="sr-only" :disabled="busy" @change="readPantry($event)">
            <span x-text="$t('Add photos')"></span>
        </label>
        <div x-show="pantry.length" class="flex flex-wrap gap-2">
            <template x-for="(item, index) in pantry" :key="index">
                <label class="flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1 text-sm"
                    :class="item.selected ? 'border-emerald-600 bg-emerald-50' : 'border-stone-300 bg-white text-stone-400 line-through'">
                    <input type="checkbox" class="sr-only" x-model="item.selected">
                    <span x-text="item.name + (item.amount ? ' (' + item.amount + ')' : '')"></span>
                </label>
            </template>
        </div>
        <form @submit.prevent="addPantryItem()" class="flex gap-2">
            <input x-model="newPantryItem" class="field min-w-0 flex-1 text-sm" :placeholder="$t('Add by hand, e.g. rice')">
            <button class="button-secondary text-sm" x-text="$t('Add')"></button>
        </form>
    </div>

    {{-- 3. Recipes --}}
    <div class="space-y-3">
        <h2 class="font-semibold" x-text="'3. ' + $t('Recipes')"></h2>
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
        <button type="button" class="button w-full" :disabled="busy || (!selectedOffers.length && !selectedPantry.length)" @click="suggest()" x-text="$t('Suggest recipes')"></button>
        <p x-show="warning" class="notice" x-text="warning"></p>

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
                                            <button x-show="!suggestion.savedUrl" type="button" class="font-medium underline" :disabled="busy" @click="saveIdea(suggestion)" x-text="$t('Import into :name', { name: recipes.name })"></button>
                                        </p>
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
    </div>

    {{-- 4. Shopping list --}}
    <div x-show="suggestions.length" class="space-y-3">
        <h2 class="font-semibold" x-text="'4. ' + $t('Shopping list')"></h2>
        <button type="button" class="button w-full" :disabled="busy || !selectedSuggestions.length" @click="buildList()" x-text="$t('Create shopping list')"></button>

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
    </div>

    <button type="button" x-show="offers.length || pantry.length || suggestions.length" class="text-sm text-stone-500 underline"
        @click="confirm($t('Start over?')) && reset()" x-text="$t('Start over')"></button>
</section>
