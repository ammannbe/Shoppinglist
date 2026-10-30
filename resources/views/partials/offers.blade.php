{{--
    Offers pool (#/offers, resources/js/offers.js): own offers and the ones other users shared,
    read by the AI or entered by hand. The week planner takes them. Needs a connection.
--}}
<section x-show="route.view === 'offers'" class="space-y-4" x-data="offersPool({ storeLabel: (tag) => storeLabel(tag) })">
    <p class="sr-only" role="status" x-text="busy ?? ''"></p>

    <div class="flex items-center gap-2">
        <p class="min-w-0 flex-1 text-sm text-stone-500" x-text="$t('For the week planner. Expired offers are removed automatically.')"></p>
        <button type="button" class="icon-button" :disabled="busy" @click="load()" :aria-label="$t('Reload')" :title="$t('Reload')">
            <x-spinner x-show="busyKey === 'load'" />
            <svg x-show="busyKey !== 'load'" viewBox="0 0 24 24" class="size-5"><path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </div>
    <x-planner-error key="load" />

    <div class="grid grid-cols-2 gap-2">
        <button type="button" class="button" @click="openForm()" x-text="$t('Add offer')"></button>
        <button type="button" class="button-secondary" :aria-expanded="reading" @click="reading = !reading" x-text="$t('Read offers')"></button>
    </div>
    <div x-show="reading" x-cloak class="rounded-xl border border-stone-200 bg-white p-4">
        <x-offer-reader />
    </div>

    <template x-if="loaded && offers.length">
        <div class="space-y-3">
            <input x-model="search" type="search" class="field w-full" autocomplete="off" :placeholder="$t('Search offers')" :aria-label="$t('Search offers')">
            <div x-show="stores.length > 1" class="flex flex-wrap gap-1.5" role="group" :aria-label="$t('Filter by shop')">
                <button type="button" class="rounded-full border px-3 py-1 text-sm" :aria-pressed="!storeFilter.length"
                    :class="storeFilter.length ? 'border-stone-300 bg-white' : 'border-emerald-700 bg-emerald-700 text-white'"
                    @click="storeFilter = []" x-text="$t('All shops')"></button>
                <template x-for="store in stores" :key="store">
                    <button type="button" class="max-w-40 truncate rounded-full border px-3 py-1 text-sm" :aria-pressed="storeFilter.includes(store)"
                        :class="storeFilter.includes(store) ? 'border-orange-600 bg-orange-600 text-white' : 'border-stone-300 bg-white'"
                        @click="toggleStore(store)" x-text="storeLabel(store)"></button>
                </template>
            </div>
        </div>
    </template>

    <p x-show="regionHint.length" x-cloak class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <span x-text="$t('Offers of all regions are shown for :shops.', { shops: regionHint.join(', ') })"></span>
        <a href="#/settings" class="underline" x-text="$t('Choose your region')"></a>
    </p>

    <p x-show="loaded && !shown.length" class="py-6 text-center text-stone-500" x-text="offers.length ? $t('No offers found.') : $t('No offers yet.')"></p>

    {{-- Per shop, collapsible; own offers of a shop can be deleted at once --}}
    <template x-for="group in groups" :key="group.store">
        <details class="group/shop overflow-hidden rounded-xl border border-stone-200 bg-white" x-init="$el.open = groups.length === 1 || group.offers.length <= 8">
            <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3">
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium" x-text="storeLabel(group.store)"></span>
                    <span class="block truncate text-xs text-stone-500" x-text="$t(group.offers.length === 1 ? ':count offer' : ':count offers', { count: group.offers.length })"></span>
                </span>
                <button type="button" x-show="group.offers.some((offer) => offer.own)" class="inline-flex shrink-0 items-center gap-1.5 text-sm text-stone-500 underline"
                    :disabled="busy" @click.prevent.stop="removeStore(group)">
                    <x-spinner x-show="busyKey === `store:${group.store}`" />
                    <span x-text="$t('Delete own')"></span>
                </button>
                <span class="text-stone-400 transition-transform group-open/shop:rotate-90" aria-hidden="true">›</span>
            </summary>
            <ul class="divide-y divide-stone-100 border-t border-stone-200">
                <template x-for="offer in group.offers" :key="offer.id">
                    <li class="flex items-center gap-3 px-4 py-2">
                        <div class="min-w-0 flex-1">
                            <span class="block truncate" x-text="offer.name"></span>
                            <span class="block truncate text-xs text-stone-500" x-text="details(offer)"></span>
                        </div>
                        <span x-show="offer.own && !offer.shared" class="shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600" x-text="$t('Private')"></span>
                        <button type="button" x-show="offer.own" class="icon-button" @click="openForm(offer)" :aria-label="$t('Edit')">
                            <svg viewBox="0 0 24 24" class="size-5"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                        </button>
                    </li>
                </template>
            </ul>
        </details>
    </template>
    <p x-show="error && errorKey?.startsWith('store:')" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" x-text="error"></p>

    {{-- Add or edit an own offer --}}
    <dialog x-ref="offerDialog" class="dialog" @close="form = null">
        <template x-if="form">
            <form @submit.prevent="saveForm()" class="space-y-3">
                <h2 class="font-semibold" x-text="form.id ? $t('Edit offer') : $t('Add offer')"></h2>
                <label class="block">
                    <span class="label" x-text="$t('Product')"></span>
                    <input x-model="form.name" class="field w-full" maxlength="255" required>
                </label>
                <div class="flex gap-3">
                    <label class="block min-w-0 w-1/2">
                        <span class="label" x-text="$t('Shop')"></span>
                        <input x-model="form.store" class="field w-full" maxlength="255" list="offer-stores">
                        <datalist id="offer-stores">
                            <template x-for="store in choosableStores" :key="store.id">
                                <option :value="storeTag(store)" x-text="store.name"></option>
                            </template>
                        </datalist>
                    </label>
                    <label class="block min-w-0 w-1/2">
                        <span class="label" x-text="$t('Brand')"></span>
                        <input x-model="form.brand" class="field w-full" maxlength="255">
                    </label>
                </div>
                {{-- Shops with regional offers: new offers default to the user's region --}}
                <template x-if="regionsOf(form.store).length">
                    <label class="block" x-init="if (!form.id && !form.region) form.region = chosenRegion(form.store) ?? ''">
                        <span class="label" x-text="$t('Region')"></span>
                        <select x-model="form.region" class="field w-full">
                            <option value="" x-text="$t('All regions')"></option>
                            <template x-for="[key, name] in regionsOf(form.store)" :key="key">
                                <option :value="key" x-text="name" :selected="form.region === key"></option>
                            </template>
                        </select>
                    </label>
                </template>
                <div class="flex gap-3">
                    <label class="block min-w-0 w-1/3">
                        <span class="label" x-text="$t('Discount')"></span>
                        <input x-model="form.discount" class="field w-full" maxlength="100" :placeholder="$t('e.g. −30 %')">
                    </label>
                    <label class="block min-w-0 w-1/3">
                        <span class="label" x-text="$t('Price')"></span>
                        <input x-model="form.price" class="field w-full" inputmode="decimal">
                    </label>
                    <label class="block min-w-0 w-1/3">
                        <span class="label" x-text="$t('Instead of')"></span>
                        <input x-model="form.regular_price" class="field w-full" inputmode="decimal">
                    </label>
                </div>
                <div class="flex gap-3">
                    <label class="block min-w-0 w-1/2">
                        <span class="label" x-text="$t('Valid from')"></span>
                        <input x-model="form.valid_from" type="date" class="field w-full">
                    </label>
                    <label class="block min-w-0 w-1/2">
                        <span class="label" x-text="$t('Valid until')"></span>
                        <input x-model="form.valid_until" type="date" class="field w-full" :min="form.valid_from || null">
                    </label>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" class="size-4 accent-emerald-700" x-model="form.shared">
                    <span x-text="$t('Share with all users')"></span>
                </label>
                <x-planner-error key="form" />
                <div class="flex flex-wrap items-center gap-2 pt-2">
                    <button type="button" x-show="form.id" class="mr-auto inline-flex items-center gap-2 text-red-700 underline" :disabled="busy" @click="remove(form)">
                        <x-spinner x-show="busyKey === `delete:${form.id}`" />
                        <span x-text="$t('Delete')"></span>
                    </button>
                    <button type="button" class="button-secondary ml-auto" @click="$refs.offerDialog.close()" x-text="$t('Cancel')"></button>
                    <button class="button inline-flex items-center gap-2" :disabled="busy">
                        <x-spinner x-show="busyKey === 'form'" />
                        <span x-text="label('form', $t('Save'))"></span>
                    </button>
                </div>
                <p x-show="error && errorKey.startsWith('delete:')" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" x-text="error"></p>
            </form>
        </template>
    </dialog>
</section>
