<!DOCTYPE html>
<html lang="de-CH">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ config('app.name') }}</title>
    <meta name="theme-color" content="#047857">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    @include('partials.favicon')
    @include('partials.meta')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    App shell: cached by the service worker and used offline. It must not contain user data
    or server side translations, the UI translates itself ($t) and reads the local database.
--}}
<body class="min-h-dvh bg-stone-50 text-stone-900 antialiased" :class="$store.sync.online || 'is-offline'" x-data="shoppinglist" x-cloak>
    <header class="sticky top-0 z-10 border-b border-stone-200 bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur">
        <div class="mx-auto flex h-14 max-w-xl items-center gap-2 px-3">
            <a :href="backLink" x-show="!['lists', 'offline'].includes(route.view)" class="icon-button" :aria-label="$t('Back')">
                <svg viewBox="0 0 24 24" class="size-6"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <h1 class="min-w-0 flex-1 truncate text-lg font-semibold">
                <span x-show="route.view === 'lists'" class="block truncate" x-text="$t('Shopping lists')"></span>
                <span x-show="route.view === 'settings'" class="block truncate" x-text="$t('Settings')"></span>
                <span x-show="route.view === 'install'" class="block truncate" x-text="$t('Install app')"></span>
                <span x-show="route.view === 'planner'" class="block truncate" x-text="$t('Week planner')"></span>
                <span x-show="route.view === 'offers'" class="block truncate" x-text="$t('Offers')"></span>
                <span x-show="route.view === 'recipes'" class="block truncate" x-text="$t('Recipe history')"></span>
                <span x-show="route.view === 'products'" class="block truncate" x-text="$t('Products')"></span>
                <span x-show="route.view === 'units'" class="block truncate" x-text="$t('Units')"></span>
                <span x-show="route.view === 'stores'" class="block truncate" x-text="$t('Shops')"></span>
                <span x-show="route.view === 'offline'" class="block truncate" x-text="$t('Offline')"></span>
                <template x-if="route.view === 'list' && list">
                    <span class="block min-w-0">
                        <span x-show="renaming === null" x-text="list.name" @click="startRename()" class="block cursor-text truncate"></span>
                        <form x-show="renaming !== null" @submit.prevent="renameList()">
                            <input x-ref="renameInput" x-model="renaming" @blur="renameList()" @keydown.escape="renaming = null"
                                class="w-full rounded border border-stone-300 px-2 py-1 text-base font-normal">
                        </form>
                    </span>
                </template>
            </h1>

            {{-- Sync state --}}
            <span class="flex shrink-0 items-center gap-1.5 text-xs text-stone-500" :title="$t('Sync status')">
                <span class="size-2 rounded-full"
                    :class="{
                        'bg-emerald-500': $store.sync.status === 'synced',
                        'bg-amber-400 animate-pulse': ['starting', 'syncing'].includes($store.sync.status),
                        'bg-stone-400': ['offline', 'local'].includes($store.sync.status),
                        'bg-red-500': ['error', 'expired', 'unverified', 'broken'].includes($store.sync.status),
                    }"></span>
                <span class="hidden min-[400px]:inline" x-text="{
                    starting: $t('Starting …'),
                    syncing: $t('Syncing …'),
                    synced: $t('Synced'),
                    offline: $t('Offline'),
                    local: $t('Only on this device'),
                    expired: $t('Not synced'),
                    unverified: $t('Not synced'),
                    error: $t('Sync error'),
                    broken: $t('Error'),
                }[$store.sync.status]"></span>
            </span>

            <a href="#/settings" x-show="['lists', 'list'].includes(route.view)" class="icon-button" :aria-label="$t('Settings')">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10.3 4.3c.4-1.7 3-1.7 3.4 0a1.7 1.7 0 0 0 2.6 1.1c1.5-.9 3.3.8 2.4 2.4a1.7 1.7 0 0 0 1 2.5c1.8.4 1.8 3 0 3.4a1.7 1.7 0 0 0-1 2.6c.9 1.5-.9 3.3-2.4 2.4a1.7 1.7 0 0 0-2.6 1c-.4 1.8-3 1.8-3.4 0a1.7 1.7 0 0 0-2.5-1c-1.6.9-3.3-.9-2.4-2.4a1.7 1.7 0 0 0-1.1-2.6c-1.7-.4-1.7-3 0-3.4a1.7 1.7 0 0 0 1.1-2.5c-.9-1.6.8-3.3 2.4-2.4a1.7 1.7 0 0 0 2.5-1.1z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>

        </div>
    </header>

    {{-- Account hints --}}
    <div class="mx-auto max-w-xl px-3">
        <div x-show="$store.sync.status === 'broken'" class="notice mt-3 space-y-2" role="alert">
            <p x-text="$t('The data on this device could not be opened.')"></p>
            <p x-text="$t('Resetting deletes it from this device. Synced lists come back from the server, changes that were not synced yet are lost.')"></p>
            <button type="button" class="font-medium underline" @click="confirm($t('Reset the data on this device?')) && $store.sync.resetLocalData()" x-text="$t('Reset data on this device')"></button>
        </div>
        <p x-show="$store.sync.status === 'local'" class="notice mt-3">
            <span x-text="$t('Log in to sync your lists across devices and share them.')"></span>
            <a href="/login" data-online class="font-medium underline" x-text="$t('Log in')"></a> ·
            <a href="/register" data-online class="font-medium underline" x-text="$t('Register')"></a>
        </p>
        <p x-show="$store.sync.status === 'expired'" class="notice mt-3">
            <span x-text="$t('Your session has expired. Please log in again.')"></span>
            <a href="/login" data-online class="font-medium underline" x-text="$t('Log in')"></a>
        </p>
        <p x-show="$store.sync.status === 'unverified'" class="notice mt-3">
            <span x-text="$t('Please verify your email address to sync.')"></span>
            <a href="/email/verify" data-online class="font-medium underline" x-text="$t('Resend Verification Email')"></a>
        </p>
    </div>

    <main x-show="$store.sync.status !== 'broken'" class="mx-auto max-w-xl px-3 pt-4 pb-[calc(2rem+env(safe-area-inset-bottom))]">
        {{-- Lists --}}
        <section x-show="route.view === 'lists'">
            <a x-show="assistantEnabled" href="#/planner" class="mb-4 flex items-center gap-3 rounded-lg border border-violet-200 bg-violet-50 p-4 hover:bg-violet-100">
                <span class="text-2xl" aria-hidden="true">✨</span>
                <span class="min-w-0 flex-1">
                    <span class="block font-medium" x-text="$t('Week planner')"></span>
                    <span class="block text-sm text-stone-600" x-text="$t('Offers, what is at home, recipes and the shopping list')"></span>
                </span>
                <span aria-hidden="true">›</span>
            </a>

            <form @submit.prevent="createList()" class="mb-4 flex gap-2">
                <input x-model="newListName" class="field min-w-0 flex-1" :placeholder="$t('New list')" :aria-label="$t('New list')" enterkeyhint="done">
                <button class="button shrink-0 px-3 min-[400px]:px-4" :aria-label="$t('Add')">
                    <span class="hidden min-[400px]:inline" x-text="$t('Add')"></span>
                    <svg viewBox="0 0 24 24" class="size-6 min-[400px]:hidden"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                </button>
            </form>

            <p x-show="!lists.length" class="py-8 text-center text-stone-500" x-text="$t('No lists yet.')"></p>

            <x-sort-select x-show="lists.length > 1" value="$store.prefs.list_sort" change="$store.prefs.set('list_sort', $event.target.value)" />

            {{-- Hold a row and move it (touch) or drag it (mouse) to change the order. --}}
            <ul x-show="lists.length" class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white" @row-move="moveRow('lists', $event.detail)">
                <template x-for="item in lists" :key="item.id">
                    <x-swipe-row actions="listActions(item)" x-bind:data-sort-id="item.id">
                        <a :href="`#/list/${item.id}`" class="flex min-w-0 flex-1 items-center gap-3 py-3 pl-4 pointer-coarse:pr-4 pointer-fine:pr-3" draggable="false">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium" x-text="item.name"></span>
                                <span x-show="item.is_owner === false" class="block truncate text-xs text-stone-500" x-text="$t('Shared by :email', { email: item.owner_email })"></span>
                                <span x-show="item.is_owner !== false && item.members?.length" class="block truncate text-xs text-stone-500" x-text="$t('Shared with :emails', { emails: item.members?.join(', ') })"></span>
                            </span>
                            <span class="rounded-full bg-stone-100 px-2 py-0.5 text-sm tabular-nums text-stone-600" x-text="openCount(item.id)"></span>
                        </a>
                    </x-swipe-row>
                </template>
            </ul>
        </section>

        {{-- One list --}}
        <section x-show="route.view === 'list'">
            <template x-if="!list">
                <p class="py-8 text-center text-stone-500">
                    <span x-text="$t('This list does not exist (anymore).')"></span>
                    <a href="#/" class="underline" x-text="$t('Back')"></a>
                </p>
            </template>

            <template x-if="list">
                <div>
                    <form @submit.prevent="addItem()" class="mb-4 flex gap-2">
                        {{-- Shop of the new items (native picker on top of the badge), kept until changed --}}
                        <label class="field relative flex shrink-0 cursor-pointer items-center px-2" :title="newItemStore ? storeLabel(newItemStore) : $t('Shop')">
                            <template x-if="newItemStore"><x-store-badge tag="newItemStore" class="!mr-0" /></template>
                            <svg x-show="!newItemStore" viewBox="0 0 24 24" class="size-5 text-stone-500" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 9l1.5-5h13L20 9M4 9h16v11H4zM9 20v-6h6v6"/>
                            </svg>
                            <select x-model="newItemStore" class="absolute inset-0 cursor-pointer opacity-0" :aria-label="$t('Shop for new items')">
                                <option value="" x-text="$t('No shop')"></option>
                                <template x-for="store in quickStores" :key="store.id">
                                    <option :value="storeTag(store)" x-text="store.name" :selected="storeTag(store) === newItemStore"></option>
                                </template>
                            </select>
                        </label>
                        {{-- Picking a suggestion adds the item right away, the amount typed before is kept. --}}
                        <div class="relative min-w-0 flex-1" @click.outside="close()"
                            x-data="suggestField({ source: 'item', get: () => newItemText, set: (value) => newItemText = value, units: () => unitNames, onPick: () => addItem(), openItems: () => openItems })">
                            <input x-model="newItemText" class="field w-full" autocomplete="off" autocorrect="off" enterkeyhint="enter"
                                role="combobox" aria-autocomplete="list" aria-controls="item-suggestions" :aria-expanded="visible"
                                :aria-activedescendant="active >= 0 ? `item-suggestions-${active}` : null"
                                @input="input()" @focus="input()" @blur="blur()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                                @keydown.enter="enter($event)" @keydown.escape="close()"
                                :placeholder="$t('Add item, e.g. “2 l milk”')" :aria-label="$t('Add item')">
                            <x-suggestions id="item-suggestions" />
                        </div>
                        <button class="button shrink-0 px-3 min-[400px]:px-4" :aria-label="$t('Add')">
                    <span class="hidden min-[400px]:inline" x-text="$t('Add')"></span>
                    <svg viewBox="0 0 24 24" class="size-6 min-[400px]:hidden"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                </button>
                    </form>

                    {{-- Same product already open on the list --}}
                    <template x-if="duplicate">
                        <div class="notice -mt-2 mb-4 flex flex-wrap items-center gap-x-4 gap-y-2" role="status">
                            <span class="min-w-0 flex-1 basis-full min-[400px]:basis-auto"
                                x-text="$t('“:name” is already on the list.', { name: duplicate.existing.name }) + (amount(duplicate.existing) ? ` (${amount(duplicate.existing)})` : '')"></span>
                            <button type="button" x-show="duplicate.merged !== null" class="font-medium underline" @click="mergeDuplicate()"
                                x-text="$t('Increase to :amount', { amount: amount({ amount: duplicate.merged, unit: duplicate.existing.unit ?? duplicate.parsed.unit }) })"></button>
                            <button type="button" class="font-medium underline" @click="addDuplicate()" x-text="$t('Add anyway')"></button>
                            <button type="button" class="ml-auto" @click="duplicate = null" :aria-label="$t('Close')">✕</button>
                        </div>
                    </template>

                    <p x-show="!openItems.length" class="py-6 text-center text-stone-500" x-text="$t('Nothing to buy.')"></p>

                    <x-sort-select x-show="openItems.length > 1" value="itemSort" change="setItemSort($event.target.value)" />

                    {{-- Filter by shop (this device): items without a shop are always shown --}}
                    <div x-show="listStoreTags.length > 1 || storeFilter.length" class="mb-3 flex flex-wrap items-center gap-1.5" role="group" :aria-label="$t('Filter by shop')">
                        <button type="button" class="rounded-full border px-3 py-1 text-sm" :aria-pressed="!storeFilter.length"
                            :class="storeFilter.length ? 'border-stone-300 bg-white' : 'border-emerald-700 bg-emerald-700 text-white'"
                            @click="clearStoreFilter()" x-text="$t('All shops')"></button>
                        <template x-for="tag in listStoreTags" :key="tag">
                            <button type="button" class="flex max-w-40 items-center gap-1.5 rounded-full border px-3 py-1 text-sm" :aria-pressed="storeFilter.includes(tag)"
                                :class="storeFilter.includes(tag) ? 'border-orange-600 bg-orange-600 text-white' : 'border-stone-300 bg-white'"
                                :title="storeLabel(tag)" @click="toggleStoreFilter(tag)">
                                <img x-show="storeIcon(tag)" :src="storeIcon(tag)" alt="" class="-ml-1.5 h-5 w-auto max-w-16 shrink-0 rounded bg-white object-contain">
                                <span class="truncate" x-text="storeLabel(tag)"></span>
                            </button>
                        </template>
                    </div>
                    <p x-show="shownOpenItems.length < openItems.length" class="mb-2 text-sm text-stone-500"
                        x-text="$t('Hidden (other shops): :count', { count: openItems.length - shownOpenItems.length })"></p>

                    <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white" x-show="shownOpenItems.length" @row-move="moveRow('items', $event.detail)">
                        <template x-for="item in shownOpenItems" :key="item.id">
                            <x-swipe-row actions="itemActions(item)" x-bind:data-sort-id="item.id">
                                {{-- Tap marks as done, edit/delete via swipe or "…" --}}
                                <button type="button" class="flex min-w-0 flex-1 items-center py-3 pr-4 text-left" role="checkbox" aria-checked="false" @click="toggle(item)">
                                    <span class="check" aria-hidden="true"></span>
                                    <img x-show="item.image" :src="imageUrl(item.image, 'thumb')" @click.stop="openImage(item.image)" :alt="$t('Show photo')" loading="lazy" decoding="async" class="mr-3 size-8 shrink-0 cursor-zoom-in rounded-md bg-stone-100 object-cover">
                                    <template x-for="tag in item.stores" :key="tag">
                                        <x-store-badge tag="tag" />
                                    </template>
                                    <span x-show="item.stores.length" class="mr-0.5" aria-hidden="true"></span>
                                    <span x-show="amount(item)" class="mr-2 shrink-0 tabular-nums text-stone-500" x-text="amount(item)"></span>
                                    <span class="min-w-0">
                                        <span class="block truncate" x-text="item.name"></span>
                                        <span x-show="item.note" class="block whitespace-pre-line break-words text-xs text-stone-500" x-text="item.note"></span>
                                        {{-- Links to the recipes are in the edit dialog (no links inside the row button) --}}
                                        <span x-show="item.recipes?.length" class="block truncate text-xs text-violet-700" x-text="$t('For :recipes', { recipes: item.recipes.map((recipe) => recipe.name).join(', ') })"></span>
                                    </span>
                                </button>
                            </x-swipe-row>
                        </template>
                    </ul>

                    <div x-show="doneItems.length" class="mt-6">
                        <div class="mb-2 flex items-center justify-between">
                            <button type="button" class="text-sm font-medium text-stone-600" @click="showDone = !showDone"
                                x-text="`${$t('Done')} (${doneItems.length}) ${showDone ? '▾' : '▸'}`"></button>
                            <button type="button" class="text-sm text-stone-600 underline" @click="clearDone()" x-text="$t('Clear done')"></button>
                        </div>
                        <ul x-show="showDone" class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                            <template x-for="item in doneItems" :key="item.id">
                                <x-swipe-row actions="itemActions(item)" class="text-stone-400">
                                    <button type="button" class="flex min-w-0 flex-1 items-center py-3 pr-4 text-left" role="checkbox" aria-checked="true" @click="toggle(item)">
                                        <span class="check check-done" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" class="size-4"><path d="M5 12l5 5 9-10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </span>
                                        <img x-show="item.image" :src="imageUrl(item.image, 'thumb')" @click.stop="openImage(item.image)" :alt="$t('Show photo')" loading="lazy" decoding="async" class="mr-3 opacity-50 size-8 shrink-0 cursor-zoom-in rounded-md bg-stone-100 object-cover">
                                        <template x-for="tag in item.stores" :key="tag">
                                            <x-store-badge tag="tag" muted />
                                        </template>
                                        <span x-show="amount(item)" class="mr-2 shrink-0 tabular-nums line-through" x-text="amount(item)"></span>
                                        <span class="truncate line-through" x-text="item.name"></span>
                                    </button>
                                </x-swipe-row>
                            </template>
                        </ul>
                    </div>
                </div>
            </template>
        </section>

        {{-- Settings --}}
        <section x-show="route.view === 'settings'" class="space-y-6">
            <div>
                <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Language')"></h2>
                <select class="field w-full" :value="$store.i18n.locale" @change="setLocale($event.target.value)">
                    <template x-for="(label, code) in $store.i18n.locales" :key="code">
                        <option :value="code" x-text="label" :selected="code === $store.i18n.locale"></option>
                    </template>
                </select>
            </div>

            {{-- Country of the standard shops offered in the shop choice --}}
            <div>
                <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Country of the shops')"></h2>
                <select class="field w-full" @change="$store.prefs.set('country', $event.target.value)">
                    <template x-for="option in [{ value: 'CH', label: $t('Switzerland') }, { value: 'DE', label: $t('Germany') }, { value: 'AT', label: $t('Austria') }]" :key="option.value">
                        <option :value="option.value" x-text="option.label" :selected="option.value === country"></option>
                    </template>
                </select>
            </div>

            {{-- Region of shops with regional offers (Migros cooperatives): offers of other regions are hidden --}}
            <template x-for="(regions, tag) in country === 'CH' ? shopRegions : {}" :key="tag">
                <div>
                    <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Offers of :shop', { shop: storeLabel(tag) })"></h2>
                    <select class="field w-full" @change="setShopRegion(tag, $event.target.value)">
                        <option value="" x-text="$t('All regions')" :selected="!$store.prefs.regions?.[tag]"></option>
                        <template x-for="(name, key) in regions" :key="key">
                            <option :value="key" x-text="name" :selected="$store.prefs.regions?.[tag] === key"></option>
                        </template>
                    </select>
                </div>
            </template>

            <div>
                <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Account')"></h2>
                <div class="rounded-lg border border-stone-200 bg-white p-4">
                    <template x-if="loggedIn">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="min-w-0 break-all" x-text="$t('Logged in as :email', { email: $store.sync.user.email })"></span>
                            <button type="button" class="button-secondary shrink-0 whitespace-nowrap" @click="logout()" :disabled="busy" x-text="$t('Log Out')"></button>
                            <a href="/account" data-online class="w-full text-sm font-medium underline" x-text="$t('Change password and passkeys')"></a>
                        </div>
                    </template>
                    <template x-if="!loggedIn">
                        <p>
                            <span x-text="$t('Log in to sync your lists across devices and share them.')"></span>
                            <a href="/login" data-online class="font-medium underline" x-text="$t('Log in')"></a> ·
                            <a href="/register" data-online class="font-medium underline" x-text="$t('Register')"></a>
                        </p>
                    </template>
                </div>
            </div>

            <fieldset>
                <legend class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Actions on touch devices')"></legend>
                <div class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                    <template x-for="option in [
                        { value: 'swipe', label: $t('Swipe left'), hint: $t('Edit, share and delete appear next to the row.') },
                        { value: 'longpress', label: $t('Long press'), hint: $t('A menu with edit, share and delete opens.') },
                    ]" :key="option.value">
                        <label class="flex cursor-pointer items-start gap-3 p-4 hover:bg-stone-50">
                            <input type="radio" name="row-actions" class="mt-1 accent-emerald-700" :value="option.value"
                                :checked="$store.prefs.row_actions === option.value" @change="$store.prefs.set('row_actions', option.value)">
                            <span>
                                <span class="block" x-text="option.label"></span>
                                <span class="block text-sm text-stone-500" x-text="option.hint"></span>
                            </span>
                        </label>
                    </template>
                </div>
            </fieldset>

            <div>
                <template x-if="recipeApp && loggedIn">
                    <div class="mb-6">
                        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="recipeApp.name"></h2>
                        <div class="rounded-lg border border-stone-200 bg-white p-4">
                            <template x-if="recipeApp.connected">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <span class="min-w-0 break-all" x-text="$t('Connected as :account', { account: recipeApp.account?.email ?? recipeApp.account?.name ?? '' })"></span>
                                    <button type="button" class="button-secondary shrink-0" :disabled="busy" @click="disconnectRecipes()" x-text="$t('Disconnect')"></button>
                                </div>
                            </template>
                            <template x-if="!recipeApp.connected">
                                <div class="space-y-3">
                                    <p class="text-sm text-stone-600" x-text="$t('Connect your account so the week planner suggests your recipes and can save new ones.')"></p>
                                    <a href="/recipes/connect" data-online class="button inline-block" x-text="$t('Connect with :name', { name: recipeApp.name })"></a>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Suggestions')"></h2>
                <div class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                    <a href="#/products" class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
                        <span x-text="$t('Products')"></span>
                        <span aria-hidden="true">›</span>
                    </a>
                    <a href="#/units" class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
                        <span x-text="$t('Units')"></span>
                        <span aria-hidden="true">›</span>
                    </a>
                    <a href="#/stores" class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
                        <span x-text="$t('Shops')"></span>
                        <span aria-hidden="true">›</span>
                    </a>
                </div>
            </div>

            <div>
                <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Install app')"></h2>
                <a href="#/install" class="flex items-center justify-between gap-3 rounded-lg border border-stone-200 bg-white p-4 hover:bg-stone-50">
                    <span x-text="$store.install.installed ? $t('The app is installed on this device.') : $t('How to add Shoppinglist to your home screen or dock')"></span>
                    <span aria-hidden="true">›</span>
                </a>
            </div>
        </section>
        {{-- Products, units and shops suggested when adding items: own ones can be edited, public ones are read-only --}}
        <section x-show="['products', 'units', 'stores'].includes(route.view)" class="space-y-4">
            <p class="text-stone-600" x-text="{
                units: $t('Units are suggested after the amount. Rename or delete your own units here, the standard units cannot be changed.'),
                stores: $t('Shops can be chosen when editing an item, the item shows the icon or the code. Add, edit or delete your own shops here (with an own image if you like); standard shops cannot be changed, but you can hide the ones you do not use.'),
                products: $t('Products are suggested when adding items. Rename or delete your own misspelled and unused ones here; standard products cannot be changed, but your photos are shown with them.'),
            }[route.view]"></p>
            {{-- Own products: add one, export them and import a CSV (one column "name") --}}
            <div x-show="route.view === 'products'" class="space-y-2">
                <form @submit.prevent="createProduct()" class="flex gap-2">
                    <input x-model="newProductName" class="field min-w-0 flex-1" maxlength="255" required :placeholder="$t('New product')" :aria-label="$t('New product')">
                    <button class="button-secondary" x-text="$t('Add')"></button>
                </form>
                <details class="text-sm">
                    <summary class="cursor-pointer text-stone-600 underline" x-text="$t('Export and import')"></summary>
                    <div class="mt-2 space-y-2 rounded-lg border border-stone-200 bg-white p-3">
                        <p class="text-stone-500" x-text="$t('A CSV file with one product name per line (column “name”). Photos are not included.')"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="button-secondary" @click="exportProducts()" :disabled="!ownProducts.length" x-text="$t('Export own products')"></button>
                            <label class="button-secondary cursor-pointer">
                                <span x-text="$t('Import and add')"></span>
                                <input type="file" accept=".csv,text/csv,text/plain" class="sr-only" @change="importProducts($event, 'add')">
                            </label>
                            <label class="button-secondary cursor-pointer">
                                <span x-text="$t('Import and replace own')"></span>
                                <input type="file" accept=".csv,text/csv,text/plain" class="sr-only" @change="importProducts($event, 'replace')">
                            </label>
                        </div>
                    </div>
                </details>
            </div>
            <form x-show="route.view === 'stores'" @submit.prevent="createStore()" class="flex gap-2">
                <input x-model="newStore.name" class="field min-w-0 flex-1" maxlength="255" required :placeholder="$t('New shop, e.g. Amazon')" :aria-label="$t('Name')">
                <input x-model="newStore.code" class="field w-28" maxlength="10" :placeholder="$t('Code (optional)')" :aria-label="$t('Code (optional)')">
                <button class="button-secondary" x-text="$t('Add')"></button>
            </form>
            <input x-model="catalogSearch" @input="catalogLimit = 200" type="search" class="field w-full" autocomplete="off"
                :placeholder="{ units: $t('Search units'), stores: $t('Search shops'), products: $t('Search products') }[route.view]"
                :aria-label="{ units: $t('Search units'), stores: $t('Search shops'), products: $t('Search products') }[route.view]">

            <p x-show="!catalog.length" class="py-6 text-center text-stone-500" x-text="{ units: $t('No units found.'), stores: $t('No shops found.'), products: $t('No products found.') }[route.view]"></p>

            <template x-if="['products', 'units', 'stores'].includes(route.view) && catalog.length">
                <div class="space-y-4">
                    <ul x-show="shownCatalog.some((entry) => !entry.is_public)" class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                        <template x-for="entry in shownCatalog.filter((entry) => !entry.is_public)" :key="entry.id">
                            <x-swipe-row actions="catalogActions(entry)">
                                <span class="flex min-w-0 flex-1 items-center gap-3 py-2 pl-4 pointer-coarse:pr-4">
                                    <img x-show="route.view === 'stores' && storeIconUrl(entry)" :src="storeIconUrl(entry)" alt="" class="h-6 w-auto max-w-20 shrink-0 rounded bg-white object-contain">
                                    <span x-show="entry.code" class="grid h-5 min-w-5 shrink-0 place-items-center rounded bg-orange-100 px-1 text-xs font-semibold text-orange-800" x-text="entry.code"></span>
                                    <img x-show="entry.image" :src="imageUrl(entry.image, 'thumb')" @click.stop="openImage(entry.image)" :alt="$t('Show photo')" loading="lazy" decoding="async" class="size-10 shrink-0 cursor-zoom-in rounded-md bg-stone-100 object-cover">
                                    <span class="min-w-0 flex-1 truncate py-1" x-text="entry.name"></span>
                                </span>
                            </x-swipe-row>
                        </template>
                    </ul>
                    <div x-show="shownCatalog.some((entry) => entry.is_public)">
                        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Standard')"></h2>
                        <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white text-stone-600">
                            <template x-for="entry in shownCatalog.filter((entry) => entry.is_public)" :key="entry.id">
                                <li class="flex items-center gap-3 px-4 py-3" :class="route.view === 'stores' && storeHidden(entry) && 'text-stone-400'">
                                    <img x-show="entry.image" :src="imageUrl(entry.image, 'thumb')" @click="openImage(entry.image)" :alt="$t('Show photo')" loading="lazy" decoding="async" class="-my-1 size-10 shrink-0 cursor-zoom-in rounded-md bg-stone-100 object-cover">
                                    <img x-show="route.view === 'stores' && storeIconUrl(entry)" :src="storeIconUrl(entry)" alt="" class="h-6 w-auto max-w-20 shrink-0 rounded bg-white object-contain">
                                    <span class="min-w-0 flex-1 truncate" x-text="entry.name"></span>
                                    {{-- Standard shops can be hidden from the shop choice (per user) --}}
                                    <label x-show="route.view === 'stores'" class="flex shrink-0 cursor-pointer items-center gap-2 text-sm text-stone-600">
                                        <input type="checkbox" class="size-4 accent-emerald-700" :checked="!storeHidden(entry)" @change="toggleStoreHidden(entry)">
                                        <span x-text="$t('Show')"></span>
                                    </label>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </template>
            <div x-show="catalog.length > shownCatalog.length" class="space-y-2 text-center">
                <p class="text-sm text-stone-500" x-text="$t('Showing :shown of :total.', { shown: shownCatalog.length, total: catalog.length })"></p>
                <button type="button" class="button-secondary inline-flex items-center gap-2" @click="showMoreCatalog()" :disabled="catalogLoading" :aria-busy="catalogLoading">
                    <x-spinner x-show="catalogLoading" />
                    <span x-text="$t('Show more')"></span>
                </button>
            </div>
        </section>

        @include('partials.planner')
        @include('partials.offers')
        @include('partials.recipe-history')

        {{-- A server page (login, account, …) was opened without a connection: the service worker served the app shell. --}}
        <section x-show="route.view === 'offline'" class="space-y-4 py-8 text-center">
            <p class="text-lg font-semibold" x-text="$t('This page needs an internet connection.')"></p>
            <p class="text-stone-600" x-text="$t('Your lists are still available offline.')"></p>
            <div class="flex flex-wrap justify-center gap-2 pt-2">
                <button type="button" class="button-secondary" @click="location.reload()" x-text="$t('Try again')"></button>
                <a href="/" class="button" x-text="$t('Back to the lists')"></a>
            </div>
        </section>

        {{-- Install instructions (offline available, part of the app shell) --}}
        <section x-show="route.view === 'install'" class="space-y-6">
            <p class="text-stone-600" x-text="$t('Installed, Shoppinglist starts like an app from the home screen, in full screen and without a connection.')"></p>

            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <h2 class="mb-3 font-semibold" x-text="`${$t('This device')}: ${$store.install.current.name}`"></h2>
                <p x-show="$store.install.installed" class="text-emerald-700" x-text="$t('The app is installed on this device.')"></p>
                <template x-if="!$store.install.installed">
                    <div>
                        <button type="button" x-show="$store.install.prompt" class="button mb-4 w-full" @click="$store.install.install()" x-text="$t('Install now')"></button>
                        <ol class="list-decimal space-y-1 pl-5">
                            <template x-for="text in $store.install.current.steps" :key="text"><li x-text="$t(text)"></li></template>
                        </ol>
                        <p x-show="$store.install.current.note" class="mt-3 text-sm text-stone-500" x-text="$t($store.install.current.note ?? '')"></p>
                    </div>
                </template>
            </div>

            <div>
                <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Other devices and browsers')"></h2>
                <div class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                    <template x-for="[id, platform] in $store.install.others" :key="id">
                        <details class="group/details">
                            <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 hover:bg-stone-50">
                                <span x-text="platform.name"></span>
                                <span class="transition-transform group-open/details:rotate-90" aria-hidden="true">›</span>
                            </summary>
                            <div class="px-4 pb-4">
                                <ol class="list-decimal space-y-1 pl-5 text-sm">
                                    <template x-for="text in platform.steps" :key="text"><li x-text="$t(text)"></li></template>
                                </ol>
                                <p x-show="platform.note" class="mt-2 text-sm text-stone-500" x-text="$t(platform.note ?? '')"></p>
                            </div>
                        </details>
                    </template>
                </div>
            </div>
        </section>
    </main>

    {{-- First visit: install hint for the current platform --}}
    <div x-show="$store.install.showHint && route.view === 'lists' && !$store.undo.toast && !$store.notice.text" x-transition
        class="fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-20 mx-auto max-w-xl rounded-lg bg-stone-900 p-4 text-sm text-white shadow-lg">
        <p class="mb-1 font-semibold" x-text="$t('Install Shoppinglist as an app')"></p>
        <p x-show="$store.install.prompt" x-text="$t('Works offline and starts from your home screen.')"></p>
        <p x-show="!$store.install.prompt" x-text="$t($store.install.current.steps[0]) + ' ' + ($store.install.current.steps[1] ? $t($store.install.current.steps[1]) : '')"></p>
        <p class="mt-2 text-xs text-stone-300" x-text="$t('You can find these instructions in the settings at any time.')"></p>
        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
            <button type="button" x-show="$store.install.prompt" class="rounded-lg bg-white px-3 py-1.5 font-medium text-stone-900" @click="$store.install.install()" x-text="$t('Install now')"></button>
            <a href="#/install" class="font-medium underline" @click="$store.install.dismiss()" x-text="$t('Instructions')"></a>
            <button type="button" class="ml-auto font-medium underline" @click="$store.install.dismiss()" x-text="$t('Got it')"></button>
        </div>
    </div>

    {{-- Edit item --}}
    <dialog x-ref="editDialog" class="dialog overflow-visible" @close="editing = null">
        <template x-if="editing">
            <form @submit.prevent="saveItem()" class="space-y-4">
                <label class="block">
                    <span class="label" x-text="$t('Name')"></span>
                    <span class="relative block" @click.outside="close()" x-data="suggestField({ source: 'product', get: () => editing.name, set: (value) => editing.name = value })">
                        <input x-model="editing.name" class="field w-full" required autocomplete="off" autocorrect="off"
                            role="combobox" aria-autocomplete="list" aria-controls="edit-suggestions" :aria-expanded="visible"
                            :aria-activedescendant="active >= 0 ? `edit-suggestions-${active}` : null"
                            @input="input()" @blur="blur()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                            @keydown.enter="enter($event)" @keydown.escape="if (visible) { $event.preventDefault(); close() }">
                        <x-suggestions id="edit-suggestions" />
                    </span>
                </label>
                <div class="flex gap-3">
                    <label class="block min-w-0 w-1/2">
                        <span class="label" x-text="$t('Amount')"></span>
                        <input x-model="editing.amount" inputmode="decimal" class="field w-full">
                    </label>
                    <label class="block min-w-0 w-1/2">
                        <span class="label" x-text="$t('Unit')"></span>
                        <span class="relative block" @click.outside="close()"
                            x-data="suggestField({ source: 'unit', get: () => editing.unit ?? '', set: (value) => editing.unit = value, units: () => unitNames })">
                            <input x-model="editing.unit" class="field w-full" autocomplete="off" autocorrect="off" autocapitalize="off"
                                role="combobox" aria-autocomplete="list" aria-controls="unit-suggestions" :aria-expanded="visible"
                                :aria-activedescendant="active >= 0 ? `unit-suggestions-${active}` : null"
                                @input="input()" @focus="input()" @blur="blur()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                                @keydown.enter="enter($event)" @keydown.escape="if (visible) { $event.preventDefault(); close() }">
                            <x-suggestions id="unit-suggestions" />
                        </span>
                    </label>
                </div>
                {{-- Shops: picked ones as chips; a typed unknown shop becomes an own shop without code --}}
                <div>
                    <label for="edit-store" class="label" x-text="$t('Shops')"></label>
                    <div x-show="editing.stores.length" class="mb-2 flex flex-wrap gap-1.5">
                        <template x-for="tag in editing.stores" :key="tag">
                            <span class="flex max-w-full items-center gap-1 rounded-full bg-orange-100 py-0.5 pr-1 pl-2.5 text-sm text-orange-900">
                                <img x-show="storeIcon(tag)" :src="storeIcon(tag)" alt="" class="-ml-1.5 h-5 w-auto max-w-16 shrink-0 rounded bg-white object-contain">
                                <span class="truncate" x-text="storeLabel(tag)"></span>
                                <button type="button" class="grid size-6 shrink-0 place-items-center rounded-full hover:bg-orange-200" @click="removeEditingStore(tag)"
                                    :aria-label="$t('Remove :name', { name: storeLabel(tag) })">✕</button>
                            </span>
                        </template>
                    </div>
                    <span class="relative block" @click.outside="close()"
                        x-data="suggestField({ source: 'store', get: () => editing.storeText, set: (value) => editing.storeText = value, stores: () => editingStoreChoices, onPick: (option) => addEditingStore(storeTag(option)) })">
                        <input id="edit-store" x-model="editing.storeText" class="field w-full" maxlength="255" autocomplete="off" autocorrect="off"
                            :placeholder="editing.stores.length ? $t('Add another shop') : $t('Choose or type a shop')"
                            role="combobox" aria-autocomplete="list" aria-controls="store-suggestions" :aria-expanded="visible"
                            :aria-activedescendant="active >= 0 ? `store-suggestions-${active}` : null"
                            @input="input()" @focus="input()" @blur="blur()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                            @keydown.enter="if (active < 0 && editing.storeText.trim()) { $event.preventDefault(); close(); addTypedStore() } else enter($event)"
                            @keydown.escape="if (visible) { $event.preventDefault(); close() }">
                        <x-suggestions id="store-suggestions" />
                    </span>
                </div>
                <label class="block">
                    <span class="label" x-text="$t('Note')"></span>
                    {{-- Grows with the text (field-sizing), line breaks are kept --}}
                    <textarea x-model="editing.note" rows="1" class="field block max-h-40 w-full resize-none field-sizing-content" maxlength="255"></textarea>
                </label>
                <p x-show="editing.recipes.length" class="text-sm">
                    <span class="text-stone-500" x-text="$t('Recipes') + ': '"></span>
                    <template x-for="(recipe, index) in editing.recipes" :key="index">
                        <span>
                            <span x-show="index > 0">, </span>
                            <a x-show="recipe.url" :href="recipe.url" target="_blank" rel="noopener" class="font-medium text-violet-700 underline" x-text="recipe.name"></a>
                            <span x-show="!recipe.url" x-text="recipe.name"></span>
                        </span>
                    </template>
                </p>
                {{-- Photo of the product: shown for every item with this name --}}
                <div x-show="loggedIn" class="flex items-center gap-3">
                    <img x-show="editing.image" :src="imageUrl(editing.image, 'thumb')" @click="openImage(editing.image)" :alt="$t('Show photo')" class="size-20 shrink-0 cursor-zoom-in rounded-lg bg-stone-100 object-cover">
                    <div class="min-w-0 space-y-1 text-sm">
                        <span class="label" x-text="$t('Photo for all “:name”', { name: editing.name.trim() || '…' })"></span>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <span x-show="imageBusy" class="text-stone-500" x-text="$t('Saving …')"></span>
                            {{-- capture opens the camera directly (phones and tablets; desktops ignore it, so it is hidden there) --}}
                            <label x-show="!imageBusy" class="cursor-pointer font-medium underline pointer-fine:hidden" :class="$store.sync.online || 'opacity-50'">
                                <input type="file" accept="image/*" capture="environment" class="sr-only" @change="chooseImage($event)">
                                <span x-text="$t('Take photo')"></span>
                            </label>
                            <label x-show="!imageBusy" class="cursor-pointer font-medium underline" :class="$store.sync.online || 'opacity-50'">
                                <input type="file" accept="image/*" class="sr-only" @change="chooseImage($event)">
                                <span x-text="editing.image ? $t('Choose another photo') : $t('Choose photo')"></span>
                            </label>
                            <button type="button" x-show="editing.image && !imageBusy" class="text-red-700 underline" @click="removeImage()" x-text="$t('Remove photo')"></button>
                        </div>
                    </div>
                </div>
                <p x-show="editing.creator_email" class="text-xs text-stone-500" x-text="$t('Added by :email', { email: editing.creator_email })"></p>
                <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                    <button type="button" class="text-sm text-red-700 underline" @click="deleteItem()" x-text="$t('Delete')"></button>
                    <span class="ml-auto flex gap-2">
                        <button type="button" class="button-secondary" @click="closeEdit()" x-text="$t('Cancel')"></button>
                        <button class="button" x-text="$t('Save')"></button>
                    </span>
                </div>
            </form>
        </template>
    </dialog>

    {{-- Share list (owner only, needs a connection) --}}
    <dialog x-ref="shareDialog" class="dialog">
        <template x-if="shareList">
            <div class="space-y-4">
                <h2 class="text-lg font-semibold" x-text="$t('Share')"></h2>
                <p x-show="!$store.sync.online" class="notice" x-text="$t('This needs an internet connection.')"></p>

                <ul x-show="shareList.members?.length" class="divide-y divide-stone-200 rounded-lg border border-stone-200">
                    <template x-for="email in shareList.members" :key="email">
                        <li class="flex items-center justify-between gap-2 px-3 py-2">
                            <span class="min-w-0 truncate" x-text="email"></span>
                            <button type="button" class="text-sm text-red-700 underline" @click="removeMember(email)" :disabled="busy" x-text="$t('Remove')"></button>
                        </li>
                    </template>
                </ul>

                <form @submit.prevent="share()" class="space-y-2">
                    <label class="block">
                        <span class="label" x-text="$t('Invite by email')"></span>
                        <input x-model="shareEmail" type="email" class="field w-full" required autocomplete="email">
                    </label>
                    <p x-show="shareError" class="text-sm text-red-700" x-text="shareError"></p>
                    <div class="flex flex-wrap justify-end gap-2 pt-2">
                        <button type="button" class="button-secondary" @click="$refs.shareDialog.close()" x-text="$t('Close')"></button>
                        <button class="button" :disabled="busy" x-text="$t('Invite')"></button>
                    </div>
                </form>
            </div>
        </template>
    </dialog>

    {{-- Rename list (overview), product, unit or shop (with code) --}}
    <dialog x-ref="renameDialog" class="dialog" @close="renamingDoc = null">
        <template x-if="renamingDoc">
            <form @submit.prevent="saveRename()" class="space-y-4">
                <label class="block">
                    <span class="label" x-text="$t('Name')"></span>
                    <input x-model="renamingDoc.name" class="field w-full" required>
                </label>
                <label x-show="renamingDoc.collection === 'stores'" class="block">
                    <span class="label" x-text="$t('Code')"></span>
                    <input x-model="renamingDoc.code" class="field w-full" maxlength="10" :placeholder="$t('Optional, otherwise the name is shown')">
                </label>
                {{-- Own image shown on the items instead of the code --}}
                <div x-show="renamingDoc.collection === 'stores'" class="flex items-center gap-3">
                    <img x-show="renamingDoc.icon" :src="renamingDoc.icon" alt="" class="size-10 shrink-0 rounded bg-white object-contain">
                    <label class="button-secondary cursor-pointer">
                        <span x-text="renamingDoc.icon ? $t('Change image') : $t('Add image')"></span>
                        <input type="file" accept="image/*" class="sr-only" @change="pickStoreIcon($event)">
                    </label>
                    <button type="button" x-show="renamingDoc.icon" class="text-sm text-stone-600 underline" @click="renamingDoc.icon = null" x-text="$t('Remove image')"></button>
                </div>
                <div class="flex flex-wrap justify-end gap-2 pt-2">
                    <button type="button" class="button-secondary" @click="$refs.renameDialog.close()" x-text="$t('Cancel')"></button>
                    <button class="button" x-text="$t('Save')"></button>
                </div>
            </form>
        </template>
    </dialog>

    {{-- Move an item to another list --}}
    <dialog x-ref="moveDialog" class="dialog" @close="moving = null">
        <template x-if="moving">
            <div class="space-y-4">
                <h2 class="font-semibold" x-text="$t('Move “:name” to', { name: moving.name })"></h2>
                <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200">
                    <template x-for="target in lists.filter((list) => list.id !== moving.shopping_list_id)" :key="target.id">
                        <li><button type="button" class="block w-full truncate px-4 py-3 text-left hover:bg-stone-50" @click="moveItem(target.id)" x-text="target.name"></button></li>
                    </template>
                </ul>
                <div class="flex justify-end">
                    <button type="button" class="button-secondary" @click="$refs.moveDialog.close()" x-text="$t('Cancel')"></button>
                </div>
            </div>
        </template>
    </dialog>

    {{-- Product photo in full view (resources/js/lightbox.js) --}}
    <dialog x-ref="lightbox" class="m-0 h-dvh max-h-none w-screen max-w-none overflow-hidden bg-transparent p-0 backdrop:bg-black/90"
        @close="$store.lightbox.closed()" :aria-label="$t('Photo')">
        <template x-if="$store.lightbox.image">
            <div class="relative size-full touch-none select-none"
                @pointerdown="$store.lightbox.down($event)" @pointermove="$store.lightbox.move($event)"
                @pointerup="$store.lightbox.up($event)" @pointercancel="$store.lightbox.up($event)" @wheel.prevent="$store.lightbox.wheel($event)">
                <div class="absolute inset-0 grid p-2 pt-[max(0.5rem,env(safe-area-inset-top))] pb-[max(0.5rem,env(safe-area-inset-bottom))]"
                    :class="$store.lightbox.gesture || 'transition-transform duration-150 motion-reduce:transition-none'"
                    :style="`transform: ${$store.lightbox.transform}`">
                    {{-- Thumbnail at once (and offline), the large image on top as soon as it is loaded --}}
                    <img :src="imageUrl($store.lightbox.image, 'thumb')" alt="" draggable="false" class="col-start-1 row-start-1 size-full object-contain">
                    <img :src="imageUrl($store.lightbox.image)" alt="" draggable="false" x-show="$store.lightbox.loaded" @load="$store.lightbox.loaded = true"
                        class="col-start-1 row-start-1 size-full object-contain">
                </div>
            </div>
        </template>
        <button type="button" class="absolute top-[max(0.75rem,env(safe-area-inset-top))] right-3 grid size-11 place-items-center rounded-full bg-black/50 text-white"
            @click="$store.lightbox.close()" :aria-label="$t('Close')">
            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </dialog>

    {{-- "…" menu of a row (desktop) --}}
    <div x-ref="menu" x-show="menu" x-transition.opacity.duration.100ms role="menu" :style="menu?.style"
        class="fixed z-30 w-52 overflow-hidden rounded-lg border border-stone-200 bg-white py-1 shadow-lg"
        @click.outside="menu = null" @keydown.escape.window="menu = null" @scroll.window="menu = null" @resize.window="menu = null">
        <template x-for="action in menu?.actions ?? []" :key="action.label">
            <button type="button" role="menuitem" class="menu-item" :class="[action.danger && 'text-red-700', action.online && !$store.sync.online && 'opacity-50']" @click="runMenu(action)" x-text="action.label"></button>
        </template>
    </div>

    {{-- New version available --}}
    <div x-show="$store.update.apply && !$store.undo.toast" x-transition role="status" aria-live="polite"
        class="fixed inset-x-3 top-[calc(4rem+env(safe-area-inset-top))] z-40 mx-auto flex max-w-xl items-center gap-3 rounded-lg bg-emerald-800 px-4 py-3 text-sm text-white shadow-lg">
        <span class="min-w-0 flex-1" x-text="$t('A new version is available.')"></span>
        <button type="button" class="shrink-0 font-semibold underline" @click="$store.update.reload()" x-text="$t('Reload')"></button>
    </div>

    {{-- Short notices (e.g. offline) --}}
    <div x-show="$store.notice.text" x-transition role="alert"
        class="fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-50 mx-auto max-w-xl rounded-lg bg-stone-900 px-4 py-3 text-sm text-white shadow-lg"
        x-text="$store.notice.text" @click="$store.notice.text = null"></div>

    {{-- Undo deletion --}}
    <div x-show="$store.undo.toast" x-transition role="status" aria-live="polite"
        class="fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-40 mx-auto flex max-w-xl items-center gap-3 rounded-lg bg-stone-900 px-4 py-3 text-sm text-white shadow-lg">
        <span class="min-w-0 flex-1 truncate" x-text="$store.undo.toast?.message"></span>
        <button type="button" class="shrink-0 font-semibold text-emerald-300 uppercase" @click="$store.undo.undo()" x-text="$t('Undo')"></button>
    </div>

</body>
</html>
