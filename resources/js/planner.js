import Alpine from 'alpinejs';
import { createApi, HttpError } from './api.js';
import { prepareForAi } from './image.js';
import { offerReader } from './offers.js';
import { closeDialogThen } from './layers.js';
import { offerMatches, shortDate, uuid7 } from './helpers.js';
import {
    allowedStep, builtRecipe, migrateState, plannerSelection, queueUsed, recipePayload, remoteIsNewer, selectedOfferIds, STEPS, stepAllowed, stepTrail,
    suggestionExclude, SYNCED_KEYS, syncedState, unbuiltRecipes, withoutPlanned, withoutUsed,
} from './planner-state.js';
import { mergePantry, pantryEntries } from './pantry.js';
import { listRecipes } from './recipe-links.js';
import { requests } from './requests.js';

// Week planner (#/planner/<step>): offers (screenshots, PDFs, text) and pantry photos are read
// by the AI on the server, recipes come from the connected recipe app and/or the AI; planning a
// suggestion saves it (planned_recipes), the shopping list is built from all planned recipes.
// Four steps (planner-state.js STEPS), each its own history entry.
// The state is kept in localStorage, so a reload (Android may kill the browser while the camera
// is open) does not lose the progress, and on the server (PlannerStateController) to continue
// on another device: last write wins by the time of the change.

const STORAGE_KEY = 'shoppinglist.planner';
// { updated_at: ms of the last change, pending: not sent to the server yet }
const SYNC_KEY = 'shoppinglist.planner-sync';
// Last step opened (device only), #/planner opens it.
const STEP_KEY = 'shoppinglist.planner-step';
const OWNER_KEY = 'shoppinglist.owner';
// Planned recipes as last loaded (the steps can be opened before they are loaded again).
const PLANNED_KEY = 'shoppinglist.planned';
// Recipes whose items were added to a list, "used" not sent yet ([{ ids, added_at }], device only).
const USED_KEY = 'shoppinglist.planned-used';
const PUSH_DELAY = 1000;
const api = createApi();

/** Logout or another account: the planner state of this device is removed with the local data. */
export function clearPlannerState() {
    [STORAGE_KEY, SYNC_KEY, STEP_KEY, PLANNED_KEY, USED_KEY].forEach((key) => localStorage.removeItem(key));
}

let sendingUsed = null;

/**
 * Marks the recipes of added items as on a list (POST /planned-recipes/used). Queued first, so
 * offline or after a failure it is sent again: when the planner or the Recipes tab is opened,
 * on foreground and when online again. The changed recipes go out as `planned-recipe` events.
 */
export function sendUsedRecipes() {
    sendingUsed ??= (async () => {
        while (navigator.onLine) {
            const [entry] = readJson(USED_KEY, []);
            if (!entry) break;
            try {
                const { recipes } = await api.post('/planned-recipes/used', entry);
                recipes.forEach((recipe) => window.dispatchEvent(new CustomEvent('planned-recipe', { detail: recipe })));
            } catch (error) {
                // Invalid (e.g. a recipe without saved id): retrying does not help. Otherwise later.
                if (!(error instanceof HttpError && error.status === 422)) break;
            }
            const rest = readJson(USED_KEY, []).filter((each) => JSON.stringify(each) !== JSON.stringify(entry));
            if (rest.length) localStorage.setItem(USED_KEY, JSON.stringify(rest));
            else localStorage.removeItem(USED_KEY);
        }
    })().finally(() => {
        // Asynchronous also when there was nothing to send (a `finally` block would run before the assignment).
        sendingUsed = null;
    });

    return sendingUsed;
}

function readJson(key, fallback) {
    try {
        return JSON.parse(localStorage.getItem(key) ?? 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

/** Selected offers and pantry items of the planner (a recipe planned again is matched with them). */
export function currentSelection() {
    return plannerSelection(readJson(STORAGE_KEY, {}));
}

function readSyncMeta(stored) {
    const meta = JSON.parse(localStorage.getItem(SYNC_KEY) ?? 'null');
    if (meta) return meta;
    // State of a version without server sync: sent once, unless the server has one already.
    const started = Boolean(stored.offerIds?.length || stored.pantry?.length || stored.suggestions?.length);

    return { updated_at: started ? 1 : 0, pending: started };
}
function initialState() {
    return {
        // Selected offers of the pool (ids); offers not in it are not selected.
        offerIds: [],
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
        // Recipes the items were built from, { name, url, key } (key: saved recipe).
        builtRecipes: [],
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
 * @param {() => object[]} options.pantries pantry lists (step "At home")
 * @param {(listId: string) => object[]} options.pantryItems items of a list
 * @param {(item: object) => string} options.amountText formatted amount and unit of an item
 * @param {(target: string, entries: object[]) => Promise<number>} options.savePantry saves entries into a pantry list ('' = new one)
 */
export function planner({ addItems, merges, lists, open, navigate, pantries = () => [], pantryItems = () => [], amountText = () => '', savePantry = async () => 0 }) {
    const stored = migrateState(readJson(STORAGE_KEY, {}));
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
        // Pantry list to choose in step 2 (device only): 'load' ("Take from pantry") or 'save' ("Save to pantry"), null: closed.
        pantryPick: null,
        // Offers of the pool (GET /offers), null until loaded.
        savedOffers: null,
        // Search in step 1 (device only, not synced): filters the offers by name and brand.
        offerSearch: '',
        // Planned recipes (saved, not on a list yet), the list is built from them.
        planned: readJson(PLANNED_KEY, []),
        // Ingredients already open on the target list, asked before adding (null: not asked).
        mergeCount: null,
        // Suggestion shown in the recipe dialog (all details and actions).
        detail: null,
        // Adding an own recipe in step 3 (device only, not synced): the typed name, the recipes
        // of the recipe app found for it (POST /planner/recipe-search) and the query they belong to.
        ownQuery: '',
        ownResults: [],
        ownSearched: '',
        ownBusy: false,
        ownError: null,
        ownTimer: null,
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
            // A recipe of the Recipes tab planned again or taken out of the plan.
            window.addEventListener('planned-recipe', (event) => this.updatePlanned(event.detail));

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
                if (Alpine.store('sync').user?.verified && navigator.onLine) this.loadPlanned();
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
                if (localStorage.getItem(USED_KEY)) sendUsedRecipes();
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
            const step = allowedStep(requested ?? localStorage.getItem(STEP_KEY) ?? STEPS[0], this.gate);
            if (step !== requested) {
                navigate(`#/planner/${step}`, { replace: true });
                return false;
            }
            this.step = step;
            localStorage.setItem(STEP_KEY, step);

            return true;
        },

        canOpen(step) {
            return stepAllowed(step, this.gate);
        },

        /** What the steps depend on (planner-state.js stepAllowed). */
        get gate() {
            return { offerIds: this.selectedIds, pantry: this.pantry, planned: this.planned, items: this.items };
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

            return this.$t('Plan at least one recipe.');
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
            // Planned recipes stay planned (they are saved, step 3 and 4 show them).
            if (!confirm(this.$t(this.planned.length ? 'Start over? The planned recipes stay planned.' : 'Start over?'))) return;
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
            const migrated = migrateState(values);
            const state = Object.fromEntries(SYNCED_KEYS.map((key) => [key, migrated[key] ?? defaults[key]]));
            syncedJson = JSON.stringify(syncedState(state));
            Object.assign(this, state);
            this.mergeCount = null;
            this.setSyncMeta({ updated_at: remote.updated_at, pending: false });
            // The current step may not be possible anymore (e.g. the offers were removed there).
            if (this.step && !this.canOpen(this.step)) this.goStep(allowedStep(this.step, this.gate));
        },

        get recipes() {
            return Alpine.store('sync').user?.recipes ?? null;
        },

        /** Selected offer ids still in the pool (expired or removed ones drop out). */
        get selectedIds() {
            return selectedOfferIds(this.offerIds, this.savedOffers);
        },

        get selectedOffers() {
            const ids = new Set(this.selectedIds);

            return (this.savedOffers ?? []).filter((offer) => ids.has(offer.id));
        },

        get selectedPantry() {
            return this.pantry.filter((item) => item.selected);
        },

        get selectedItems() {
            return this.items.filter((item) => item.selected);
        },

        reset() {
            Object.assign(this, initialState());
            this.error = null;
            this.errorKey = null;
        },


        // ---- 1. offers: all offers of the pool by shop, ticked ones are used

        /** Offers read in the planner: into the pool and selected. */
        addOffers(offers) {
            const known = new Set((this.savedOffers ?? []).map((offer) => offer.id));
            this.savedOffers = [...(this.savedOffers ?? []), ...offers.filter((offer) => !known.has(offer.id))];
            this.selectOffers(offers, true);
        },

        isSelected(offer) {
            return this.offerIds.includes(offer.id);
        },

        /** (De)selects offers; ids no longer in the pool are dropped with it. */
        selectOffers(offers, selected) {
            const ids = new Set(this.selectedIds);
            offers.forEach((offer) => (selected ? ids.add(offer.id) : ids.delete(offer.id)));
            this.offerIds = [...ids];
        },

        get searchingOffers() {
            return this.offerSearch.trim() !== '';
        },

        /**
         * Offers of the pool by shop (no shop last); while searching only the matching ones, so
         * "All"/"None" of a shop apply to the shown offers.
         */
        get offerGroups() {
            return groupByStore((this.savedOffers ?? []).filter((offer) => offerMatches(offer, this.offerSearch)));
        },

        selectedIn(group) {
            return group.offers.filter((offer) => this.isSelected(offer)).length;
        },

        /** Offers of the pool (#/offers): own and shared, not expired. */
        async loadSavedOffers() {
            await this.run('saved', this.$t('Loading offers …'), async () => {
                this.savedOffers = (await api.get('/offers')).offers;
            });
        },

        clearPantry() {
            this.pantry = [];
        },

        /** "until 4.11." for a group: the latest end date of its offers. */
        groupValidity(offers) {
            const last = offers.map((offer) => offer.valid_until).filter(Boolean).sort().at(-1);

            return last ? this.$t('until :date', { date: this.shortDate(last) }) : null;
        },

        shortDate(value) {
            return shortDate(value, Alpine.store('i18n').locale);
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
            this.pantry = mergePantry(this.pantry, items);
        },

        /** Pantry lists of the user (RxDB, shared ones too). */
        get pantrySources() {
            return pantries();
        },

        /**
         * "Take from pantry": the open (not used up) items of a pantry list, selected; with several
         * pantry lists the user picks one first.
         */
        loadFromPantry(listId = null) {
            const sources = this.pantrySources;
            const list = listId ? sources.find((each) => each.id === listId) : sources.length === 1 ? sources[0] : null;
            if (!list) {
                this.pantryPick = 'load';
                return;
            }
            this.pantryPick = null;
            const before = this.pantry.length;
            this.addPantry(pantryEntries(pantryItems(list.id), amountText));
            Alpine.store('notice').show(this.$t(':count taken from “:list”', { count: this.pantry.length - before, list: list.name }));
        },

        /** "Save to pantry": the selected entries into a pantry list (picked, or a new one); names open there are skipped. */
        async saveToPantry(listId = null) {
            const sources = this.pantrySources;
            if (listId === null && sources.length !== 1) {
                this.pantryPick = 'save';
                return;
            }
            this.pantryPick = null;
            await savePantry(listId ?? sources[0].id, this.selectedPantry.map(({ name, amount }) => ({ name, amount })));
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
         * New suggestions replace the shown ones (planning saves the ones to keep); with `more`
         * they are added to the shown ones, which are left out. Planned recipes are left out.
         */
        async suggest(more = false) {
            await this.run(more ? 'more' : 'suggest', this.$t('Looking for recipes …'), async () => {
                const kept = more ? this.suggestions : [];
                const excluded = [...this.planned, ...kept];
                const result = await api.post('/planner/suggestions', {
                    offers: this.offerPayload(),
                    pantry: this.pantryPayload(),
                    servings: Number(this.servings) || 4,
                    count: Number(this.count) || 5,
                    ideas: this.ideas,
                    wishes: this.wishes.trim() || null,
                    exclude: excluded.length ? suggestionExclude(excluded) : undefined,
                });
                const known = new Set(kept.map((suggestion) => suggestion.key));
                // Not saved until planned (or imported into the recipe app).
                const added = withoutPlanned(result.suggestions, this.planned).filter((suggestion) => !known.has(suggestion.key)).map((suggestion) => ({
                    ...suggestion, servings: Number(this.servings) || 4, savedUrl: null,
                }));
                this.suggestions = [...kept, ...added];
                this.warning = more && !added.length ? this.$t('No further recipes found.') : result.warning;
                this.warningMore = more;
            });
        },

        // ---- own recipes (step 3): a recipe of the recipe app found by name, or a typed dish

        /** Searches the recipe app while typing (debounced); without a connection only the typed dish is offered. */
        searchOwn() {
            clearTimeout(this.ownTimer);
            const query = this.ownQuery.trim();
            if (query.length < 2 || !this.recipes?.connected) {
                this.ownResults = [];
                this.ownSearched = '';
                this.ownError = null;
                return;
            }
            // Not through run(): typing must not block the other buttons (or replace their progress).
            this.ownTimer = setTimeout(async () => {
                this.ownBusy = true;
                this.ownError = null;
                try {
                    const { recipes } = await api.post('/planner/recipe-search', { query });
                    // A slower answer of an older query must not replace the current one.
                    if (this.ownQuery.trim() !== query) return;
                    this.ownResults = recipes;
                    this.ownSearched = query;
                } catch (error) {
                    this.ownError = error instanceof HttpError ? (error.body?.message ?? error.message) : this.$t('This needs an internet connection.');
                } finally {
                    this.ownBusy = false;
                }
            }, 350);
        },

        /** Found recipes that are not planned yet. */
        get ownFound() {
            return withoutPlanned(this.ownResults, this.planned);
        },

        /** Plans a recipe of the recipe app found by name. */
        async planOwn(recipe) {
            await this.plan({ uses_offers: [], uses_pantry: [], uses_season: [], ...recipe, servings: Number(this.servings) || 4 });
        },

        /** Plans the typed name as own dish: the AI adds the usual ingredients when the list is built. */
        async planCustom() {
            const name = this.ownQuery.trim();
            if (!name) return;
            const key = `custom-${uuid7()}`;
            await this.plan({ key, source: 'custom', name, servings: Number(this.servings) || 4, uses_offers: [], uses_pantry: [], uses_season: [] });
            if (this.planned.some((recipe) => recipe.key === key)) {
                this.ownQuery = '';
                this.ownResults = [];
                this.ownSearched = '';
            }
        },

        // ---- planned recipes (saved, planned_recipes; the list is built from them)

        /** Planned recipes of the server (other devices, the Recipes tab); used marks not sent yet go first. */
        async loadPlanned() {
            await sendUsedRecipes();
            try {
                const planned = (await api.get('/planned-recipes')).recipes.filter((recipe) => recipe.planned);
                this.setPlanned(withoutUsed(planned, readJson(USED_KEY, [])));
            } catch {
                // Offline or server error: the last loaded ones stay.
            }
        },

        setPlanned(recipes) {
            // Oldest first: the order they were planned in.
            this.planned = [...recipes].sort((a, b) => String(a.planned_at ?? '').localeCompare(String(b.planned_at ?? '')));
            try {
                localStorage.setItem(PLANNED_KEY, JSON.stringify(this.planned));
            } catch {
                localStorage.removeItem(PLANNED_KEY);
            }
        },

        /** A saved recipe changed (planned or not): updates the planned ones and the suggestions. */
        updatePlanned(recipe) {
            const others = this.planned.filter((each) => each.id !== recipe.id);
            this.setPlanned(recipe.planned ? [...others, recipe] : others);
            if (recipe.planned) this.suggestions = withoutPlanned(this.suggestions, [recipe]);
        },

        /** Plans a suggestion: saved into the recipes (Recipes tab), it leaves the suggestions. */
        async plan(suggestion) {
            await this.run(`plan:${suggestion.key}`, this.$t('Planning …'), async () => {
                const { recipe } = await api.post('/planned-recipes', recipePayload(suggestion));
                const done = () => this.updatePlanned({ ...recipe, draft_url: recipe.draft_url ?? suggestion.savedUrl ?? null });
                if (this.detail?.key === suggestion.key) closeDialogThen(this.$refs.recipeDialog, done);
                else done();
            });
        },

        /** Takes a recipe out of the plan (stays saved); in step 3 it is offered as suggestion again. */
        async unplan(recipe) {
            await this.run(`unplan:${recipe.id}`, this.$t('Removing …'), async () => {
                const { recipe: saved } = await api.delete(`/planned-recipes/${recipe.id}/plan`);
                const done = () => {
                    this.updatePlanned(saved);
                    if (this.step === 'recipes' && !this.suggestions.some((each) => each.key === saved.key)) {
                        const { id, draft_url: draftUrl, planned, planned_at: plannedAt, used_at: usedAt, created_at: createdAt, updated_at: updatedAt, ...suggestion } = saved;
                        this.suggestions = [{ uses_offers: [], uses_pantry: [], uses_season: [], ...suggestion, servings: saved.servings || Number(this.servings) || 4, savedUrl: draftUrl ?? null }, ...this.suggestions];
                    }
                    if (this.step && !this.canOpen(this.step)) this.goStep(allowedStep(this.step, this.gate));
                };
                if (this.detail?.id === recipe.id) closeDialogThen(this.$refs.recipeDialog, done);
                else done();
            });
        },

        openDetail(recipe) {
            this.detail = recipe;
            this.$nextTick(() => this.$refs.recipeDialog.showModal());
        },

        // Hands the idea to the recipe app as a draft and opens its import, where the user
        // matches foods, units and the category and picks cookbook and visibility.
        async saveIdea(suggestion) {
            // Open the tab while still in the click, otherwise browsers block it as a pop-up.
            const tab = navigator.onLine ? window.open('', '_blank') : null;
            let url = null;
            await this.run(`idea:${suggestion.key}`, this.$t('Handing over …'), async () => {
                // With all details: an idea not saved yet is saved first (Recipes tab keeps the import link).
                ({ url } = await api.post('/planner/recipes', {
                    ...recipePayload(suggestion),
                    servings: Number(suggestion.servings) || 4,
                    category: suggestion.category ?? null,
                    complexity: suggestion.complexity ?? null,
                }));
                if (suggestion.id) suggestion.draft_url = url;
                else suggestion.savedUrl = url;
            });
            if (tab && url) {
                tab.location.href = url;
            } else {
                tab?.close();
            }
        },

        // ---- 4. shopping list

        /** Planned recipes no items were built from yet (they stay planned until the items are added). */
        get unbuilt() {
            return unbuiltRecipes(this.planned, this.builtRecipes);
        },

        /** Whether items were built from the recipe (shown at the planned recipes). */
        isBuilt(recipe) {
            return this.builtRecipes.some((built) => built.key === recipe.id);
        },

        async buildList() {
            const built = [...this.unbuilt];
            if (!built.length) return;
            await this.run('list', this.$t('Creating the shopping list …'), async () => {
                const { items, recipes = [] } = await api.post('/planner/list', {
                    offers: this.offerPayload(),
                    pantry: this.pantryPayload(),
                    // With all details: the server saves them (Recipes tab), the items link to them.
                    recipes: built.map((recipe) => recipePayload({ ...recipe, servings: recipe.servings || Number(this.servings) || 4 })),
                });
                // A list built before (not added yet) gets the new items.
                this.items = [...this.items, ...items.map((item) => ({ ...item, selected: !item.at_home }))];
                // Saved recipe of each one: the items link to it (recipes[].key). They stay planned
                // until the items are added (addToList), cancelling keeps them in the plan.
                const ids = new Map(recipes.map(({ key, id }) => [key, id]));
                this.builtRecipes = [...this.builtRecipes, ...built.map((recipe) => builtRecipe(recipe, ids.get(recipe.key) ?? recipe.id))];
                if (!this.listName) this.listName = this.$t('Weekly shopping :date', { date: new Date().toLocaleDateString(Alpine.store('i18n').locale.replace('_', '-')) });
            });
        },

        /**
         * Adds the selected items; asks first if some are already open on an existing list
         * (`merge` is the answer).
         */
        async addToList(merge = null) {
            // Recipes as names from the list step, linked to the saved recipe and the recipe app (own recipes).
            const items = this.selectedItems.map(({ name, amount, unit, store, note, recipes }) => ({
                name, amount, unit, note,
                stores: store ? [store] : [],
                recipes: listRecipes(recipes, this.builtRecipes),
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
            // The recipes are on a list now: no longer planned here at once, the server is told
            // (queued, sent again if offline or failed).
            const queue = queueUsed(readJson(USED_KEY, []), this.builtRecipes, Date.now());
            if (queue.length) localStorage.setItem(USED_KEY, JSON.stringify(queue));
            this.setPlanned(withoutUsed(this.planned, queue));
            this.reset();
            sendUsedRecipes();
            // Back from the new list leads to the lists, not into the finished planner.
            open(`#/list/${listId}`);
        },

        get lists() {
            return lists();
        },
    };
}
