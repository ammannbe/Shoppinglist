import Alpine from 'alpinejs';
import { addRxPlugin } from 'rxdb/plugins/core';
import { getRxStorageDexie } from 'rxdb/plugins/storage-dexie';
import { createApi, HttpError } from './api.js';
import { createDatabase, startReplication } from './db/index.js';
import { formatAmount, parseItemInput, uuid7 } from './helpers.js';
import { hasPendingLocale, htmlLang, initialLocale, LOCALES, storeLocale, translate } from './i18n.js';
import { registerServiceWorker } from './pwa.js';

const RESYNC_INTERVAL = 30_000;
const DEFAULT_UNITS = ['Stk.', 'g', 'kg', 'ml', 'dl', 'l', 'Pck.', 'Bund', 'Fl.'];
const OWNER_KEY = 'shoppinglist.owner';
const USER_KEY = 'shoppinglist.user';

const api = createApi();
let db = null;
let replication = null;

// ---------------------------------------------------------------- stores

Alpine.store('i18n', { locale: initialLocale(), locales: LOCALES });
// status: starting | local (not logged in) | unverified | expired | offline | syncing | synced | error
Alpine.store('sync', { status: 'starting', user: null, online: navigator.onLine });
Alpine.store('data', { lists: [], items: [], products: [], units: [] });

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

Alpine.data('shoppinglist', () => ({
    route: { view: 'lists', id: null },
    newListName: '',
    newItemText: '',
    showDone: false,
    editing: null,
    renaming: null,
    shareEmail: '',
    shareError: null,
    busy: false,
    installHintDismissed: localStorage.getItem('shoppinglist.install-hint') === 'dismissed',

    init() {
        const parse = () => {
            const [, view, id = null] = location.hash.split('/');
            this.route = { view: view || 'lists', id };
            this.renaming = null;
        };
        window.addEventListener('hashchange', parse);
        parse();
    },

    // ---- data helpers

    get lists() {
        return Alpine.store('data').lists;
    },
    get list() {
        return this.lists.find((list) => list.id === this.route.id) ?? null;
    },
    itemsOf(listId) {
        return Alpine.store('data').items.filter((item) => item.shopping_list_id === listId);
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
        // Defaults for guests and the first start, the server list arrives with the sync.
        return [...new Set([...DEFAULT_UNITS, ...Alpine.store('data').units.map((unit) => unit.name)])];
    },
    get productNames() {
        return [...new Set(Alpine.store('data').products.map((product) => product.name))].sort();
    },
    get loggedIn() {
        return Alpine.store('sync').user !== null;
    },
    get isIos() {
        return /iphone|ipad|ipod/i.test(navigator.userAgent) && !navigator.standalone;
    },
    amount(item) {
        return [formatAmount(item.amount, Alpine.store('i18n').locale), item.unit].filter(Boolean).join(' ');
    },

    // ---- lists

    async createList() {
        const name = this.newListName.trim();
        if (!name) return;
        const id = uuid7();
        this.newListName = '';
        await db.lists.insert({ id, name, owner_email: Alpine.store('sync').user?.email ?? null, is_owner: true, members: [] });
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

    async deleteList() {
        if (!confirm(this.$t('Delete this list for all members?'))) return;
        await (await db.lists.findOne(this.route.id).exec())?.remove();
        location.hash = '#/';
    },

    async leaveList() {
        if (!confirm(this.$t('Leave this list?'))) return;
        await this.online(async () => {
            await api.delete(`/lists/${this.route.id}/members/${encodeURIComponent(Alpine.store('sync').user.email)}`);
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
        await db.items.insert({
            id: uuid7(),
            shopping_list_id: this.route.id,
            ...parsed,
            creator_email: Alpine.store('sync').user?.email ?? null,
        });
        await rememberProduct(parsed.name);
    },

    async toggle(item) {
        await (await db.items.findOne(item.id).exec())?.incrementalPatch({ done: !item.done });
    },

    edit(item) {
        this.editing = { ...item, amount: item.amount ?? '' };
        this.$nextTick(() => this.$refs.editDialog.showModal());
    },

    async saveItem() {
        const { id, name, amount, unit } = this.editing;
        if (name.trim()) {
            await (await db.items.findOne(id).exec())?.incrementalPatch({
                name: name.trim(),
                amount: amount === '' || amount === null ? null : Number(String(amount).replace(',', '.')) || null,
                unit: unit?.trim() || null,
            });
            await rememberProduct(name.trim());
        }
        this.closeEdit();
    },

    async deleteItem() {
        await (await db.items.findOne(this.editing.id).exec())?.remove();
        this.closeEdit();
    },

    closeEdit() {
        this.$refs.editDialog.close();
        this.editing = null;
    },

    async clearDone() {
        await db.items.bulkRemove(this.doneItems.map((item) => item.id));
    },

    // ---- sharing (needs a connection)

    openShare() {
        this.shareEmail = '';
        this.shareError = null;
        this.$refs.shareDialog.showModal();
    },

    async share() {
        const email = this.shareEmail.trim();
        if (!email) return;
        this.shareError = null;
        await this.online(async () => {
            await api.post(`/lists/${this.route.id}/members`, { email });
            this.shareEmail = '';
            replication?.resync();
        }, (error) => { this.shareError = error; });
    },

    async removeMember(email) {
        await this.online(async () => {
            await api.delete(`/lists/${this.route.id}/members/${encodeURIComponent(email)}`);
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

    dismissInstallHint() {
        localStorage.setItem('shoppinglist.install-hint', 'dismissed');
        this.installHintDismissed = true;
    },

    /**
     * Runs an action that needs the server and reports errors.
     */
    async online(action, onError = (message) => alert(message)) {
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

async function rememberProduct(name) {
    const exists = Alpine.store('data').products.some((product) => product.name.toLowerCase() === name.toLowerCase());
    if (!exists) await db.products.insert({ id: uuid7(), name });
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

    db = await createDatabase({ storage });
    navigator.storage?.persist?.();

    const sync = Alpine.store('sync');
    const data = Alpine.store('data');

    // The UI only renders the local database, with or without a connection.
    db.lists.find({ sort: [{ name: 'asc' }] }).$.subscribe((docs) => { data.lists = docs.map((doc) => doc.toJSON()); });
    db.items.find({ sort: [{ id: 'asc' }] }).$.subscribe((docs) => { data.items = docs.map((doc) => doc.toJSON()); });
    db.products.find().$.subscribe((docs) => { data.products = docs.map((doc) => doc.toJSON()); });
    db.units.find({ sort: [{ name: 'asc' }] }).$.subscribe((docs) => { data.units = docs.map((doc) => doc.toJSON()); });

    window.addEventListener('online', () => { sync.online = true; });
    window.addEventListener('offline', () => { sync.online = false; });

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

window.Alpine = Alpine;
Alpine.start();
boot();
registerServiceWorker();
