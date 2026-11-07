{{-- Settings and install instructions (part of the app shell, available offline). --}}
<section x-show="route.view === 'settings'" class="space-y-6">
    <div>
        <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Account')"></h2>
        <div class="rounded-lg border border-stone-200 bg-white p-4">
            <template x-if="loggedIn">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span class="min-w-0 break-all" x-text="$t('Logged in as :email', { email: $store.sync.user.email })"></span>
                    <button type="button" class="button-secondary shrink-0 whitespace-nowrap" @click="logout()" :disabled="busy" x-text="$t('Log Out')"></button>
                    <a href="/account" data-online class="w-full text-sm font-medium underline" x-text="$store.sync.user.linked ? $t('Linked sign-in') : $t('Password and passkeys')"></a>
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

    <div class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
        {{-- Households page is server rendered (needs a connection) --}}
        <a x-show="loggedIn" href="/households" data-online class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
            <span>
                <span class="block">
                    <span x-text="`🏠 ${$t('Households')}`"></span>
                    <span x-show="$store.sync.user?.household_invitations" class="ml-1 rounded-full bg-emerald-700 px-2 py-0.5 text-xs text-white"
                        x-text="$store.sync.user?.household_invitations === 1 ? $t('1 invitation') : $t(':count invitations', { count: $store.sync.user?.household_invitations })"></span>
                </span>
                <span class="block text-sm text-stone-500" x-text="households.length
                    ? households.map((household) => household.name).join(', ')
                    : $t('Share lists with your family or flat in one go')"></span>
            </span>
            <span aria-hidden="true">›</span>
        </a>
        {{-- Products, units and shops suggested when adding items --}}
        <a href="#/products" class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
            <span>
                <span class="block" x-text="`🥕 ${$t('Products')}`"></span>
                <span class="block text-sm text-stone-500" x-text="$t('Own products, photos, import and export')"></span>
            </span>
            <span aria-hidden="true">›</span>
        </a>
        <a href="#/units" class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
            <span>
                <span class="block" x-text="`⚖️ ${$t('Units')}`"></span>
                <span class="block text-sm text-stone-500" x-text="$t('Suggested after the amount')"></span>
            </span>
            <span aria-hidden="true">›</span>
        </a>
        <a href="#/stores" class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
            <span>
                <span class="block" x-text="`🏪 ${$t('Shops')}`"></span>
                <span class="block text-sm text-stone-500" x-text="$t('Own shops and which standard shops are offered')"></span>
            </span>
            <span aria-hidden="true">›</span>
        </a>
        <a href="#/install" class="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
            <span>
                <span class="block" x-text="`📲 ${$t('Install app')}`"></span>
                <span class="block text-sm text-stone-500" x-text="$store.install.installed ? $t('The app is installed on this device.') : $t('How to add :app to your home screen or dock')"></span>
            </span>
            <span aria-hidden="true">›</span>
        </a>
    </div>

    <div class="grid gap-4 min-[480px]:grid-cols-2">
        <label class="block">
            <span class="mb-2 block text-sm font-medium text-stone-600" x-text="$t('Language')"></span>
            <select class="field w-full" @change="setLocale($event.target.value)">
                <template x-for="(label, code) in $store.i18n.locales" :key="code">
                    <option :value="code" x-text="label" :selected="code === $store.i18n.locale"></option>
                </template>
            </select>
        </label>
        {{-- Country of the standard shops offered in the shop choice --}}
        <label class="block">
            <span class="mb-2 block text-sm font-medium text-stone-600" x-text="$t('Country of the shops')"></span>
            <select class="field w-full" @change="$store.prefs.set('country', $event.target.value)">
                <template x-for="option in [{ value: 'CH', label: $t('Switzerland') }, { value: 'DE', label: $t('Germany') }, { value: 'AT', label: $t('Austria') }]" :key="option.value">
                    <option :value="option.value" x-text="option.label" :selected="option.value === country"></option>
                </template>
            </select>
        </label>
        {{-- Region of shops with regional offers (Migros cooperatives): offers of other regions are hidden --}}
        <template x-for="(regions, tag) in country === 'CH' ? shopRegions : {}" :key="tag">
            <label class="block">
                <span class="mb-2 block text-sm font-medium text-stone-600" x-text="$t('Offers of :shop', { shop: storeLabel(tag) })"></span>
                <select class="field w-full" @change="setShopRegion(tag, $event.target.value)">
                    <option value="" x-text="$t('All regions')" :selected="!$store.prefs.regions?.[tag]"></option>
                    <template x-for="(name, key) in regions" :key="key">
                        <option :value="key" x-text="name" :selected="$store.prefs.regions?.[tag] === key"></option>
                    </template>
                </select>
            </label>
        </template>
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

    {{-- AI configured, but not unlocked for this account (Entitlements, shoppinglist.ai) --}}
    <p x-show="loggedIn && $store.sync.user?.assistant_locked" class="notice" x-text="$t('The AI features are not enabled for your account.')"></p>

    {{-- Connection to the recipe app (week planner); connecting is a server page --}}
    <template x-if="recipeApp && loggedIn">
        <div>
            <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="`📖 ${recipeApp.name}`"></h2>
            <div class="space-y-3 rounded-lg border border-stone-200 bg-white p-4 text-sm">
                <template x-if="recipeApp.connected">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span class="min-w-0 break-all" x-text="$t('Connected as :account', { account: recipeApp.account?.email ?? recipeApp.account?.name ?? '' })"></span>
                        <button type="button" class="button-secondary shrink-0" :disabled="busy" @click="disconnectRecipes()" x-text="$t('Disconnect')"></button>
                    </div>
                </template>
                <template x-if="!recipeApp.connected">
                    <div class="space-y-3">
                        <p class="text-stone-600" x-text="$t('Connect your account so the week planner suggests your recipes and can save new ones.')"></p>
                        <a href="/recipes/connect" data-online class="button inline-block" x-text="$t('Connect with :name', { name: recipeApp.name })"></a>
                    </div>
                </template>
            </div>
        </div>
    </template>
</section>

{{-- Install instructions (offline available, part of the app shell) --}}
<section x-show="route.view === 'install'" class="space-y-6">
    <p class="text-stone-600" x-text="$t('Installed, :app starts like an app from the home screen, in full screen and without a connection.')"></p>

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
