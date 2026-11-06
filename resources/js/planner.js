import Alpine from 'alpinejs';
import { createApi, HttpError } from './api.js';
import { prepareForAi } from './image.js';
import { offerReader } from './offers.js';
import { closeDialogThen } from './layers.js';
import { allowedStep, recipePayload, remoteIsNewer, replaceSuggestions, STEPS, stepAllowed, stepTrail, suggestionExclude, SYNCED_KEYS, syncedState } from './planner-state.js';
import { requests } from './requests.js';

// Week planner (#/planner/<step>): offers (screenshots, PDFs, text) and pantry photos are read
// by the AI on the server, recipes come from the connected recipe app and/or the AI, the result
// is added to a list. Four steps (planner-state.js STEPS), each its own history entry.
// The state is kept in localStorage, so a reload (Android may kill the browser while the camera
// is open) does not lose the progress, and on the server (PlannerStateController) to continue
// on another device: last write wins by the time of the change.

const STORAGE_KEY = 'shoppinglist.planner';
// { updated_at: ms of the last change, pending: not sent to the server yet }
const SYNC_KEY = 'shoppinglist.planner-sync';
// Last step opened (device only), #/planner opens it.
const STEP_KEY = 'shoppinglist.planner-step';
const OWNER_KEY = 'shoppinglist.owner';
const PUSH_DELAY = 1000;
const api = createApi();

/** Logout or another account: the planner state of this device is removed with the local data. */
export function clearPlannerState() {
    [STORAGE_KEY, SYNC_KEY, STEP_KEY].forEach((key) => localStorage.removeItem(key));
}

function readSyncMeta(stored) {
    const meta = JSON.parse(localStorage.getItem(SYNC_KEY) ?? 'null');
    if (meta) return meta;
    // State of a version without server sync: sent once, unless the server has one already.
    const started = Boolean(stored.offers?.length || stored.pantry?.length || stored.suggestions?.length);

    return { updated_at: started ? 1 : 0, pending: started };
}
function initialState() {
    return {
        offers: [],
        pantry: [],
        servings: 4,
        count: 5,
        ideas: true,
        wishes: '',
        suggestions: [],
        warning: null,
        // The warning belongs to "suggest more" (shown below that button).
        warningMore: false,
        items: [],
        target: '',
        listName: '',
    };
}

/**
 * Offers grouped by shop tag, in order of the tag; offers without shop last.
 *
 * @returns {{ store: string, offers: object[] }[]}
 */
function groupByStore(offers) {
    const groups = new Map();
    for (const offer of offers) {
        const store = offer.store ?? '';
        if (!groups.has(store)) groups.set(store, []);
        groups.get(store).push(offer);
    }

    return [...groups.entries()]
        .sort(([a], [b]) => (a === '' ? 1 : b === '' ? -1 : a.localeCompare(b)))
        .map(([store, list]) => ({ store, offers: list }));
}

/**
 * @param {object} options
 * @param {(target: string, name: string, items: object[], merge: boolean) => Promise<string>} options.addItems adds the items, returns the list id
 * @param {(target: string, items: object[]) => number} options.merges number of items that could be merged into open ones
 * @param {() => object[]} options.lists lists to add to
 * @param {(hash: string) => void} options.open opens a page next to the lists (shoppinglist.openFromLists)
 * @param {(hash: string, options: object) => void} options.navigate shoppinglist.navigate (history aware)
 */
export function planner({ addItems, merges, lists, open, navigate }) {
    const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
    // Synced state as last sent or received (JSON), to notice real changes.
    let syncedJson = null;
    let pushTimer = null;
    let pushing = false;
    let pulling = false;

    return {
        ...initialState(),
        ...stored,
        // Current step (route #/planner/<step>), null outside the planner.
        step: null,
        steps: STEPS,
        syncMeta: readSyncMeta(stored),
        offerText: '',
        newPantryItem: '',
        // Saved offers (pool) not taken yet, grouped by shop to take them per shop.
        savedOffers: null,
        // Ingredients already open on the target list, asked before adding (null: not asked).
        mergeCount: null,
        // Suggestion shown in the recipe dialog (all details and actions).
        detail: null,
        ...requests(),
        ...offerReader(),

        init() {
            syncedJson = JSON.stringify(syncedState(this));
            const save = () => {
                const state = Object.fromEntries(Object.keys(initialState()).map((key) => [key, this[key]]));
                localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
                this.stateChanged();
            };
            Object.keys(initialState()).forEach((key) => this.$watch(key, save));
            this.$watch('target', () => { this.mergeCount = null; });
            // A recipe of the history (#/recipes) planned again.
            window.addEventListener('plan-recipe', (event) => this.planRecipe(event.detail));

            // Opened (not when changing the step): saved offers and the state of the server are loaded.
            const opened = ({ force = false } = {}) => {
                if (!location.hash.startsWith('#/planner')) {
                    this.step = null;
                    return;
                }
                const wasOpen = this.step !== null;
                if (!this.routeStep() || (wasOpen && !force)) return;
                // Before the offers: their request sets `busy`, which holds the pull back.
                this.pullState();
                if (Alpine.store('sync').user?.verified && navigator.onLine && !this.busy) this.loadSavedOffers();
            };
            window.addEventListener('hashchange', () => opened());
            opened();
            // The account is known and the sync started (the device belongs to it): load once.
            // A microtask, so only the store is tracked by the effect.
            let ready = false;
            Alpine.effect(() => {
                const now = Boolean(Alpine.store('sync').user) && !['starting', 'unverified', 'expired', 'local'].includes(Alpine.store('sync').status);
                queueMicrotask(() => {
                    if (now && !ready) opened({ force: true });
                    ready = now;
                });
            });
            // Back in the app or online again: changes of other devices, own changes not sent yet.
            const resume = () => {
                if (document.visibilityState !== 'visible') {
                    this.pushState();
                    return;
                }
                if (this.step) this.pullState();
                else this.pushState();
            };
            document.addEventListener('visibilitychange', resume);
            window.addEventListener('online', resume);
        },

        // ---- steps (#/planner/<step>, the back gesture goes back one step)

        /**
         * Takes the step of the route; #/planner, unknown steps and steps that cannot be
         * opened yet are replaced by the last step that can (#/planner: the last used one).
         *
         * @returns {boolean} whether the route was a valid step
         */
        routeStep() {
            const requested = location.hash.split('/')[2] ?? null;
            const step = allowedStep(requested ?? localStorage.getItem(STEP_KEY) ?? STEPS[0], this);
            if (step !== requested) {
                navigate(`#/planner/${step}`, { replace: true });
                return false;
            }
            this.step = step;
            localStorage.setItem(STEP_KEY, step);

            return true;
        },

        canOpen(step) {
            return stepAllowed(step, this);
        },

        get stepIndex() {
            return STEPS.indexOf(this.step);
        },

        get nextStep() {
            return STEPS[this.stepIndex + 1] ?? null;
        },

        /** Why "Next" is disabled (shown next to it). */
        get nextBlocked() {
            if (!this.nextStep || this.canOpen(this.nextStep)) return null;

            return this.nextStep === 'recipes' ? this.$t('Take offers or add what is at home first.') : this.$t('Select at least one recipe.');
        },

        /** Earlier steps go back in the history, later ones push the skipped steps first. */
        goStep(step) {
            if (!step || step === this.step || !this.canOpen(step)) return;
            const target = `#/planner/${step}`;
            if (STEPS.indexOf(step) < this.stepIndex) {
                navigate(target, { replace: true });
            } else {
                navigate(target, { trail: stepTrail(this.step, step).map((each) => `#/planner/${each}`) });
            }
        },

        startOver() {
            if (!confirm(this.$t('Start over?'))) return;
            this.reset();
            this.goStep(STEPS[0]);
        },

        // ---- state on the server (PUT/GET /planner/state)

        /** Synced only for the account this device belongs to (not before a switch is detected). */
        canSync() {
            const user = Alpine.store('sync').user;

            return Boolean(user?.verified && user.assistant && navigator.onLine && localStorage.getItem(OWNER_KEY) === user.email);
        },

        setSyncMeta(meta) {
            this.syncMeta = meta;
            localStorage.setItem(SYNC_KEY, JSON.stringify(meta));
        },

        /** A synced part changed: remember the time and send it a moment later. */
        stateChanged() {
            const json = JSON.stringify(syncedState(this));
            if (json === syncedJson) return;
            syncedJson = json;
            this.setSyncMeta({ updated_at: Math.max(Date.now(), this.syncMeta.updated_at + 1), pending: true });
            clearTimeout(pushTimer);
            pushTimer = setTimeout(() => this.pushState(), PUSH_DELAY);
        },

        /** Sends the state not sent yet; offline it stays pending until the next foreground or reconnect. */
        async pushState() {
            clearTimeout(pushTimer);
            if (!this.syncMeta.pending || !this.canSync()) return;
            if (pushing) {
                pushTimer = setTimeout(() => this.pushState(), PUSH_DELAY);
                return;
            }
            pushing = true;
            const sentAt = this.syncMeta.updated_at;
            try {
                const remote = await api.put('/planner/state', { state: JSON.parse(syncedJson), updated_at: sentAt });
                // Changed again meanwhile: that change is sent next.
                if (this.syncMeta.updated_at !== sentAt) return;
                if (remote.accepted) this.setSyncMeta({ updated_at: remote.updated_at, pending: false });
                else this.applyRemote(remote);
            } catch (error) {
                // Rejected (too large): retrying does not help.
                if (error instanceof HttpError && error.status === 422) this.setSyncMeta({ ...this.syncMeta, pending: false });
            } finally {
                pushing = false;
            }
            if (this.syncMeta.pending && this.syncMeta.updated_at !== sentAt) pushTimer = setTimeout(() => this.pushState(), PUSH_DELAY);
        },

        /** Takes the server's state if it is newer, then sends own changes not sent yet. */
        async pullState() {
            if (!this.canSync() || pulling || this.busy) return;
            pulling = true;
            try {
                const remote = await api.get('/planner/state');
                if (remoteIsNewer(this.syncMeta, remote)) this.applyRemote(remote);
            } catch {
                // Offline or server error: the local state stays, retried later.
            } finally {
                pulling = false;
            }
            await this.pushState();
        },

        applyRemote(remote) {
            const defaults = initialState();
            const values = remote.state && !Array.isArray(remote.state) ? remote.state : {};
            const state = Object.fromEntries(SYNCED_KEYS.map((key) => [key, values[key] ?? defaults[key]]));
            syncedJson = JSON.stringify(syncedState(state));
            Object.assign(this, state);
            this.mergeCount = null;
            this.setSyncMeta({ updated_at: remote.updated_at, pending: false });
            // The current step may not be possible anymore (e.g. the offers were removed there).
            if (this.step && !this.canOpen(this.step)) this.goStep(allowedStep(this.step, this));
        },

        get recipes() {
            return Alpine.store('sync').user?.recipes ?? null;
        },

        get selectedOffers() {
            return this.offers.filter((offer) => offer.selected);
        },

        get selectedPantry() {
            return this.pantry.filter((item) => item.selected);
        },

        get selectedSuggestions() {
            return this.suggestions.filter((suggestion) => suggestion.selected);
        },

        get selectedItems() {
            return this.items.filter((item) => item.selected);
        },

        reset() {
            Object.assign(this, initialState());
            this.error = null;
            this.errorKey = null;
        },


        // ---- 1. offers


        addOffers(offers) {
            const known = new Set(this.offers.map((offer) => offer.id).filter(Boolean));
            this.offers = [...this.offers, ...offers.filter((offer) => !known.has(offer.id)).map((offer) => ({ ...offer, selected: true }))];
        },

        removeOfferItem(offer) {
            this.offers = this.offers.filter((each) => each !== offer);
        },

        /** Offers of the planner by shop (no shop last). */
        get offerGroups() {
            return groupByStore(this.offers);
        },

        /** Selects or clears all offers of a shop. */
        selectGroup(group, selected) {
            group.offers.forEach((offer) => { offer.selected = selected; });
        },

        /** Offers of the pool (#/offers) that are not taken yet: own and shared, not expired. */
        async loadSavedOffers() {
            await this.run('saved', this.$t('Loading offers …'), async () => {
                this.savedOffers = (await api.get('/offers')).offers;
            });
        },

        /** Saved offers not taken into the planner yet. */
        get availableOffers() {
            const taken = new Set(this.offers.map((offer) => offer.id).filter(Boolean));

            return (this.savedOffers ?? []).filter((offer) => !taken.has(offer.id));
        },

        /** Removes the offers of one shop group, or all. */
        removeOffers(offers = this.offers) {
            const removed = new Set(offers);
            this.offers = this.offers.filter((offer) => !removed.has(offer));
        },

        clearPantry() {
            this.pantry = [];
        },

        /** "until 4.11." for a group: the latest end date of its offers. */
        groupValidity(offers) {
            const last = offers.map((offer) => offer.valid_until).filter(Boolean).sort().at(-1);
            if (!last) return null;
            const date = new Date(`${last}T12:00:00`).toLocaleDateString(Alpine.store('i18n').locale.replace('_', '-'), { day: 'numeric', month: 'numeric' });

            return this.$t('until :date', { date });
        },

        get savedGroups() {
            return groupByStore(this.availableOffers);
        },

        /** Takes saved offers (one shop or all) into the planner. */
        takeSaved(offers) {
            this.addOffers(offers);
        },


        // ---- 2. pantry

        async readPantry(event) {
            const files = [...event.target.files].slice(0, 6);
            event.target.value = '';
            if (!files.length) return;
            await this.run('pantry', this.$t('Looking at the photos …'), async () => {
                const form = new FormData();
                for (const file of files) form.append('photos[]', await prepareForAi(file), file.name);
                const { items } = await api.post('/planner/pantry', form);
                this.addPantry(items);
            });
        },

        addPantry(items) {
            const known = new Set(this.pantry.map((item) => item.name.toLowerCase()));
            this.pantry = [...this.pantry, ...items.filter((item) => !known.has(item.name.toLowerCase())).map((item) => ({ ...item, selected: true }))];
        },

        addPantryItem() {
            const name = this.newPantryItem.trim();
            if (name) this.addPantry([{ name, amount: null }]);
            this.newPantryItem = '';
        },

        // ---- 3. recipes

        offerPayload() {
            return this.selectedOffers.map(({ selected, ...offer }) => offer);
        },

        pantryPayload() {
            return this.selectedPantry.map(({ name, amount }) => ({ name, amount }));
        },

        /**
         * New suggestions replace the shown ones (the recipes picked for the list stay); with
         * `more` they are added to the shown ones, which are left out.
         */
        async suggest(more = false) {
            await this.run(more ? 'more' : 'suggest', this.$t('Looking for recipes …'), async () => {
                const kept = more ? this.suggestions : this.selectedSuggestions;
                const result = await api.post('/planner/suggestions', {
                    offers: this.offerPayload(),
                    pantry: this.pantryPayload(),
                    servings: Number(this.servings) || 4,
                    count: Number(this.count) || 5,
                    ideas: this.ideas,
                    wishes: this.wishes.trim() || null,
                    exclude: kept.length ? suggestionExclude(kept) : undefined,
                });
                const known = new Set(kept.map((suggestion) => suggestion.key));
                // Nothing is selected, the user picks. Not saved until "Save" or the list.
                const added = result.suggestions.filter((suggestion) => !known.has(suggestion.key)).map((suggestion) => ({
                    ...suggestion, selected: false, servings: Number(this.servings) || 4, savedUrl: null, saved: false,
                }));
                this.suggestions = more ? [...this.suggestions, ...added] : replaceSuggestions(this.suggestions, added);
                this.warning = more && !added.length ? this.$t('No further recipes found.') : result.warning;
                this.warningMore = more;
            });
        },

        /**
         * Saves a suggestion into the recipes (Recipes tab). It leaves the suggestions, unless it
         * is picked for the list: then it stays there, marked as saved.
         */
        async saveSuggestion(suggestion) {
            await this.run(`save:${suggestion.key}`, this.$t('Saving …'), async () => {
                await api.post('/planned-recipes', recipePayload(suggestion));
                Alpine.store('notice').show(this.$t('“:name” saved in the recipes.', { name: suggestion.name }));
                const remove = () => {
                    if (suggestion.selected) suggestion.saved = true;
                    else this.suggestions = this.suggestions.filter((each) => each.key !== suggestion.key);
                };
                if (this.detail?.key === suggestion.key && !suggestion.selected) closeDialogThen(this.$refs.recipeDialog, remove);
                else remove();
            });
        },

        openDetail(suggestion) {
            this.detail = suggestion;
            this.$nextTick(() => this.$refs.recipeDialog.showModal());
        },

        // Hands the idea to the recipe app as a draft and opens its import, where the user
        // matches foods, units and the category and picks cookbook and visibility.
        async saveIdea(suggestion) {
            // Open the tab while still in the click, otherwise browsers block it as a pop-up.
            const tab = navigator.onLine ? window.open('', '_blank') : null;
            await this.run(`idea:${suggestion.key}`, this.$t('Handing over …'), async () => {
                const { url } = await api.post('/planner/recipes', {
                    key: suggestion.key,
                    name: suggestion.name,
                    servings: suggestion.servings,
                    category: suggestion.category ?? null,
                    complexity: suggestion.complexity ?? null,
                    instructions: suggestion.instructions,
                    ingredients: suggestion.ingredients,
                });
                suggestion.savedUrl = url;
            });
            if (tab && suggestion.savedUrl) {
                tab.location.href = suggestion.savedUrl;
            } else {
                tab?.close();
            }
        },

        /** Saved recipe (Recipes tab): shown on top and selected (again). */
        async planRecipe(recipe) {
            // The planner may not have pulled yet (opened from the Recipes tab): otherwise this
            // newer change would replace the suggestions of another device.
            await this.pullState();
            const { id, draft_url: draftUrl, used_at: usedAt, created_at: createdAt, updated_at: updatedAt, ...suggestion } = recipe;
            const others = this.suggestions.filter((each) => each.key !== recipe.key);
            this.suggestions = [{
                uses_offers: [], uses_pantry: [], uses_season: [], ...suggestion, servings: recipe.servings || Number(this.servings) || 4, selected: true, savedUrl: draftUrl ?? null, saved: true,
            }, ...others];
            this.items = [];
        },

        // ---- 4. shopping list

        async buildList() {
            await this.run('list', this.$t('Creating the shopping list …'), async () => {
                const { items } = await api.post('/planner/list', {
                    offers: this.offerPayload(),
                    pantry: this.pantryPayload(),
                    // With all details: the server saves them (Recipes tab), the items link to them.
                    recipes: this.selectedSuggestions.map((suggestion) => recipePayload(suggestion)),
                });
                this.items = items.map((item) => ({ ...item, selected: !item.at_home }));
                this.selectedSuggestions.forEach((suggestion) => { suggestion.saved = true; });
                if (!this.listName) this.listName = this.$t('Weekly shopping :date', { date: new Date().toLocaleDateString(Alpine.store('i18n').locale.replace('_', '-')) });
            });
        },

        /**
         * Adds the selected items; asks first if some are already open on an existing list
         * (`merge` is the answer).
         */
        async addToList(merge = null) {
            // Recipes as names from the list step, linked if the suggestion has a URL (own recipes).
            const urls = new Map(this.suggestions.map((suggestion) => [suggestion.name.toLowerCase(), suggestion.url ?? null]));
            const items = this.selectedItems.map(({ name, amount, unit, store, note, recipes }) => ({
                name, amount, unit, note,
                stores: store ? [store] : [],
                recipes: (recipes ?? []).map((recipe) => ({ name: recipe, url: urls.get(recipe.toLowerCase()) ?? null })),
            }));
            if (!items.length) return;
            if (merge === null && this.target) {
                const count = merges(this.target, items);
                if (count) {
                    this.mergeCount = count;
                    return;
                }
            }
            this.mergeCount = null;
            const listId = await addItems(this.target, this.listName.trim() || this.$t('Shopping list'), items, Boolean(merge));
            this.reset();
            // Back from the new list leads to the lists, not into the finished planner.
            open(`#/list/${listId}`);
        },

        get lists() {
            return lists();
        },
    };
}
