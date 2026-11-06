import Alpine from 'alpinejs';
import { createApi, HttpError } from './api.js';
import { prepareForAi } from './image.js';
import { offerReader } from './offers.js';
import { closeDialogThen } from './layers.js';
import { shortDate } from './helpers.js';
import {
    allowedStep, builtRecipe, migrateState, plannerSelection, recipePayload, remoteIsNewer, selectedOfferIds, STEPS, stepAllowed, stepTrail,
    suggestionExclude, SYNCED_KEYS, syncedState, withoutPlanned,
} from './planner-state.js';
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
const PUSH_DELAY = 1000;
const api = createApi();

/** Logout or another account: the planner state of this device is removed with the local data. */
export function clearPlannerState() {
    [STORAGE_KEY, SYNC_KEY, STEP_KEY, PLANNED_KEY].forEach((key) => localStorage.removeItem(key));
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
 */
export function planner({ addItems, merges, lists, open, navigate }) {
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
        // Offers of the pool (GET /offers), null until loaded.
        savedOffers: null,
        // Planned recipes (saved, not on a list yet), the list is built from them.
        planned: readJson(PLANNED_KEY, []),
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

            return this.nextStep === 'recipes' ? this.$t('Select offers or add what is at home first.') : this.$t('Plan at least one recipe.');
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

        /** Offers of the pool by shop (no shop last). */
        get offerGroups() {
            return groupByStore(this.savedOffers ?? []);
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

        // ---- planned recipes (saved, planned_recipes; the list is built from them)

        /** Planned recipes of the server (other devices, the Recipes tab). */
        async loadPlanned() {
            try {
                this.setPlanned((await api.get('/planned-recipes')).recipes.filter((recipe) => recipe.planned));
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

        async buildList() {
            const built = [...this.planned];
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
                // Saved recipe of each one: the items link to it (recipes[].key). They are on a list now, no longer planned.
                const ids = new Map(recipes.map(({ key, id }) => [key, id]));
                this.builtRecipes = [...this.builtRecipes, ...built.map((recipe) => builtRecipe(recipe, ids.get(recipe.key) ?? recipe.id))];
                const done = new Set(built.map((recipe) => recipe.id));
                this.setPlanned(this.planned.filter((recipe) => !done.has(recipe.id)));
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
            this.reset();
            // Back from the new list leads to the lists, not into the finished planner.
            open(`#/list/${listId}`);
        },

        get lists() {
            return lists();
        },
    };
}
