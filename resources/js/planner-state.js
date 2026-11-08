// Week planner steps and the state synced with the server (PlannerStateController), without
// Alpine or the browser so they can be tested (tests/js/planner-state.test.js).

/** Steps of the planner, the route is #/planner/<step>. */
export const STEPS = ['offers', 'pantry', 'recipes', 'list'];

/**
 * Parts of the planner state kept on the server (cross-device). Busy flags, errors, warnings
 * and the current step stay on the device. The planned recipes are saved recipes on the server
 * (planned_recipes), not part of the state.
 */
export const SYNCED_KEYS = ['offerIds', 'pantry', 'servings', 'count', 'ideas', 'wishes', 'suggestions', 'rejected', 'items', 'builtRecipes', 'target', 'listName'];

/** Rejected suggestions remembered per planner run (the server takes 100 names and ids). */
export const REJECTED_LIMIT = 100;

/**
 * Whether a step can be opened: recipes need selected offers, pantry items or planned recipes,
 * the list needs planned recipes (or a list built already).
 *
 * @param {string} step
 * @param {{ offerIds?: string[], pantry?: object[], planned?: object[], items?: object[] }} state
 */
export function stepAllowed(step, state) {
    const planned = (state.planned ?? []).length > 0;
    switch (step) {
        // Always: own recipes can be added there without offers or pantry (the suggestions need them).
        case 'recipes':
            return true;
        case 'list':
            return planned || (state.items ?? []).length > 0;
        default:
            return STEPS.includes(step);
    }
}

/** The step itself if it can be opened, otherwise the last one before it that can. */
export function allowedStep(step, state) {
    const index = STEPS.indexOf(step);
    for (let each = index < 0 ? 0 : index; each > 0; each--) {
        if (stepAllowed(STEPS[each], state)) return STEPS[each];
    }

    return STEPS[0];
}

/**
 * Steps between two steps when jumping forward (pushed to the history first, so the back
 * gesture goes back one step at a time).
 */
export function stepTrail(from, to) {
    const [start, end] = [STEPS.indexOf(from), STEPS.indexOf(to)];

    return start < 0 || end <= start + 1 ? [] : STEPS.slice(start + 1, end);
}

/**
 * State of an older version in the current shape (stored on the device or on the server):
 * - the taken offers (`offers`, each with `selected`) become the selection of offer ids;
 * - suggestions lose their selection (planning saves them now);
 * - a list built from the selected suggestions keeps their links (`builtRecipes`).
 */
export function migrateState(state) {
    if (!state || typeof state !== 'object' || Array.isArray(state)) return {};
    const { offers, ...migrated } = state;
    if (!Array.isArray(migrated.offerIds)) {
        migrated.offerIds = (Array.isArray(offers) ? offers : []).filter((offer) => offer?.id && offer.selected !== false).map((offer) => offer.id);
    }
    const suggestions = Array.isArray(migrated.suggestions) ? migrated.suggestions : [];
    if (!Array.isArray(migrated.builtRecipes)) {
        migrated.builtRecipes = (migrated.items ?? []).length
            ? suggestions.filter((suggestion) => suggestion.selected).map((suggestion) => builtRecipe(suggestion, suggestion.planned_id))
            : [];
    }
    if (Array.isArray(migrated.suggestions)) {
        migrated.suggestions = suggestions.map(({ selected, saved, planned_id: plannedId, ...suggestion }) => suggestion);
    }
    // Older states have no rejected suggestions.
    if ('rejected' in migrated && !Array.isArray(migrated.rejected)) migrated.rejected = [];

    return migrated;
}

/** A recipe the list was built from: the items link to it (`items.recipes`, recipe-links.js). */
export function builtRecipe(recipe, key = null) {
    return { name: recipe.name, url: recipe.url ?? null, key: key ?? null };
}

/**
 * Plain copy of the synced parts (Alpine proxies become plain values). Data URLs (photos) are
 * not synced, they stay on the device.
 */
export function syncedState(state) {
    const picked = Object.fromEntries(SYNCED_KEYS.filter((key) => state[key] !== undefined).map((key) => [key, state[key]]));

    return JSON.parse(JSON.stringify(picked, (key, value) => (typeof value === 'string' && value.startsWith('data:') ? null : value)));
}

/**
 * The selected offer ids still available: offers removed from the pool or expired drop out
 * (all are kept while the pool is not loaded).
 *
 * @param {string[]} ids
 * @param {object[]|null} pool offers of GET /offers
 */
export function selectedOfferIds(ids, pool) {
    if (!pool) return ids ?? [];
    const known = new Set(pool.map((offer) => offer.id));

    return (ids ?? []).filter((id) => known.has(id));
}

/** Suggestions without the recipes planned already (same key; own recipes are "recipe-<id>"). */
export function withoutPlanned(suggestions, planned) {
    const keys = new Set((planned ?? []).map((recipe) => recipe.key));

    return suggestions.filter((suggestion) => !keys.has(suggestion.key));
}

/** Recipes not to suggest again (`exclude` of POST /planner/suggestions, max. 100 each: the latest). */
export function suggestionExclude(suggestions) {
    return {
        recipe_ids: suggestions.map((suggestion) => suggestion.recipe_id).filter(Boolean).slice(-REJECTED_LIMIT),
        names: suggestions.map((suggestion) => suggestion.name).slice(-REJECTED_LIMIT),
    };
}

function nameKey(name) {
    return String(name ?? '').trim().toLowerCase();
}

/**
 * Rejects a suggestion: it leaves the suggestions and is remembered for the planner run (synced,
 * `rejected`: { key, name, recipe_id }), the oldest go beyond REJECTED_LIMIT.
 *
 * @returns {{ suggestions: object[], rejected: object[] }}
 */
export function rejectSuggestion(suggestions, rejected, suggestion) {
    const entry = { key: suggestion.key, name: suggestion.name, recipe_id: suggestion.recipe_id ?? null };

    return {
        suggestions: (suggestions ?? []).filter((each) => each.key !== suggestion.key),
        rejected: [...(rejected ?? []).filter((each) => each.key !== suggestion.key), entry].slice(-REJECTED_LIMIT),
    };
}

/**
 * What a search sends besides offers and options: the planned and shown recipes (`exclude`) and
 * the rejected ones (`rejected`), so every search finds further recipes.
 */
export function suggestionRequest({ planned = [], suggestions = [], rejected = [] }) {
    const shown = [...planned, ...suggestions];

    return {
        exclude: shown.length ? suggestionExclude(shown) : undefined,
        rejected: rejected.length ? suggestionExclude(rejected) : undefined,
    };
}

/**
 * Suggestions of a search that are new: not planned, not shown and not rejected (own recipes by
 * recipe id, ideas by name, each key once).
 */
export function newSuggestions(found, { planned = [], suggestions = [], rejected = [] }) {
    const known = [...planned, ...suggestions, ...rejected];
    const keys = new Set(known.map((recipe) => recipe.key));
    const recipeIds = new Set(known.map((recipe) => recipe.recipe_id).filter(Boolean));
    const names = new Set(known.map((recipe) => nameKey(recipe.name)));
    const added = [];
    for (const suggestion of found ?? []) {
        const repeated = suggestion.recipe_id ? recipeIds.has(suggestion.recipe_id) : names.has(nameKey(suggestion.name));
        if (keys.has(suggestion.key) || repeated) continue;
        keys.add(suggestion.key);
        added.push(suggestion);
    }

    return added;
}

/**
 * Suggestions with the AI ideas imported in the recipe app meanwhile replaced by the own recipe
 * (the saved recipe names the idea's key as `idea_key`, RecipeDrafts on the server). Returns the
 * same array when nothing changed.
 *
 * @param {object[]} suggestions
 * @param {object[]} recipes saved recipes of GET /planned-recipes
 */
export function linkImportedSuggestions(suggestions, recipes) {
    const imported = new Map((recipes ?? []).filter((recipe) => recipe.idea_key && recipe.source === 'recipes').map((recipe) => [recipe.idea_key, recipe]));
    if (!(suggestions ?? []).some((suggestion) => imported.has(suggestion.key))) return suggestions;
    const linked = suggestions.map((suggestion) => {
        const recipe = imported.get(suggestion.key);
        if (!recipe) return suggestion;
        const { ingredients, instructions, category, complexity, savedUrl, draft_url: draftUrl, ...rest } = suggestion;

        return { ...rest, key: recipe.key, source: 'recipes', recipe_id: recipe.recipe_id, name: recipe.name, url: recipe.url ?? null, savedUrl: null };
    });
    const seen = new Set();

    return linked.filter((suggestion) => !seen.has(suggestion.key) && seen.add(suggestion.key));
}

/** Fields of a suggestion the server saves (POST /planned-recipes, recipes of POST /planner/list). */
const RECIPE_FIELDS = ['key', 'source', 'recipe_id', 'name', 'url', 'servings', 'reason', 'category', 'complexity', 'ingredients', 'instructions', 'uses_offers', 'uses_pantry', 'uses_season'];

/** Plain copy of a suggestion with the saved fields only (no selection or other device state). */
export function recipePayload(suggestion) {
    const picked = Object.fromEntries(RECIPE_FIELDS.filter((field) => suggestion[field] != null).map((field) => [field, suggestion[field]]));
    if (picked.servings != null) picked.servings = Number(picked.servings) || 1;

    return JSON.parse(JSON.stringify(picked));
}

/**
 * The planner's selection a recipe planned again is matched with (POST /planned-recipes/{id}/plan),
 * from the stored planner state.
 */
export function plannerSelection(state) {
    const migrated = migrateState(state);

    return {
        offer_ids: migrated.offerIds ?? [],
        pantry: (migrated.pantry ?? []).filter((item) => item.selected).map((item) => item.name),
    };
}

/**
 * Whether the server's state replaces the local one: newer than the local change (last write
 * wins). `local`/`remote`: { updated_at } in milliseconds, 0 if never changed.
 */
export function remoteIsNewer(local, remote) {
    return Boolean(remote?.state) && (remote.updated_at ?? 0) > (local?.updated_at ?? 0);
}

/**
 * Planned recipes the list step has not built items from yet (a second build only adds those).
 *
 * @param {object[]} planned saved planned recipes
 * @param {{ key: string|null }[]} builtRecipes recipes the items were built from (key = saved id)
 */
export function unbuiltRecipes(planned, builtRecipes) {
    const built = new Set((builtRecipes ?? []).map((recipe) => recipe.key).filter(Boolean));

    return (planned ?? []).filter((recipe) => !built.has(recipe.id));
}

/**
 * Queue of recipes whose items were added to a list but whose "used" mark (POST
 * /planned-recipes/used) was not sent yet (device only): adds an entry for the built recipes.
 *
 * @param {{ ids: string[], added_at: number }[]} queue
 * @param {{ key: string|null }[]} builtRecipes
 * @param {number} addedAt ms
 */
export function queueUsed(queue, builtRecipes, addedAt) {
    const ids = [...new Set((builtRecipes ?? []).map((recipe) => recipe.key).filter(Boolean))];

    return ids.length ? [...(queue ?? []), { ids, added_at: addedAt }] : [...(queue ?? [])];
}

/**
 * Planned recipes without the ones added to a list meanwhile (queued, not sent yet); a recipe
 * planned again after it was added stays (like the server, PlannedRecipe::markUsed).
 */
export function withoutUsed(planned, queue) {
    const addedAt = new Map();
    for (const entry of queue ?? []) entry.ids.forEach((id) => addedAt.set(id, Math.max(addedAt.get(id) ?? 0, entry.added_at)));

    return (planned ?? []).filter((recipe) => !addedAt.has(recipe.id)
        || (recipe.planned_at != null && Date.parse(recipe.planned_at) > addedAt.get(recipe.id)));
}
