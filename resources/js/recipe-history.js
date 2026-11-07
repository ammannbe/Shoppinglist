import Alpine from 'alpinejs';
import { createApi } from './api.js';
import { shortDate } from './helpers.js';
import { closeDialogThen } from './layers.js';
import { currentSelection, sendUsedRecipes } from './planner.js';
import { linkedItems, recipeProgress, unavailableRecipe } from './recipe-links.js';
import { requests } from './requests.js';

// Recipes tab (#/recipes, PlannedRecipeController): planner suggestions the user planned or put
// on a shopping list, in two groups: planned (the planner builds the next list from them) and on
// a list / earlier. A tap opens the details in a dialog: cook one again (ingredients and
// preparation of AI ideas are kept), plan it again (matched with the planner's current offers
// and pantry), take it out of the plan or import an AI idea into the recipe app afterwards. Loading needs a connection, the last loaded recipes
// are kept on the device; the items linked to a recipe come from the local database.
// `#/recipes/<id>` (recipe links of items) opens the dialog of that recipe.

const api = createApi();
// Last loaded recipes (offline), removed with the local data.
const CACHE_KEY = 'shoppinglist.recipes';

/** A saved recipe was planned again or taken out of the plan (planner.js listens). */
function plannedChanged(recipe) {
    window.dispatchEvent(new CustomEvent('planned-recipe', { detail: recipe }));
}

export function clearRecipeCache() {
    localStorage.removeItem(CACHE_KEY);
}

function cachedRecipes() {
    try {
        return JSON.parse(localStorage.getItem(CACHE_KEY) ?? 'null');
    } catch {
        return null;
    }
}

/**
 * @param {object} options
 * @param {(hash: string) => void} options.open opens another tab or a list (shoppinglist.openTab)
 * @param {(hash: string) => void} options.replace replaces the current history entry
 */
export function recipeHistory({ open: openTab, replace }) {
    const cached = cachedRecipes();

    return {
        ...requests(),
        recipes: cached ?? [],
        loaded: cached !== null,
        // Recipe shown in the dialog (all details and actions).
        detail: null,
        // Recipe to open once the recipes are loaded (#/recipes/<id>).
        pending: null,

        init() {
            const opened = () => {
                const [, view, key] = location.hash.split('/');
                if (view !== 'recipes') return;
                if (key) {
                    // The dialog gets its own history entry on #/recipes: back closes it, then leaves the tab.
                    this.pending = decodeURIComponent(key);
                    replace('#/recipes');
                    return;
                }
                this.load();
            };
            window.addEventListener('hashchange', opened);
            // Recipes the planner changed (e.g. put on a list after adding its items).
            window.addEventListener('planned-recipe', (event) => {
                this.store(this.recipes.map((each) => (each.id === event.detail.id ? event.detail : each)));
            });
            opened();
        },

        async load() {
            await this.run('load', this.$t('Loading recipes …'), async () => {
                // Recipes put on a list while offline are marked first.
                await sendUsedRecipes();
                this.store((await api.get('/planned-recipes')).recipes);
                this.loaded = true;
            });
            if (this.pending) {
                const key = this.pending;
                this.pending = null;
                await this.openKey(key);
            }
        },

        /** Keeps the recipes for offline use (as far as there is space). */
        store(recipes) {
            this.recipes = recipes;
            try {
                localStorage.setItem(CACHE_KEY, JSON.stringify(recipes));
            } catch {
                localStorage.removeItem(CACHE_KEY);
            }
        },

        /**
         * Recipe of an item link: an own one, one of another member of a shared list (read only),
         * otherwise the name and link the items have (removed, or not available).
         */
        async openKey(key) {
            let recipe = this.recipes.find((each) => each.id === key);
            if (!recipe && navigator.onLine) {
                try {
                    recipe = (await api.get(`/planned-recipes/${encodeURIComponent(key)}`)).recipe;
                } catch {
                    // Removed or not shared: shown from the items below.
                }
            }
            recipe ??= unavailableRecipe(Alpine.store('data').items, key);
            if (recipe) this.openDetail(recipe);
            else Alpine.store('notice').show(this.$t('This recipe is not saved anymore.'));
        },

        openDetail(recipe) {
            this.detail = recipe;
            this.$nextTick(() => this.$refs.recipeDialog.showModal());
        },

        /** Items on the device linked to the recipe, with their list (open ones first). */
        linked(recipe) {
            const undo = Alpine.store('undo');
            const items = Alpine.store('data').items.filter((item) => !undo.hidden(item.id));
            const lists = Alpine.store('data').lists.filter((list) => !undo.hidden(list.id));

            return linkedItems(items, lists, recipe?.id);
        },

        progress(recipe) {
            return recipeProgress(this.linked(recipe));
        },

        /** A list of a linked item; the dialog's history entry has to be gone before navigating. */
        openList(listId) {
            closeDialogThen(this.$refs.recipeDialog, () => openTab(`#/list/${listId}`));
        },

        get plannedRecipes() {
            return this.recipes.filter((recipe) => recipe.planned);
        },

        get earlierRecipes() {
            return this.recipes.filter((recipe) => !recipe.planned);
        },

        /** Replaces a recipe with the server's answer (and tells the planner). */
        updated(recipe) {
            this.store(this.recipes.map((each) => (each.id === recipe.id ? recipe : each)));
            plannedChanged(recipe);
        },

        /**
         * Plans it again with the planner's current offers and pantry, then opens the planner's
         * recipes; the dialog's history entry has to be gone before navigating.
         */
        async plan(recipe) {
            await this.run(`plan:${recipe.id}`, this.$t('Planning …'), async () => {
                const { recipe: planned } = await api.post(`/planned-recipes/${recipe.id}/plan`, currentSelection());
                this.updated(planned);
                closeDialogThen(this.$refs.recipeDialog, () => openTab('#/planner/recipes'));
            });
        },

        async unplan(recipe) {
            await this.run(`unplan:${recipe.id}`, this.$t('Removing …'), async () => {
                const { recipe: saved } = await api.delete(`/planned-recipes/${recipe.id}/plan`);
                this.updated(saved);
                if (this.detail?.id === saved.id) this.detail = saved;
            });
        },

        async remove(recipe) {
            await this.run(`remove:${recipe.id}`, this.$t('Removing …'), async () => {
                await api.delete(`/planned-recipes/${recipe.id}`);
                closeDialogThen(this.$refs.recipeDialog, () => {
                    this.store(this.recipes.filter((each) => each.id !== recipe.id));
                    if (recipe.planned) plannedChanged({ ...recipe, planned: false });
                });
            });
        },

        async clear() {
            if (!confirm(this.$t('Remove all recipes from the history?'))) return;
            await this.run('clear', this.$t('Removing …'), async () => {
                await api.delete('/planned-recipes');
                this.plannedRecipes.forEach((recipe) => plannedChanged({ ...recipe, planned: false }));
                this.store([]);
            });
        },

        // Like the planner: hands the AI idea to the recipe app as a draft and opens its import.
        async importIdea(recipe) {
            const tab = navigator.onLine ? window.open('', '_blank') : null;
            await this.run(`idea:${recipe.id}`, this.$t('Handing over …'), async () => {
                const { url } = await api.post('/planner/recipes', {
                    key: recipe.key,
                    name: recipe.name,
                    servings: recipe.servings || 4,
                    category: recipe.category ?? null,
                    complexity: recipe.complexity ?? null,
                    instructions: recipe.instructions,
                    ingredients: recipe.ingredients,
                });
                recipe.draft_url = url;
            });
            if (tab && recipe.draft_url) {
                tab.location.href = recipe.draft_url;
            } else {
                tab?.close();
            }
        },

        shortDate(value) {
            return shortDate(value, Alpine.store('i18n').locale);
        },

        date(value) {
            return value ? new Date(value).toLocaleDateString(Alpine.store('i18n').locale.replace('_', '-')) : '';
        },
    };
}
