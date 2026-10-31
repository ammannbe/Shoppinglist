import Alpine from 'alpinejs';
import { addRxPlugin } from 'rxdb/plugins/core';
import { getRxStorageDexie } from 'rxdb/plugins/storage-dexie';
import { createApi, HttpError } from './api.js';
import { createDatabase, removeDatabase, startReplication } from './db/index.js';
import { byPosition, compareBy, csvFirstColumn, csvValue, formatAmount, mergedAmount, nextPosition, normalizeName, parseItemInput, sameName, uuid7 } from './helpers.js';
import { hasPendingLocale, htmlLang, initialLocale, LOCALES, storeLocale, translate } from './i18n.js';
import { registerServiceWorker } from './pwa.js';
import { shrinkIcon, shrinkImage } from './image.js';
import { closeLayersOnBack } from './layers.js';
import { createLightboxStore } from './lightbox.js';
import { createPrefsStore } from './prefs.js';
import { offersPool } from './offers.js';
import { planner } from './planner.js';
import { recipeHistory } from './recipe-history.js';
import { deviceCountry, inCountry, storeIconUrl, storeUsage } from './stores.js';
import { suggestField } from './suggest.js';
import { swipeRow } from './swipe.js';
import { createUndoStore } from './undo.js';
import { createInstallStore } from './install.js';

const RESYNC_INTERVAL = 30_000;
const DEFAULT_UNITS = ['Stk.', 'g', 'kg', 'ml', 'dl', 'l', 'Pck.', 'Bund', 'Fl.'];
// Standard shops for guests and the first start, the public ones arrive with the sync.
const DEFAULT_STORES = [['Migros', 'M', ['CH']], ['Coop', 'C', ['CH']], ['Aldi', 'A', []], ['Lidl', 'L', []], ['Denner', 'D', ['CH']]]
    .map(([name, code, countries]) => ({ id: `default:${code}`, name, code, icon: name.toLowerCase(), countries, is_public: true }));
const OWNER_KEY = 'shoppinglist.owner';
// Shop filter per list (device only): { listId: [tags] }.
const STORE_FILTER_KEY = 'shoppinglist.store-filter';
const USER_KEY = 'shoppinglist.user';

const api = createApi();
let db = null;
let replication = null;

// ---------------------------------------------------------------- stores

Alpine.store('i18n', { locale: initialLocale(), locales: LOCALES });
// status: starting | local (not logged in) | unverified | expired | offline | syncing | synced | error | broken (local database)
Alpine.store('sync', {
    status: 'starting',
    user: null,
    online: navigator.onLine,

    /**
     * The local database could not be opened (e.g. schema changed without migration):
     * start over with an empty one, synced data comes back from the server.
     */
    async resetLocalData() {
        await removeDatabase({ storage: getRxStorageDexie() });
        location.reload();
    },
});
Alpine.store('data', { lists: [], items: [], products: [], units: [], stores: [] });
Alpine.store('undo', createUndoStore());
Alpine.store('prefs', createPrefsStore(api));
Alpine.store('lightbox', createLightboxStore());
Alpine.store('install', createInstallStore());
// New app version ready (service worker waiting).
Alpine.store('update', {
    apply: null,
    async reload() {
        // Deletions waiting for undo must not get lost.
        Alpine.store('undo').flush();
        await this.apply?.();
    },
});

// Short notice at the bottom, hides itself.
Alpine.store('notice', {
    text: null,
    timer: null,
    show(text) {
        clearTimeout(this.timer);
        this.text = text;
        this.timer = setTimeout(() => { this.text = null; }, 4000);
    },
});

Alpine.magic('t', () => (key, replace) => translate(Alpine.store('i18n').locale, key, replace));

document.documentElement.lang = htmlLang(Alpine.store('i18n').locale);

async function setLocale(locale) {
    Alpine.store('i18n').locale = locale;
    document.documentElement.lang = htmlLang(locale);
    try {
        await api.put('/locale', { locale });
        storeLocale(locale, { pending: false });
    } catch {
        // Offline: sent again with the next start.
        storeLocale(locale, { pending: true });
    }
}

// ---------------------------------------------------------------- UI component

Alpine.data('swipeRow', swipeRow);
Alpine.data('suggestField', suggestField);
Alpine.data('planner', planner);
Alpine.data('offersPool', offersPool);
Alpine.data('recipeHistory', recipeHistory);

// The products/units/shops view renders this many rows at a time ("Show more" adds as many).
const CATALOG_PAGE = 200;

const byName = (a, b) => a.name.localeCompare(b.name, Alpine.store('i18n').locale.replace('_', '-'));
const byId = (a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0);

// Sort options of the lists overview (per user) and of the items of a list (per list).
const SORTS = ['manual', 'name_asc', 'name_desc', 'newest', 'oldest'];

Alpine.data('shoppinglist', () => ({
    route: { view: 'lists', id: null },
    newListName: '',
    newItemText: '',
    // Shop (tag) for new items, chosen before typing; kept until changed.
    newItemStore: '',
    newProductName: '',
    showDone: false,
    editing: null,
    imageBusy: false,
    // New entry of a product that is already open on the list: { existing, parsed, merged }
    duplicate: null,
    // Item being moved to another list (dialog).
    moving: null,
    catalogSearch: '',
    catalogLimit: CATALOG_PAGE,
    catalogLoading: false,
    renaming: null,
    renamingDoc: null,
    newStore: { name: '', code: '' },
    storeFilters: JSON.parse(localStorage.getItem(STORE_FILTER_KEY) ?? '{}'),
    shareListId: null,
    shareEmail: '',
    shareError: null,
    busy: false,
    menu: null,
    // Where the back button of the settings leads (the list they were opened from).
    settingsBack: '#/',
    // Hashes visited in this tab, to make "back" go up in the structure (goBack).
    navStack: [],
    navReplacing: false,

    init() {
        const layers = closeLayersOnBack({ menuOpen: () => this.menu !== null, closeMenu: () => { this.menu = null; } });
        this.$watch('menu', (menu) => (menu ? layers.opened() : layers.closed()));
        const parse = (event) => {
            this.trackNavigation(Boolean(event));
            const [, view, id = null] = location.hash.split('/');
            if (view === 'settings' && event) {
                const from = new URL(event.oldURL).hash;
                if (from.startsWith('#/list/')) this.settingsBack = from;
                else if (!/^#\/(products|units|stores|install)/.test(from)) this.settingsBack = '#/';
            }
            // Any other path means the service worker served the app shell offline.
            this.route = location.pathname === '/' ? { view: view || 'lists', id } : { view: 'offline', id: null };
            this.renaming = null;
            this.menu = null;
            this.duplicate = null;
            this.catalogSearch = '';
            this.catalogLimit = CATALOG_PAGE;
        };
        window.addEventListener('hashchange', parse);
        parse();
    },

    // ---- data helpers

    get lists() {
        return Alpine.store('data').lists
            .filter((list) => !Alpine.store('undo').hidden(list.id))
            .sort(compareBy(Alpine.store('prefs').list_sort, byName, byName));
    },
    get list() {
        return this.lists.find((list) => list.id === this.route.id) ?? null;
    },
    get shareList() {
        return this.lists.find((list) => list.id === this.shareListId) ?? null;
    },
    itemsOf(listId) {
        const sort = Alpine.store('data').lists.find((list) => list.id === listId)?.item_sort ?? 'manual';

        return Alpine.store('data').items
            .filter((item) => item.shopping_list_id === listId && !Alpine.store('undo').hidden(item.id))
            .sort(compareBy(sort, byName, byId));
    },
    openCount(listId) {
        return this.itemsOf(listId).filter((item) => !item.done).length;
    },
    get openItems() {
        return this.itemsOf(this.route.id).filter((item) => !item.done);
    },
    get doneItems() {
        return this.itemsOf(this.route.id).filter((item) => item.done);
    },
    get unitNames() {
        const units = Alpine.store('data').units.filter((unit) => !Alpine.store('undo').hidden(unit.id));
        // Defaults for guests and the first start, the public units arrive with the sync.
        const defaults = units.some((unit) => unit.is_public) ? [] : DEFAULT_UNITS;

        return [...new Set([...defaults, ...units.map((unit) => unit.name)])];
    },

    /**
     * Shops (standard ones first). Items keep their tags: the code, or the name if there is none.
     */
    get stores() {
        const stores = Alpine.store('data').stores.filter((store) => !Alpine.store('undo').hidden(store.id));

        return [...(stores.some((store) => store.is_public) ? [] : DEFAULT_STORES), ...stores]
            .sort((a, b) => Number(b.is_public) - Number(a.is_public) || byName(a, b));
    },
    /** Country of the shop choice: the user's setting, otherwise the device's. */
    get country() {
        return Alpine.store('prefs').country || deviceCountry();
    },
    /** Shops offered in the edit dialog: own ones and the standard shops of the country the user has not hidden. */
    get choosableStores() {
        return this.stores.filter((store) => !store.is_public || (inCountry(store, this.country) && !this.storeHidden(store)));
    },
    /** Shops for new items: the choosable ones, most used first. */
    get quickStores() {
        const usage = storeUsage(Alpine.store('data').items);
        const score = (store) => usage.get(normalizeName(this.storeTag(store))) ?? 0;

        return [...this.choosableStores].sort((a, b) => score(b) - score(a) || byName(a, b));
    },
    storeTag(store) {
        return store.code || store.name;
    },
    /** Shop of a tag on an item: by code, otherwise by name (also a shop that got a code later). */
    storeByTag(tag) {
        if (!tag) return null;

        return this.stores.find((store) => store.code && sameName(store.code, tag))
            ?? this.stores.find((store) => sameName(store.name, tag)) ?? null;
    },
    // Hidden standard shops are kept by tag (older settings: the code, which is the tag).
    storeHidden(store) {
        return (Alpine.store('prefs').hidden_stores ?? []).includes(this.storeTag(store));
    },
    toggleStoreHidden(store) {
        const hidden = Alpine.store('prefs').hidden_stores ?? [];
        const tag = this.storeTag(store);
        Alpine.store('prefs').set('hidden_stores', hidden.includes(tag) ? hidden.filter((each) => each !== tag) : [...hidden, tag]);
    },
    /** Icon URL of a shop tag on an item, null if the shop has none (the badge shows the tag). */
    storeIcon(tag) {
        return storeIconUrl(this.storeByTag(tag));
    },
    storeIconUrl(store) {
        return storeIconUrl(store);
    },
    /** Full name of a shop tag (tooltip of the badge), the tag itself if unknown. */
    storeLabel(tag) {
        return this.storeByTag(tag)?.name ?? tag ?? '';
    },
    /** Shops with regional offers (tag => region key => name, from /me) for the settings. */
    get shopRegions() {
        return Alpine.store('sync').user?.regions ?? {};
    },
    /** The user's region of a shop (empty: offers of all regions). */
    setShopRegion(tag, region) {
        Alpine.store('prefs').set('regions', { ...Alpine.store('prefs').regions, [tag]: region || null });
    },

    /**
     * Products, units or shops view (route.view), filtered by the search: own entries can be
     * renamed and deleted, public ones are read-only.
     */
    get catalog() {
        const collection = this.route.view;
        if (!['products', 'units', 'stores'].includes(collection)) return [];
        const search = normalizeName(this.catalogSearch);
        let entries = collection === 'stores'
            // Standard shops of other countries are not listed (country in the settings).
            ? this.stores.filter((store) => !store.is_public || inCountry(store, this.country))
            : Alpine.store('data')[collection].filter((entry) => !Alpine.store('undo').hidden(entry.id));
        if (collection === 'products') entries = withOwnImages(entries);
        if (collection === 'units' && !entries.some((entry) => entry.is_public)) {
            entries = [...DEFAULT_UNITS.map((name) => ({ id: `default:${name}`, name, is_public: true })), ...entries];
        }

        return entries
            .filter((entry) => !search || normalizeName(entry.name).includes(search) || normalizeName(entry.code).startsWith(search))
            .sort((a, b) => Number(a.is_public) - Number(b.is_public) || byName(a, b));
    },
    /**
     * Renders the next rows; the spinner is painted first, rendering 200 rows blocks for a moment.
     */
    showMoreCatalog() {
        if (this.catalogLoading) return;
        this.catalogLoading = true;
        requestAnimationFrame(() => setTimeout(() => {
            this.catalogLimit += CATALOG_PAGE;
            this.$nextTick(() => { this.catalogLoading = false; });
        }));
    },
    get shownCatalog() {
        return this.catalog.slice(0, this.catalogLimit);
    },
    /**
     * Week planner with AI: configured on the server, needs a verified account.
     */
    get assistantEnabled() {
        return Boolean(Alpine.store('sync').user?.assistant && Alpine.store('sync').user?.verified);
    },

    get recipeApp() {
        return Alpine.store('sync').user?.recipes ?? null;
    },

    async disconnectRecipes() {
        if (!confirm(this.$t('Disconnect from :name?', { name: this.recipeApp.name }))) return;
        await this.online(async () => {
            await api.delete('/recipes/connection');
            Alpine.store('sync').user = await api.get('/me');
        });
    },

    /**
     * Items from the planner: into an existing list or a new one.
     *
     * @returns {Promise<string>} list id
     */
    /**
     * Open items of a list the planned items could be merged into (same product and unit), by planned item index.
     *
     * @returns {Map<number, object>}
     */
    plannedMerges(listId, items) {
        const open = listId ? this.itemsOf(listId).filter((item) => !item.done) : [];
        const merges = new Map();
        items.forEach((item, index) => {
            const existing = open.find((each) => sameName(each.name, item.name) && mergedAmount(each, item) !== null);
            if (existing) merges.set(index, existing);
        });

        return merges;
    },

    /**
     * Adds the planned items to a list (new one without target). With `merge`, items already open
     * on the list with the same unit get the amount added and the recipes and shops of both.
     */
    async addPlannedItems(target, name, items, merge = false) {
        let listId = target;
        if (!listId) {
            listId = uuid7();
            await db.lists.insert({
                id: listId, name, owner_email: Alpine.store('sync').user?.email ?? null, is_owner: true, members: [], position: nextPosition(this.lists),
            });
        }
        const merges = merge ? this.plannedMerges(target, items) : new Map();
        let position = nextPosition(this.itemsOf(listId)) ?? 0;
        for (const [index, item] of items.entries()) {
            const recipes = (item.recipes ?? []).map(({ name, url }) => ({ name, url: url ?? null }));
            const existing = merges.get(index);
            if (existing) {
                const known = existing.recipes ?? [];
                const note = [existing.note, item.note].filter(Boolean);
                await (await db.items.findOne(existing.id).exec())?.incrementalPatch({
                    amount: item.amount == null && existing.amount == null ? null : mergedAmount(existing, item),
                    unit: existing.unit ?? item.unit ?? null,
                    stores: [...new Set([...(existing.stores ?? []), ...(item.stores ?? [])])],
                    recipes: [...known.map(({ name, url }) => ({ name, url: url ?? null })), ...recipes.filter((recipe) => !known.some((each) => sameName(each.name, recipe.name)))],
                    note: [...new Set(note)].join('\n').slice(0, 255) || null,
                });
                continue;
            }
            await db.items.insert({
                id: uuid7(),
                shopping_list_id: listId,
                name: item.name,
                amount: item.amount ?? null,
                unit: item.unit ?? null,
                stores: [...(item.stores ?? [])],
                note: item.note ?? null,
                recipes,
                image: ownProduct(item.name)?.image ?? null,
                position: position++,
                creator_email: Alpine.store('sync').user?.email ?? null,
            });
            await remember('products', item.name);
        }

        return listId;
    },

    get loggedIn() {
        return Alpine.store('sync').user !== null;
    },
    amount(item) {
        return [formatAmount(item.amount, Alpine.store('i18n').locale), item.unit].filter(Boolean).join(' ');
    },

    // ---- context menu (hover "…" on desktop) and swipe actions share these definitions

    listActions(list) {
        return [
            { label: this.$t('Rename'), icon: 'edit', run: () => this.openRename(list, 'lists') },
            ...(this.loggedIn && list.is_owner !== false ? [{ label: this.$t('Share'), icon: 'share', online: true, run: () => this.openShare(list.id) }] : []),
            list.is_owner === false
                ? { label: this.$t('Leave list'), icon: 'leave', danger: true, online: true, run: () => this.leaveList(list.id) }
                : { label: this.$t('Delete'), icon: 'delete', danger: true, run: () => this.deleteList(list.id) },
        ];
    },

    itemActions(item) {
        return [
            { label: this.$t('Edit'), icon: 'edit', run: () => this.edit(item) },
            ...(this.lists.length > 1 ? [{ label: this.$t('Move to list'), icon: 'move', run: () => this.openMove(item) }] : []),
            { label: this.$t('Delete'), icon: 'delete', danger: true, run: () => this.deleteItem(item) },
        ];
    },

    catalogActions(entry) {
        const collection = this.route.view;

        return [
            { label: collection === 'stores' ? this.$t('Edit') : this.$t('Rename'), icon: 'edit', run: () => this.openRename(entry, collection) },
            { label: this.$t('Delete'), icon: 'delete', danger: true, run: () => this.deleteCatalogEntry(entry, collection) },
        ];
    },

    /** Keeps navStack in step with the browser history (push, back, or replaced entry). */
    trackNavigation(changed) {
        const hash = location.hash || '#/';
        if (!changed) this.navStack = [hash];
        else if (this.navReplacing) this.navStack[this.navStack.length - 1] = hash;
        else if (this.navStack.at(-2) === hash) this.navStack.pop();
        else this.navStack.push(hash);
        this.navReplacing = false;
    },

    /**
     * Back button: up in the structure (backLink). If that is the previous history entry, go
     * back in the history, otherwise replace the current entry, so the system back gesture
     * does not return to the page just left.
     */
    goBack(target = this.backLink) {
        if (this.navStack.at(-2) === target) {
            history.back();
            return;
        }
        this.navReplacing = true;
        location.replace(target);
    },

    get backLink() {
        if (['products', 'units', 'stores', 'install'].includes(this.route.view)) return '#/settings';
        if (['offers', 'recipes'].includes(this.route.view)) return '#/planner';

        return this.route.view === 'settings' ? this.settingsBack : '#/';
    },

    openMenu(target, actions) {
        const rect = target.getBoundingClientRect();
        const below = rect.bottom + 48 * actions.length + 16 < window.innerHeight;
        this.menu = {
            actions,
            style: `right: ${window.innerWidth - rect.right}px; ${below ? `top: ${rect.bottom + 4}px` : `bottom: ${window.innerHeight - rect.top + 4}px`}`,
        };
        this.$nextTick(() => this.$refs.menu?.querySelector('button')?.focus());
    },

    runMenu(action) {
        this.menu = null;
        action.run();
    },

    // ---- lists

    async createList() {
        const name = this.newListName.trim();
        if (!name) return;
        const id = uuid7();
        this.newListName = '';
        await db.lists.insert({
            id, name, owner_email: Alpine.store('sync').user?.email ?? null, is_owner: true, members: [], position: nextPosition(this.lists),
        });
        location.hash = `#/list/${id}`;
    },

    startRename() {
        this.renaming = this.list.name;
        this.$nextTick(() => this.$refs.renameInput?.focus());
    },

    async renameList() {
        const name = this.renaming?.trim();
        if (name && name !== this.list?.name) {
            await (await db.lists.findOne(this.route.id).exec())?.incrementalPatch({ name });
        }
        this.renaming = null;
    },

    /**
     * Rename dialog for lists (overview), products, units and shops (with code).
     */
    openRename(doc, collection) {
        this.renamingDoc = { collection, id: doc.id, name: doc.name, code: doc.code ?? null, icon: doc.icon ?? null };
        this.$nextTick(() => this.$refs.renameDialog.showModal());
    },

    async saveRename() {
        const { collection, id } = this.renamingDoc;
        const name = this.renamingDoc.name.trim();
        if (collection === 'stores') {
            if (await this.saveStore(this.renamingDoc)) this.$refs.renameDialog.close();
            return;
        }
        const doc = name ? await db[collection].findOne(id).exec() : null;
        // Product or unit renamed to an existing one: keep that one instead of a duplicate.
        const duplicate = collection !== 'lists' && Alpine.store('data')[collection].some((entry) => entry.id !== id
            && !Alpine.store('undo').hidden(entry.id) && sameName(entry.name, name));
        if (duplicate) await doc?.remove();
        else await doc?.incrementalPatch({ name });
        this.$refs.renameDialog.close();
    },

    deleteList(id = this.route.id) {
        if (this.route.id === id) this.goBack('#/');
        Alpine.store('undo').schedule(id, this.$t('List deleted'), async () => {
            await (await db.lists.findOne(id).exec())?.remove();
        });
    },

    async leaveList(id = this.route.id) {
        if (!this.requireOnline()) return;
        if (!confirm(this.$t('Leave this list?'))) return;
        await this.online(async () => {
            await api.delete(`/lists/${id}/members/${encodeURIComponent(Alpine.store('sync').user.email)}`);
            replication?.resync();
            this.goBack('#/');
        });
    },

    // ---- items

    async addItem() {
        const parsed = parseItemInput(this.newItemText, this.unitNames);
        if (!parsed.name) return;
        // Clear before awaiting, the next entry may already be typed.
        this.newItemText = '';

        const existing = this.openItems.find((item) => sameName(item.name, parsed.name));
        if (existing) {
            // Ask instead of adding the same product twice.
            this.duplicate = { existing, parsed, merged: mergedAmount(existing, parsed) };
            return;
        }
        this.duplicate = null;
        await this.insertItem(parsed);
    },

    async insertItem(parsed) {
        await db.items.insert({
            id: uuid7(),
            shopping_list_id: this.route.id,
            ...parsed,
            stores: this.newItemStore ? [this.newItemStore] : [],
            image: ownProduct(parsed.name)?.image ?? null,
            position: nextPosition(this.itemsOf(this.route.id)),
            creator_email: Alpine.store('sync').user?.email ?? null,
        });
        await remember('products', parsed.name);
    },

    async addDuplicate() {
        const { parsed } = this.duplicate;
        this.duplicate = null;
        await this.insertItem(parsed);
    },

    async mergeDuplicate() {
        const { existing, parsed, merged } = this.duplicate;
        this.duplicate = null;
        await (await db.items.findOne(existing.id).exec())?.incrementalPatch({ amount: merged, unit: existing.unit ?? parsed.unit });
    },

    // ---- shops (own ones: name + optional code, the items keep the tag)

    /**
     * Error message if the shop cannot be saved like this, otherwise null. Names and codes
     * must not collide, because both can be the tag on an item.
     */
    storeError(id, name, code) {
        if (!name) return this.$t('The name is required.');
        const others = this.stores.filter((store) => store.id !== id);
        if (others.some((store) => sameName(store.name, name) || (store.code && sameName(store.code, name)))) {
            return this.$t('A shop with this name already exists.');
        }
        if (code && others.some((store) => sameName(store.name, code) || (store.code && sameName(store.code, code)))) {
            return this.$t('The code :code is already used.', { code });
        }

        return null;
    },

    // ---- own products: add, export and import (CSV with one column "name")

    async createProduct() {
        const name = this.newProductName.trim().replace(/\s+/g, ' ');
        if (!name) return;
        if (Alpine.store('data').products.some((product) => !Alpine.store('undo').hidden(product.id) && sameName(product.name, name))) {
            return Alpine.store('notice').show(this.$t('“:name” already exists.', { name }));
        }
        await db.products.insert({ id: uuid7(), name });
        this.newProductName = '';
    },

    get ownProducts() {
        return Alpine.store('data').products.filter((product) => !product.is_public && !Alpine.store('undo').hidden(product.id));
    },

    exportProducts() {
        const names = this.ownProducts.map((product) => product.name).sort((a, b) => a.localeCompare(b));
        const csv = ['name', ...names.map(csvValue)].join('\r\n');
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' }));
        link.download = `${this.$t('products')}-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    },

    /**
     * Imports product names from a CSV file. "replace" deletes the own products first (with
     * their photos), "add" only adds the missing ones. Names of standard products are skipped.
     */
    async importProducts(event, mode) {
        const file = event.target.files[0];
        event.target.value = '';
        if (!file) return;
        const names = csvFirstColumn(await file.text()).map((name) => name.slice(0, 255));
        if (!names.length) return Alpine.store('notice').show(this.$t('The file contains no products.'));
        const own = this.ownProducts;
        if (mode === 'replace' && !confirm(this.$t('Delete your :count own products (with their photos) and import :new?', { count: own.length, new: names.length }))) return;

        if (mode === 'replace') await db.products.bulkRemove(own.map((product) => product.id));
        const known = Alpine.store('data').products
            .filter((product) => product.is_public || (mode !== 'replace' && !Alpine.store('undo').hidden(product.id)));
        const added = [];
        for (const name of names) {
            if (![...known, ...added].some((product) => sameName(product.name, name))) added.push({ id: uuid7(), name });
        }
        if (added.length) await db.products.bulkInsert(added);
        Alpine.store('notice').show(this.$t(':added of :total products imported (the others already exist).', { added: added.length, total: names.length }));
    },

    async createStore() {
        const name = this.newStore.name.trim();
        const code = this.newStore.code.trim() || null;
        const error = this.storeError(null, name, code);
        if (error) return Alpine.store('notice').show(error);
        await db.stores.insert({ id: uuid7(), name, code });
        this.newStore = { name: '', code: '' };
    },

    /**
     * Changes name and code of an own shop; the items with its old tag get the new one.
     */
    async saveStore({ id, name, code, icon = null }) {
        name = name.trim();
        code = (code ?? '').trim() || null;
        const error = this.storeError(id, name, code);
        if (error) {
            Alpine.store('notice').show(error);
            return false;
        }
        const doc = await db.stores.findOne(id).exec();
        if (!doc) return true;
        const old = this.storeTag(doc);
        await doc.incrementalPatch({ name, code, icon });
        const tag = code || name;
        if (old !== tag) {
            const items = Alpine.store('data').items.filter((item) => item.stores?.includes(old));
            const docs = await db.items.findByIds(items.map((item) => item.id)).exec();
            await Promise.all([...docs.values()].map((item) => item.incrementalPatch({
                stores: [...new Set(item.stores.map((each) => (each === old ? tag : each)))],
            })));
        }

        return true;
    },

    /**
     * Shop typed in the edit dialog: a code or name of a known shop, otherwise a new own
     * shop without code. Returns the tag for the item.
     */
    async storeTagFor(text) {
        text = text?.trim();
        if (!text) return null;
        const known = this.storeByTag(text);
        if (known) return this.storeTag(known);
        await db.stores.insert({ id: uuid7(), name: text.slice(0, 255), code: null });

        return text.slice(0, 255);
    },

    /** Own image as icon of a shop (rename dialog), kept small inside the shop document. */
    async pickStoreIcon(event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file || !this.renamingDoc) return;
        try {
            this.renamingDoc.icon = await shrinkIcon(file);
        } catch {
            Alpine.store('notice').show(this.$t('The image could not be read.'));
        }
    },

    /** Shops picked in the edit dialog (chips). */
    addEditingStore(tag) {
        if (tag && !this.editing.stores.some((each) => sameName(each, tag))) this.editing.stores = [...this.editing.stores, tag];
        this.editing.storeText = '';
    },
    async addTypedStore() {
        this.addEditingStore(await this.storeTagFor(this.editing.storeText));
    },
    removeEditingStore(tag) {
        this.editing.stores = this.editing.stores.filter((each) => each !== tag);
    },
    /** Shop suggestions of the edit dialog: without the picked ones. */
    get editingStoreChoices() {
        const picked = this.editing?.stores ?? [];

        return this.choosableStores.filter((store) => !picked.some((tag) => this.storeByTag(tag)?.id === store.id));
    },

    // ---- filter by shop (per list, on this device)

    /** Tags of the shops on the open items of the list, in the order of the shop list. */
    get listStoreTags() {
        const tags = [...new Set(this.openItems.flatMap((item) => item.stores ?? []))];
        const order = (tag) => {
            const index = this.stores.findIndex((store) => store.id === this.storeByTag(tag)?.id);
            return index === -1 ? Infinity : index;
        };

        return tags.sort((a, b) => order(a) - order(b) || a.localeCompare(b));
    },
    get storeFilter() {
        return (this.storeFilters[this.route.id] ?? []).filter((tag) => this.listStoreTags.includes(tag));
    },
    toggleStoreFilter(tag) {
        const current = this.storeFilter;
        const next = current.includes(tag) ? current.filter((each) => each !== tag) : [...current, tag];
        this.storeFilters = { ...this.storeFilters, [this.route.id]: next };
        localStorage.setItem(STORE_FILTER_KEY, JSON.stringify(this.storeFilters));
    },
    clearStoreFilter() {
        this.storeFilters = { ...this.storeFilters, [this.route.id]: [] };
        localStorage.setItem(STORE_FILTER_KEY, JSON.stringify(this.storeFilters));
    },
    /** Open items shown: with a filter only those of the chosen shops and those without a shop. */
    get shownOpenItems() {
        const filter = this.storeFilter;
        if (!filter.length) return this.openItems;

        return this.openItems.filter((item) => !item.stores?.length || item.stores.some((tag) => filter.includes(tag)));
    },

    async toggle(item) {
        await (await db.items.findOne(item.id).exec())?.incrementalPatch({ done: !item.done });
    },

    edit(item) {
        this.editing = { ...item, amount: item.amount ?? '', stores: [...(item.stores ?? [])], storeText: '', recipes: item.recipes ?? [] };
        this.$nextTick(() => this.$refs.editDialog.showModal());
    },

    async saveItem() {
        const { id, name, amount, unit, note } = this.editing;
        // A shop typed but not picked yet counts too.
        const typed = await this.storeTagFor(this.editing.storeText);
        // Plain arrays: RxDB cannot clone Alpine's proxies (BroadcastChannel to other tabs).
        const stores = typed && !this.editing.stores.some((tag) => sameName(tag, typed)) ? [...this.editing.stores, typed] : [...this.editing.stores];
        if (name.trim()) {
            const doc = await db.items.findOne(id).exec();
            // Renamed: the image of the (own) product with the new name.
            const image = doc && sameName(doc.name, name) ? this.editing.image : ownProduct(name)?.image ?? null;
            await doc?.incrementalPatch({
                name: name.trim(),
                image,
                amount: amount === '' || amount === null ? null : Number(String(amount).replace(',', '.')) || null,
                unit: unit?.trim() || null,
                stores,
                note: note?.trim().slice(0, 255) || null,
            });
            await remember('products', name.trim());
            if (unit?.trim() && !this.unitNames.some((known) => sameName(known, unit))) await remember('units', unit.trim());
        }
        this.closeEdit();
    },

    // ---- product images (need an account and a connection)

    imageUrl(image, size = null) {
        return image ? `/images/${image}${size ? `/${size}` : ''}` : '';
    },

    openImage(image) {
        Alpine.store('lightbox').open(image, this.$refs.lightbox);
    },

    /**
     * The photo belongs to the product of the entered name, every item of it shows it.
     */
    async chooseImage(event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file || !this.requireOnline()) return;
        const name = this.editing.name.trim();
        if (!name) return;

        this.imageBusy = true;
        try {
            const product = ownProduct(name) ?? (await db.products.insert({ id: uuid7(), name })).toJSON();
            const form = new FormData();
            form.append('name', product.name);
            const { image: large, thumb } = await shrinkImage(file);
            form.append('image', large, 'image');
            form.append('thumb', thumb, 'thumb');
            const { image } = await api.post(`/products/${product.id}/image`, form);
            await this.applyImage(name, image);
        } catch (error) {
            Alpine.store('notice').show(error instanceof HttpError && error.status === 422
                ? this.$t('The image could not be saved.')
                : this.$t('This needs an internet connection.'));
        } finally {
            this.imageBusy = false;
        }
    },

    async removeImage() {
        const product = ownProduct(this.editing.name);
        if (!this.requireOnline()) return;
        this.imageBusy = true;
        try {
            if (product?.image) await api.delete(`/products/${product.id}/image`);
            await this.applyImage(this.editing.name, null);
            // Without its photo an own copy of a standard product is not needed anymore.
            const standard = Alpine.store('data').products.some((each) => each.is_public && sameName(each.name, product?.name ?? ''));
            if (product && standard) await (await db.products.findOne(product.id).exec())?.remove();
        } catch {
            Alpine.store('notice').show(this.$t('This needs an internet connection.'));
        } finally {
            this.imageBusy = false;
        }
    },

    /**
     * Items of the product get the new image; the product itself arrives with the next pull.
     */
    async applyImage(name, image) {
        if (this.editing) this.editing.image = image;
        const items = Alpine.store('data').items.filter((item) => sameName(item.name, name) && item.image !== image);
        const docs = await db.items.findByIds(items.map((item) => item.id)).exec();
        await Promise.all([...docs.values()].map((doc) => doc.incrementalPatch({ image })));
        replication?.resync();
    },

    /**
     * Deleting is undoable for a few seconds instead of asking for confirmation.
     */
    deleteItem(item = this.editing) {
        if (this.editing) this.closeEdit();
        Alpine.store('undo').schedule(item.id, this.$t('“:name” deleted', { name: item.name }), async () => {
            await (await db.items.findOne(item.id).exec())?.remove();
        });
    },

    closeEdit() {
        this.$refs.editDialog.close();
        this.editing = null;
    },

    // ---- moving an item to another list

    openMove(item = this.editing) {
        if (this.editing) this.closeEdit();
        this.moving = item;
        this.$nextTick(() => this.$refs.moveDialog.showModal());
    },

    /**
     * The server keeps an item in its list, so moving is a copy at the end of the target list
     * (with a new id) and deleting the original, undoable like a deletion (undo removes the
     * copy). Works offline like any other change.
     */
    async moveItem(listId) {
        const item = this.moving;
        this.$refs.moveDialog.close();
        const fields = ['name', 'amount', 'unit', 'stores', 'note', 'recipes', 'image', 'done', 'creator_email'];
        const copyId = uuid7();
        await db.items.insert({
            ...Object.fromEntries(fields.map((field) => [field, item[field] ?? null])),
            stores: [...(item.stores ?? [])],
            recipes: (item.recipes ?? []).map(({ name, url }) => ({ name, url: url ?? null })),
            done: Boolean(item.done),
            id: copyId,
            shopping_list_id: listId,
            position: nextPosition(this.itemsOf(listId)),
        });
        const target = this.lists.find((list) => list.id === listId)?.name ?? '';
        Alpine.store('undo').schedule(item.id, this.$t('“:name” moved to “:list”', { name: item.name, list: target }), async () => {
            await (await db.items.findOne(item.id).exec())?.remove();
        }, async () => {
            await (await db.items.findOne(copyId).exec())?.remove();
        });
    },

    // ---- sorting (lists overview: per user, items: per list)

    sorts: SORTS,

    sortLabel(sort) {
        return {
            manual: this.$t('Custom'), name_asc: 'A–Z', name_desc: 'Z–A', newest: this.$t('Newest'), oldest: this.$t('Oldest'),
        }[sort];
    },

    get itemSort() {
        return this.list?.item_sort ?? 'manual';
    },

    async setItemSort(sort) {
        await (await db.lists.findOne(this.route.id).exec())?.incrementalPatch({ item_sort: sort });
    },

    /**
     * A row was dragged (resources/js/swipe.js): the shown order becomes the custom order.
     */
    async moveRow(kind, { from, to }) {
        const ids = (kind === 'lists' ? this.lists : this.openItems).map((doc) => doc.id);
        if (kind === 'items' && this.shownOpenItems.length !== this.openItems.length) {
            // Filtered by shop: move it next to the row it was dropped on, hidden rows stay put.
            const shown = this.shownOpenItems.map((doc) => doc.id);
            const [moved, target] = [shown[from], shown[to]];
            ids.splice(ids.indexOf(moved), 1);
            ids.splice(ids.indexOf(target) + (to > from ? 1 : 0), 0, moved);
        } else {
            ids.splice(to, 0, ...ids.splice(from, 1));
        }
        await this.reorder(kind, ids);
        if (kind === 'lists' && Alpine.store('prefs').list_sort !== 'manual') Alpine.store('prefs').set('list_sort', 'manual');
        if (kind === 'items' && this.itemSort !== 'manual') await this.setItemSort('manual');
    },

    /**
     * Stores the order as positions 0, 1, 2 …; only changed documents are written.
     */
    async reorder(kind, ids) {
        const docs = await db[kind].findByIds(ids).exec();
        await Promise.all(ids.map((id, position) => {
            const doc = docs.get(id);
            return doc && doc.position !== position ? doc.incrementalPatch({ position }) : null;
        }));
    },

    // ---- products, units and shops (suggestions)

    deleteCatalogEntry(entry, collection) {
        Alpine.store('undo').schedule(entry.id, this.$t('“:name” deleted', { name: entry.name }), async () => {
            await (await db[collection].findOne(entry.id).exec())?.remove();
        });
    },

    clearDone() {
        const ids = this.doneItems.map((item) => item.id);
        Alpine.store('undo').schedule(ids, this.$t('Done items deleted'), async () => {
            await db.items.bulkRemove(ids);
        });
    },

    // ---- sharing (needs a connection)

    /**
     * Sharing and leaving need the server: tell right away instead of opening a dead end.
     */
    requireOnline() {
        if (navigator.onLine) return true;
        Alpine.store('notice').show(this.$t('This needs an internet connection.'));

        return false;
    },

    openShare(id = this.route.id) {
        if (!this.requireOnline()) return;
        this.shareListId = id;
        this.shareEmail = '';
        this.shareError = null;
        this.$nextTick(() => this.$refs.shareDialog.showModal());
    },

    async share() {
        const email = this.shareEmail.trim();
        if (!email) return;
        this.shareError = null;
        await this.online(async () => {
            await api.post(`/lists/${this.shareListId}/members`, { email });
            this.shareEmail = '';
            replication?.resync();
        }, (error) => { this.shareError = error; });
    },

    async removeMember(email) {
        await this.online(async () => {
            await api.delete(`/lists/${this.shareListId}/members/${encodeURIComponent(email)}`);
            replication?.resync();
        }, (error) => { this.shareError = error; });
    },

    // ---- settings

    setLocale,

    async logout() {
        await this.online(async () => {
            await api.post('/logout');
            await wipeLocalData();
            location.replace('/');
        });
    },

    /**
     * Runs an action that needs the server and reports errors.
     */
    async online(action, onError = (message) => Alpine.store('notice').show(message)) {
        if (!navigator.onLine) return onError(this.$t('This needs an internet connection.'));
        this.busy = true;
        try {
            await action();
        } catch (error) {
            onError(error instanceof HttpError
                ? Object.values(error.body?.errors ?? {})[0]?.[0] ?? error.message
                : this.$t('This needs an internet connection.'));
        } finally {
            this.busy = false;
        }
    },
}));

// ---------------------------------------------------------------- database + sync

/**
 * New products and units are stored for the suggestions (own entries of the user).
 */
async function remember(collection, name) {
    const existing = Alpine.store('data')[collection].find((entry) => !Alpine.store('undo').hidden(entry.id)
        && sameName(entry.name, name));

    return existing ?? (await db[collection].insert({ id: uuid7(), name })).toJSON();
}

/**
 * An own product with the name of a standard product only carries the user's photo of it:
 * the standard product is shown with that photo instead of listing both.
 */
function withOwnImages(products) {
    // Like sameName: case and spaces are ignored, accents not.
    const key = (name) => name.trim().replace(/\s+/g, ' ').toLowerCase();
    const own = new Map(products.filter((product) => !product.is_public).map((product) => [key(product.name), product]));
    const standard = new Set(products.filter((product) => product.is_public).map((product) => key(product.name)));

    return products
        .filter((product) => product.is_public || !standard.has(key(product.name)))
        .map((product) => (product.is_public && own.get(key(product.name))?.image
            ? { ...product, image: own.get(key(product.name)).image }
            : product));
}

function ownProduct(name) {
    return Alpine.store('data').products.find((product) => !product.is_public && !Alpine.store('undo').hidden(product.id)
        && sameName(product.name, name));
}

async function wipeLocalData() {
    await replication?.cancel();
    await db?.remove();
    localStorage.removeItem(OWNER_KEY);
    localStorage.removeItem(USER_KEY);
}

async function boot() {
    let storage = getRxStorageDexie();

    // Dev mode and schema validation only in development, they add a lot of bundle size.
    if (import.meta.env.DEV) {
        const { RxDBDevModePlugin } = await import('rxdb/plugins/dev-mode');
        const { wrappedValidateAjvStorage } = await import('rxdb/plugins/validate-ajv');
        addRxPlugin(RxDBDevModePlugin);
        storage = wrappedValidateAjvStorage({ storage });
    }

    try {
        db = await createDatabase({ storage });
    } catch (error) {
        console.error(error);
        Alpine.store('sync').status = 'broken';
        return;
    }
    navigator.storage?.persist?.();

    const sync = Alpine.store('sync');
    const data = Alpine.store('data');

    // The UI only renders the local database, with or without a connection.
    db.lists.find({ sort: [{ name: 'asc' }] }).$.subscribe((docs) => { data.lists = docs.map((doc) => doc.toJSON()); });
    db.items.find({ sort: [{ id: 'asc' }] }).$.subscribe((docs) => { data.items = docs.map((doc) => doc.toJSON()); });
    db.products.find().$.subscribe((docs) => { data.products = docs.map((doc) => doc.toJSON()); });
    db.units.find({ sort: [{ name: 'asc' }] }).$.subscribe((docs) => { data.units = docs.map((doc) => doc.toJSON()); });
    db.stores.find({ sort: [{ name: 'asc' }] }).$.subscribe((docs) => { data.stores = docs.map((doc) => doc.toJSON()); });

    window.addEventListener('online', () => { sync.online = true; });
    window.addEventListener('offline', () => {
        sync.online = false;
        if (['synced', 'syncing', 'error'].includes(sync.status)) sync.status = 'offline';
    });

    await connect();
}

/**
 * Checks the session and starts the sync. Started offline, it retries as soon as
 * the device is online again.
 */
async function connect() {
    const sync = Alpine.store('sync');

    try {
        sync.user = await api.get('/me');
    } catch (error) {
        if (error instanceof HttpError && error.status === 401) {
            localStorage.removeItem(USER_KEY);
            sync.user = null;
            sync.status = localStorage.getItem(OWNER_KEY) ? 'expired' : 'local';
        } else {
            // Offline: keep working with the local data and the last known account.
            sync.user = JSON.parse(localStorage.getItem(USER_KEY) ?? 'null');
            sync.status = 'offline';
            window.addEventListener('online', connect, { once: true });
        }
        return;
    }

    // Another account logged in on this device: never push the previous user's data.
    const owner = localStorage.getItem(OWNER_KEY);
    if (owner && owner !== sync.user.email) {
        await wipeLocalData();
        location.reload();
        return;
    }
    localStorage.setItem(USER_KEY, JSON.stringify(sync.user));

    await Alpine.store('prefs').connect(sync.user);

    if (hasPendingLocale()) {
        await setLocale(Alpine.store('i18n').locale);
    } else if (sync.user.locale in LOCALES && sync.user.locale !== Alpine.store('i18n').locale) {
        Alpine.store('i18n').locale = sync.user.locale;
        document.documentElement.lang = htmlLang(sync.user.locale);
        storeLocale(sync.user.locale, { pending: false });
    }

    if (!sync.user.verified) {
        sync.status = 'unverified';
        return;
    }

    localStorage.setItem(OWNER_KEY, sync.user.email);
    if (!replication) startSync();
}

function startSync() {
    const sync = Alpine.store('sync');
    replication = startReplication(db, api);

    replication.states.forEach((state) => {
        state.active$.subscribe((active) => {
            if (active) sync.status = 'syncing';
        });
        state.error$.subscribe((error) => {
            const status = error?.parameters?.errors?.[0]?.status ?? error?.parameters?.error?.status;
            if (status === 401 || status === 419) sync.status = 'expired';
            else sync.status = navigator.onLine ? 'error' : 'offline';
        });
    });

    const settle = () => replication.awaitInSync().then(() => {
        if (['starting', 'syncing', 'offline', 'error'].includes(sync.status)) sync.status = 'synced';
    });
    settle();

    const resync = () => {
        if (!navigator.onLine || document.visibilityState !== 'visible') return;
        replication.resync();
        settle();
    };
    window.addEventListener('online', resync);
    document.addEventListener('visibilitychange', resync);
    setInterval(resync, RESYNC_INTERVAL);
}

// Links to server pages (login, account, …) need a connection.
document.addEventListener('click', (event) => {
    if (navigator.onLine || !event.target.closest('a[data-online]')) return;
    event.preventDefault();
    Alpine.store('notice').show(translate(Alpine.store('i18n').locale, 'This needs an internet connection.'));
});

window.Alpine = Alpine;
Alpine.start();
// Back from "Connect with <recipe app>" (RecipesConnectionController): show the result once.
const recipesResult = new URLSearchParams(location.search).get('recipes');
if (recipesResult) {
    history.replaceState(null, '', `/${location.hash}`);
    Alpine.store('notice').show(translate(Alpine.store('i18n').locale, {
        connected: 'Connected.',
        denied: 'The connection was not allowed.',
    }[recipesResult] ?? 'The connection failed. Please try again.'));
}

boot();
registerServiceWorker((apply) => { Alpine.store('update').apply = apply; });
