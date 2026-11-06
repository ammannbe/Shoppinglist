import Alpine from 'alpinejs';
import { createApi } from './api.js';
import { closeDialogThen } from './layers.js';
import { requests } from './requests.js';

// Recipes tab (#/recipes, PlannedRecipeController): planner suggestions the user saved or put on
// a shopping list. A tap opens the details in a dialog: cook one again (ingredients and
// preparation of AI ideas are kept), plan it again (adds it to the planner's recipes) or import
// an AI idea into the recipe app afterwards. Needs a connection.

const api = createApi();

/** Takes a saved recipe into the week planner (planner.js listens). */
export function planRecipe(recipe) {
    window.dispatchEvent(new CustomEvent('plan-recipe', { detail: recipe }));
}

/**
 * @param {object} options
 * @param {(hash: string) => void} options.open opens another tab (shoppinglist.openTab)
 */
export function recipeHistory({ open: openTab }) {
    return {
        ...requests(),
        recipes: [],
        loaded: false,
        // Recipe shown in the dialog (all details and actions).
        detail: null,

        init() {
            const opened = () => location.hash.startsWith('#/recipes') && this.load();
            window.addEventListener('hashchange', opened);
            opened();
        },

        async load() {
            await this.run('load', this.$t('Loading recipes …'), async () => {
                this.recipes = (await api.get('/planned-recipes')).recipes;
                this.loaded = true;
            });
        },

        openDetail(recipe) {
            this.detail = recipe;
            this.$nextTick(() => this.$refs.recipeDialog.showModal());
        },

        /** Into the planner's recipes; the dialog's history entry has to be gone before navigating. */
        plan(recipe) {
            planRecipe(recipe);
            closeDialogThen(this.$refs.recipeDialog, () => openTab('#/planner/recipes'));
        },

        async remove(recipe) {
            await this.run(`remove:${recipe.id}`, this.$t('Removing …'), async () => {
                await api.delete(`/planned-recipes/${recipe.id}`);
                closeDialogThen(this.$refs.recipeDialog, () => {
                    this.recipes = this.recipes.filter((each) => each.id !== recipe.id);
                });
            });
        },

        async clear() {
            if (!confirm(this.$t('Remove all recipes from the history?'))) return;
            await this.run('clear', this.$t('Removing …'), async () => {
                await api.delete('/planned-recipes');
                this.recipes = [];
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

        date(value) {
            return value ? new Date(value).toLocaleDateString(Alpine.store('i18n').locale.replace('_', '-')) : '';
        },
    };
}
