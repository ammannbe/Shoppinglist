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
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    App shell: cached by the service worker and used offline. It must not contain user data
    or server side translations, the UI translates itself ($t) and reads the local database.
--}}
<body class="min-h-dvh bg-stone-50 text-stone-900 antialiased" x-data="shoppinglist" x-cloak>
    <header class="sticky top-0 z-10 border-b border-stone-200 bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur">
        <div class="mx-auto flex h-14 max-w-xl items-center gap-2 px-3">
            <a href="#/" x-show="route.view !== 'lists'" class="icon-button" :aria-label="$t('Back')">
                <svg viewBox="0 0 24 24" class="size-6"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <h1 class="min-w-0 flex-1 truncate text-lg font-semibold">
                <span x-show="route.view === 'lists'" class="block truncate" x-text="$t('Shopping lists')"></span>
                <span x-show="route.view === 'settings'" class="block truncate" x-text="$t('Settings')"></span>
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
                        'bg-red-500': ['error', 'expired', 'unverified'].includes($store.sync.status),
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
                }[$store.sync.status]"></span>
            </span>

            <a href="#/settings" x-show="route.view === 'lists'" class="icon-button" :aria-label="$t('Settings')">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10.3 4.3c.4-1.7 3-1.7 3.4 0a1.7 1.7 0 0 0 2.6 1.1c1.5-.9 3.3.8 2.4 2.4a1.7 1.7 0 0 0 1 2.5c1.8.4 1.8 3 0 3.4a1.7 1.7 0 0 0-1 2.6c.9 1.5-.9 3.3-2.4 2.4a1.7 1.7 0 0 0-2.6 1c-.4 1.8-3 1.8-3.4 0a1.7 1.7 0 0 0-2.5-1c-1.6.9-3.3-.9-2.4-2.4a1.7 1.7 0 0 0-1.1-2.6c-1.7-.4-1.7-3 0-3.4a1.7 1.7 0 0 0 1.1-2.5c-.9-1.6.8-3.3 2.4-2.4a1.7 1.7 0 0 0 2.5-1.1z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>

            {{-- List menu --}}
            <template x-if="route.view === 'list' && list">
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" class="icon-button" @click="open = !open" :aria-label="$t('Menu')">
                        <svg viewBox="0 0 24 24" class="size-6" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                    </button>
                    <div x-show="open" x-transition @click="open = false" class="absolute right-0 mt-1 w-52 overflow-hidden rounded-lg border border-stone-200 bg-white py-1 shadow-lg">
                        <button type="button" class="menu-item" @click="startRename()" x-text="$t('Rename')"></button>
                        <button type="button" class="menu-item" x-show="loggedIn && list.is_owner" @click="openShare()" x-text="$t('Share')"></button>
                        <button type="button" class="menu-item" x-show="doneItems.length" @click="clearDone()" x-text="$t('Clear done')"></button>
                        <button type="button" class="menu-item text-red-700" x-show="loggedIn && !list.is_owner" @click="leaveList()" x-text="$t('Leave list')"></button>
                        <button type="button" class="menu-item text-red-700" x-show="list.is_owner" @click="deleteList()" x-text="$t('Delete list')"></button>
                    </div>
                </div>
            </template>
        </div>
    </header>

    {{-- Account hints --}}
    <div class="mx-auto max-w-xl px-3">
        <p x-show="$store.sync.status === 'local'" class="notice mt-3">
            <span x-text="$t('Log in to sync your lists across devices and share them.')"></span>
            <a href="/login" class="font-medium underline" x-text="$t('Log in')"></a> ·
            <a href="/register" class="font-medium underline" x-text="$t('Register')"></a>
        </p>
        <p x-show="$store.sync.status === 'expired'" class="notice mt-3">
            <span x-text="$t('Your session has expired. Please log in again.')"></span>
            <a href="/login" class="font-medium underline" x-text="$t('Log in')"></a>
        </p>
        <p x-show="$store.sync.status === 'unverified'" class="notice mt-3">
            <span x-text="$t('Please verify your email address to sync.')"></span>
            <a href="/email/verify" class="font-medium underline" x-text="$t('Resend Verification Email')"></a>
        </p>
    </div>

    <main class="mx-auto max-w-xl px-3 pt-4 pb-[calc(2rem+env(safe-area-inset-bottom))]">
        {{-- Lists --}}
        <section x-show="route.view === 'lists'">
            <form @submit.prevent="createList()" class="mb-4 flex gap-2">
                <input x-model="newListName" class="field min-w-0 flex-1" :placeholder="$t('New list')" :aria-label="$t('New list')" enterkeyhint="done">
                <button class="button shrink-0 px-3 min-[400px]:px-4" :aria-label="$t('Add')">
                    <span class="hidden min-[400px]:inline" x-text="$t('Add')"></span>
                    <svg viewBox="0 0 24 24" class="size-6 min-[400px]:hidden"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                </button>
            </form>

            <p x-show="!lists.length" class="py-8 text-center text-stone-500" x-text="$t('No lists yet.')"></p>

            <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                <template x-for="item in lists" :key="item.id">
                    <li>
                        <a :href="`#/list/${item.id}`" class="flex items-center gap-3 px-4 py-3 hover:bg-stone-50">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium" x-text="item.name"></span>
                                <span x-show="item.is_owner === false" class="block truncate text-xs text-stone-500" x-text="$t('Shared by :email', { email: item.owner_email })"></span>
                                <span x-show="item.is_owner !== false && item.members?.length" class="block truncate text-xs text-stone-500" x-text="$t('Shared with :emails', { emails: item.members?.join(', ') })"></span>
                            </span>
                            <span class="rounded-full bg-stone-100 px-2 py-0.5 text-sm tabular-nums text-stone-600" x-text="openCount(item.id)"></span>
                        </a>
                    </li>
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
                        <input x-model="newItemText" list="product-names" class="field min-w-0 flex-1" autocomplete="off" enterkeyhint="enter"
                            :placeholder="$t('Add item, e.g. “2 l milk”')" :aria-label="$t('Add item')">
                        <button class="button shrink-0 px-3 min-[400px]:px-4" :aria-label="$t('Add')">
                    <span class="hidden min-[400px]:inline" x-text="$t('Add')"></span>
                    <svg viewBox="0 0 24 24" class="size-6 min-[400px]:hidden"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                </button>
                    </form>

                    <p x-show="!openItems.length" class="py-6 text-center text-stone-500" x-text="$t('Nothing to buy.')"></p>

                    <ul class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white" x-show="openItems.length">
                        <template x-for="item in openItems" :key="item.id">
                            <li class="flex items-center">
                                <button type="button" class="check" @click="toggle(item)" :aria-label="$t('Done')"></button>
                                <button type="button" class="flex min-w-0 flex-1 items-baseline gap-2 py-3 pr-4 text-left" @click="edit(item)">
                                    <span class="truncate" x-text="item.name"></span>
                                    <span class="shrink-0 text-sm text-stone-500" x-text="amount(item)"></span>
                                </button>
                            </li>
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
                                <li class="flex items-center text-stone-400">
                                    <button type="button" class="check check-done" @click="toggle(item)" :aria-label="$t('Not done')">
                                        <svg viewBox="0 0 24 24" class="size-4"><path d="M5 12l5 5 9-10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </button>
                                    <button type="button" class="flex min-w-0 flex-1 items-baseline gap-2 py-3 pr-4 text-left line-through" @click="edit(item)">
                                        <span class="truncate" x-text="item.name"></span>
                                        <span class="shrink-0 text-sm" x-text="amount(item)"></span>
                                    </button>
                                </li>
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

            <div>
                <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Account')"></h2>
                <div class="rounded-lg border border-stone-200 bg-white p-4">
                    <template x-if="loggedIn">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="min-w-0 break-all" x-text="$t('Logged in as :email', { email: $store.sync.user.email })"></span>
                            <button type="button" class="button-secondary shrink-0 whitespace-nowrap" @click="logout()" :disabled="busy" x-text="$t('Log Out')"></button>
                        </div>
                    </template>
                    <template x-if="!loggedIn">
                        <p>
                            <span x-text="$t('Log in to sync your lists across devices and share them.')"></span>
                            <a href="/login" class="font-medium underline" x-text="$t('Log in')"></a> ·
                            <a href="/register" class="font-medium underline" x-text="$t('Register')"></a>
                        </p>
                    </template>
                </div>
            </div>

            <div x-show="isIos">
                <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Install app')"></h2>
                <p class="rounded-lg border border-stone-200 bg-white p-4" x-text="$t('On iPhone and iPad: open this page in Safari, tap “Share” and then “Add to Home Screen”.')"></p>
            </div>
        </section>
    </main>

    {{-- iOS install hint (Safari has no install prompt) --}}
    <div x-show="isIos && !installHintDismissed && route.view === 'lists'" class="fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] mx-auto max-w-xl rounded-lg bg-stone-900 p-4 text-sm text-white shadow-lg">
        <p x-text="$t('On iPhone and iPad: open this page in Safari, tap “Share” and then “Add to Home Screen”.')"></p>
        <button type="button" class="mt-2 font-medium underline" @click="dismissInstallHint()" x-text="$t('Got it')"></button>
    </div>

    {{-- Edit item --}}
    <dialog x-ref="editDialog" class="dialog" @close="editing = null">
        <template x-if="editing">
            <form @submit.prevent="saveItem()" class="space-y-4">
                <label class="block">
                    <span class="label" x-text="$t('Name')"></span>
                    <input x-model="editing.name" list="product-names" class="field w-full" required>
                </label>
                <div class="flex gap-3">
                    <label class="block min-w-0 w-1/2">
                        <span class="label" x-text="$t('Amount')"></span>
                        <input x-model="editing.amount" inputmode="decimal" class="field w-full">
                    </label>
                    <label class="block min-w-0 w-1/2">
                        <span class="label" x-text="$t('Unit')"></span>
                        <input x-model="editing.unit" list="unit-names" class="field w-full">
                    </label>
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
        <template x-if="list">
            <div class="space-y-4">
                <h2 class="text-lg font-semibold" x-text="$t('Share')"></h2>
                <p x-show="!$store.sync.online" class="notice" x-text="$t('This needs an internet connection.')"></p>

                <ul x-show="list.members?.length" class="divide-y divide-stone-200 rounded-lg border border-stone-200">
                    <template x-for="email in list.members" :key="email">
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

    <datalist id="product-names">
        <template x-for="name in productNames" :key="name"><option :value="name"></option></template>
    </datalist>
    <datalist id="unit-names">
        <template x-for="name in unitNames" :key="name"><option :value="name"></option></template>
    </datalist>
</body>
</html>
