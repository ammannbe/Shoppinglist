import Alpine from 'alpinejs';
import { createApi, HttpError } from './api.js';
import { prepareForAi } from './image.js';

// Week planner (#/planner): offers (screenshots, PDFs, text) and pantry photos are read by the
// AI on the server, recipes come from the connected recipe app and/or the AI, the result is
// added to a list. The state is kept in localStorage, so a reload (Android may kill the
// browser while the camera is open) does not lose the progress.

const STORAGE_KEY = 'shoppinglist.planner';
const api = createApi();
// The server accepts up to 20,000 characters per text; a copied offers page can be longer.
const TEXT_LIMIT = 18000;

// Splits the text into parts of at most `limit` characters, at line breaks where possible.
function splitText(text, limit) {
    const parts = [];
    let part = '';
    for (const line of text.split('\n')) {
        for (let start = 0; start < Math.max(line.length, 1); start += limit) {
            const piece = line.slice(start, start + limit);
            if (part && part.length + piece.length + 1 > limit) {
                parts.push(part);
                part = '';
            }
            part = part ? `${part}\n${piece}` : piece;
        }
    }
    if (part.trim()) parts.push(part);
    return parts;
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
 * @param {object} options
 * @param {(target: string, name: string, items: object[]) => Promise<string>} options.addItems adds the items, returns the list id
 * @param {() => object[]} options.lists lists to add to
 */
export function planner({ addItems, lists }) {
    return {
        ...initialState(),
        ...JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}'),
        offerText: '',
        newPantryItem: '',
        // Progress label and the button it belongs to (shows the spinner and the label).
        busy: null,
        busyKey: null,
        // Error and the button it belongs to (shown below it).
        error: null,
        errorKey: null,

        init() {
            const save = () => {
                const state = Object.fromEntries(Object.keys(initialState()).map((key) => [key, this[key]]));
                localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
            };
            Object.keys(initialState()).forEach((key) => this.$watch(key, save));
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

        /** Label of a button: the progress while its request runs. */
        label(key, text) {
            return this.busyKey === key ? this.busy : text;
        },

        /**
         * Runs a server request with a progress label and readable errors.
         */
        async run(key, label, action) {
            this.errorKey = key;
            if (!navigator.onLine) {
                this.error = this.$t('This needs an internet connection.');
                return;
            }
            this.busy = label;
            this.busyKey = key;
            this.error = null;
            try {
                await action();
            } catch (error) {
                this.error = error instanceof HttpError
                    ? (error.status === 429 ? this.$t('Too many requests. Please try again later.') : error.body?.message ?? error.message)
                    : this.$t('This needs an internet connection.');
            } finally {
                this.busy = null;
                this.busyKey = null;
            }
        },

        // ---- 1. offers

        async readOfferFiles(event) {
            const files = [...event.target.files];
            event.target.value = '';
            for (const [index, file] of files.entries()) {
                await this.run('offerFiles', this.$t('Reading offers :current/:total …', { current: index + 1, total: files.length }), async () => {
                    const form = new FormData();
                    form.append('file', await prepareForAi(file), file.name);
                    this.addOffers((await api.post('/planner/offers', form)).offers);
                });
                if (this.error) break;
            }
        },

        async readOfferText() {
            const text = this.offerText.trim();
            if (!text) return;
            await this.run('offerText', this.$t('Reading offers …'), async () => {
                const parts = splitText(text, TEXT_LIMIT);
                while (parts.length) {
                    this.addOffers((await api.post('/planner/offers', { text: parts[0] })).offers);
                    parts.shift();
                    // Keep only the unread parts, so a retry after an error does not read twice.
                    this.offerText = parts.join('\n');
                }
            });
        },

        addOffers(offers) {
            this.offers = [...this.offers, ...offers.map((offer) => ({ ...offer, selected: true }))];
        },

        removeOffer(index) {
            this.offers.splice(index, 1);
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
