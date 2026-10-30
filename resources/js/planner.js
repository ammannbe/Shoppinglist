import Alpine from 'alpinejs';
import { createApi } from './api.js';
import { prepareForAi } from './image.js';
import { offerReader } from './offers.js';
import { requests } from './requests.js';

// Week planner (#/planner): offers (screenshots, PDFs, text) and pantry photos are read by the
// AI on the server, recipes come from the connected recipe app and/or the AI, the result is
// added to a list. The state is kept in localStorage, so a reload (Android may kill the
// browser while the camera is open) does not lose the progress.

const STORAGE_KEY = 'shoppinglist.planner';
const api = createApi();
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
 * @param {object} options
 * @param {(target: string, name: string, items: object[]) => Promise<string>} options.addItems adds the items, returns the list id
 * @param {() => object[]} options.lists lists to add to
 */
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

export function planner({ addItems, lists }) {
    return {
        ...initialState(),
        ...JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}'),
        offerText: '',
        newPantryItem: '',
        // Saved offers (pool) not taken yet, grouped by shop to take them per shop.
        savedOffers: null,
        ...requests(),
        ...offerReader(),

        init() {
            const save = () => {
                const state = Object.fromEntries(Object.keys(initialState()).map((key) => [key, this[key]]));
                localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
            };
            Object.keys(initialState()).forEach((key) => this.$watch(key, save));

            // Saved offers are shown right away when the planner is opened (and the account known).
            const load = () => {
                if (location.hash.startsWith('#/planner') && Alpine.store('sync').user?.verified && navigator.onLine && !this.busy) {
                    this.loadSavedOffers();
                }
            };
            window.addEventListener('hashchange', load);
            // A microtask, so only the user is tracked by the effect.
            Alpine.effect(() => Alpine.store('sync').user && queueMicrotask(load));
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
         * New suggestions; with `more` they are added to the shown ones, which are left out.
         */
        async suggest(more = false) {
            await this.run(more ? 'more' : 'suggest', this.$t('Looking for recipes …'), async () => {
                const result = await api.post('/planner/suggestions', {
                    offers: this.offerPayload(),
                    pantry: this.pantryPayload(),
                    servings: Number(this.servings) || 4,
                    count: Number(this.count) || 5,
                    ideas: this.ideas,
                    wishes: this.wishes.trim() || null,
                    exclude: more ? {
                        recipe_ids: this.suggestions.map((suggestion) => suggestion.recipe_id).filter(Boolean),
                        names: this.suggestions.map((suggestion) => suggestion.name),
                    } : undefined,
                });
                const known = new Set(more ? this.suggestions.map((suggestion) => suggestion.key) : []);
                const added = result.suggestions.filter((suggestion) => !known.has(suggestion.key)).map((suggestion) => ({
                    ...suggestion, selected: !more, servings: Number(this.servings) || 4, savedUrl: null,
                }));
                this.suggestions = more ? [...this.suggestions, ...added] : added;
                this.warning = more && !added.length ? this.$t('No further recipes found.') : result.warning;
                this.warningMore = more;
                if (!more) this.items = [];
            });
        },

        // Hands the idea to the recipe app as a draft and opens its import, where the user
        // matches foods, units and the category and picks cookbook and visibility.
        async saveIdea(suggestion) {
            // Open the tab while still in the click, otherwise browsers block it as a pop-up.
            const tab = navigator.onLine ? window.open('', '_blank') : null;
            await this.run(`idea:${suggestion.key}`, this.$t('Handing over …'), async () => {
                const { url } = await api.post('/planner/recipes', {
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

        // ---- 4. shopping list

        async buildList() {
            await this.run('list', this.$t('Creating the shopping list …'), async () => {
                const { items } = await api.post('/planner/list', {
                    offers: this.offerPayload(),
                    pantry: this.pantryPayload(),
                    recipes: this.selectedSuggestions.map((suggestion) => ({
                        source: suggestion.source,
                        recipe_id: suggestion.recipe_id ?? null,
                        name: suggestion.name,
                        servings: Number(suggestion.servings) || 1,
                        ingredients: suggestion.source === 'ai' ? suggestion.ingredients : undefined,
                    })),
                });
                this.items = items.map((item) => ({ ...item, selected: !item.at_home }));
                if (!this.listName) this.listName = this.$t('Weekly shopping :date', { date: new Date().toLocaleDateString(Alpine.store('i18n').locale.replace('_', '-')) });
            });
        },

        async addToList() {
            // Recipes as names from the list step, linked if the suggestion has a URL (own recipes).
            const urls = new Map(this.suggestions.map((suggestion) => [suggestion.name.toLowerCase(), suggestion.url ?? null]));
            const items = this.selectedItems.map(({ name, amount, unit, store, note, recipes }) => ({
                name, amount, unit, note,
                stores: store ? [store] : [],
                recipes: (recipes ?? []).map((recipe) => ({ name: recipe, url: urls.get(recipe.toLowerCase()) ?? null })),
            }));
            if (!items.length) return;
            const listId = await addItems(this.target, this.listName.trim() || this.$t('Shopping list'), items);
            this.reset();
            location.hash = `#/list/${listId}`;
        },

        get lists() {
            return lists();
        },
    };
}
