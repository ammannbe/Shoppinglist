import Alpine from 'alpinejs';
import { addRxPlugin } from 'rxdb/plugins/core';
import { getRxStorageDexie } from 'rxdb/plugins/storage-dexie';
import { createApi, HttpError } from './api.js';
import { createDatabase, removeDatabase, startReplication } from './db/index.js';
import { byPosition, compareBy, formatAmount, mergedAmount, nextPosition, normalizeName, parseItemInput, sameName, uuid7 } from './helpers.js';
import { hasPendingLocale, htmlLang, initialLocale, LOCALES, storeLocale, translate } from './i18n.js';
import { registerServiceWorker } from './pwa.js';
import { shrinkImage } from './image.js';
import { createLightboxStore } from './lightbox.js';
import { createPrefsStore } from './prefs.js';
import { planner } from './planner.js';
import { suggestField } from './suggest.js';
import { swipeRow } from './swipe.js';
import { createUndoStore } from './undo.js';
import { createInstallStore } from './install.js';

const RESYNC_INTERVAL = 30_000;
const DEFAULT_UNITS = ['Stk.', 'g', 'kg', 'ml', 'dl', 'l', 'Pck.', 'Bund', 'Fl.'];
// Shop codes offered in the edit dialog (any short code is possible).
const DEFAULT_STORES = ['M', 'C', 'A', 'L', 'D'];
const OWNER_KEY = 'shoppinglist.owner';
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
Alpine.store('data', { lists: [], items: [], products: [], units: [] });
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

// The products/units view renders at most this many rows, the search narrows it down.
const CATALOG_SHOWN = 200;

const byName = (a, b) => a.name.localeCompare(b.name, Alpine.store('i18n').locale.replace('_', '-'));
const byId = (a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0);

// Sort options of the lists overview (per user) and of the items of a list (per list).
const SORTS = ['manual', 'name_asc', 'name_desc', 'newest', 'oldest'];

Alpine.data('shoppinglist', () => ({
    route: { view: 'lists', id: null },
    newListName: '',
    newItemText: '',
    showDone: false,
    editing: null,
    imageBusy: false,
    // New entry of a product that is already open on the list: { existing, parsed, merged }
    duplicate: null,
    catalogSearch: '',
    renaming: null,
    renamingDoc: null,
    shareListId: null,
    shareEmail: '',
    shareError: null,
    busy: false,
    menu: null,
    // Where the back button of the settings leads (the list they were opened from).
    settingsBack: '#/',

    init() {
        const parse = (event) => {
            const [, view, id = null] = location.hash.split('/');
            if (view === 'settings' && event) {
                const from = new URL(event.oldURL).hash;
                if (from.startsWith('#/list/')) this.settingsBack = from;
                else if (!/^#\/(products|units|install)/.test(from)) this.settingsBack = '#/';
            }
            // Any other path means the service worker served the app shell offline.
            this.route = location.pathname === '/' ? { view: view || 'lists', id } : { view: 'offline', id: null };
            this.renaming = null;
            this.menu = null;
            this.duplicate = null;
            this.catalogSearch = '';
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
     * Products or units view (route.view), filtered by the search: own entries can be
     * renamed and deleted, public ones are read-only.
     */
    get catalog() {
        const collection = this.route.view;
        if (!['products', 'units'].includes(collection)) return [];
        const search = normalizeName(this.catalogSearch);
        let entries = Alpine.store('data')[collection].filter((entry) => !Alpine.store('undo').hidden(entry.id));
        if (collection === 'units' && !entries.some((entry) => entry.is_public)) {
            entries = [...DEFAULT_UNITS.map((name) => ({ id: `default:${name}`, name, is_public: true })), ...entries];
        }

        return entries
            .filter((entry) => !search || normalizeName(entry.name).includes(search))
            .sort((a, b) => Number(a.is_public) - Number(b.is_public) || byName(a, b));
    },
    get shownCatalog() {
        return this.catalog.slice(0, CATALOG_SHOWN);
    },
    get storeCodes() {
        const used = Alpine.store('data').items.map((item) => item.store).filter(Boolean);

        return [...new Set([...DEFAULT_STORES, ...used])];
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
    async addPlannedItems(target, name, items) {
        let listId = target;
        if (!listId) {
            listId = uuid7();
            await db.lists.insert({
                id: listId, name, owner_email: Alpine.store('sync').user?.email ?? null, is_owner: true, members: [], position: nextPosition(this.lists),
            });
        }
        let position = nextPosition(this.itemsOf(listId)) ?? 0;
        for (const item of items) {
            await db.items.insert({
                id: uuid7(),
                shopping_list_id: listId,
                name: item.name,
                amount: item.amount ?? null,
                unit: item.unit ?? null,
                store: item.store ?? null,
                note: item.note ?? null,
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
            { label: this.$t('Delete'), icon: 'delete', danger: true, run: () => this.deleteItem(item) },
        ];
    },

    catalogActions(entry) {
        const collection = this.route.view;

        return [
            { label: this.$t('Rename'), icon: 'edit', run: () => this.openRename(entry, collection) },
            { label: this.$t('Delete'), icon: 'delete', danger: true, run: () => this.deleteCatalogEntry(entry, collection) },
        ];
    },

    get backLink() {
        if (['products', 'units', 'install'].includes(this.route.view)) return '#/settings';

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
     * Rename dialog for lists (overview) and products.
     */
    openRename(doc, collection) {
        this.renamingDoc = { collection, id: doc.id, name: doc.name };
        this.$nextTick(() => this.$refs.renameDialog.showModal());
    },

    async saveRename() {
        const { collection, id } = this.renamingDoc;
        const name = this.renamingDoc.name.trim();
        const doc = name ? await db[collection].findOne(id).exec() : null;
        // Product or unit renamed to an existing one: keep that one instead of a duplicate.
        const duplicate = collection !== 'lists' && Alpine.store('data')[collection].some((entry) => entry.id !== id
            && !Alpine.store('undo').hidden(entry.id) && sameName(entry.name, name));
        if (duplicate) await doc?.remove();
        else await doc?.incrementalPatch({ name });
        this.$refs.renameDialog.close();
    },

    deleteList(id = this.route.id) {
        if (this.route.id === id) location.hash = '#/';
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
            location.hash = '#/';
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

    async toggle(item) {
        await (await db.items.findOne(item.id).exec())?.incrementalPatch({ done: !item.done });
    },

    edit(item) {
        this.editing = { ...item, amount: item.amount ?? '' };
        this.$nextTick(() => this.$refs.editDialog.showModal());
    },

    async saveItem() {
        const { id, name, amount, unit, store, note } = this.editing;
        if (name.trim()) {
            const doc = await db.items.findOne(id).exec();
            // Renamed: the image of the (own) product with the new name.
            const image = doc && sameName(doc.name, name) ? this.editing.image : ownProduct(name)?.image ?? null;
            await doc?.incrementalPatch({
                name: name.trim(),
                image,
                amount: amount === '' || amount === null ? null : Number(String(amount).replace(',', '.')) || null,
                unit: unit?.trim() || null,
                store: store?.trim().toUpperCase().slice(0, 10) || null,
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
        ids.splice(to, 0, ...ids.splice(from, 1));
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

    // ---- products and units (suggestions)

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
