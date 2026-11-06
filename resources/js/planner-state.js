// Week planner steps and the state synced with the server (PlannerStateController), without
// Alpine or the browser so they can be tested (tests/js/planner-state.test.js).

/** Steps of the planner, the route is #/planner/<step>. */
export const STEPS = ['offers', 'pantry', 'recipes', 'list'];

/**
 * Parts of the planner state kept on the server (cross-device). Busy flags, errors, warnings
 * and the current step stay on the device.
 */
export const SYNCED_KEYS = ['offers', 'pantry', 'servings', 'count', 'ideas', 'wishes', 'suggestions', 'items', 'target', 'listName'];

/**
 * Whether a step can be opened: recipes need selected offers or pantry items (or recipes
 * already there, e.g. planned again from the history), the list needs selected recipes.
 */
export function stepAllowed(step, state) {
    const selected = (list) => (list ?? []).some((each) => each.selected);
    switch (step) {
        case 'recipes':
            return selected(state.offers) || selected(state.pantry) || (state.suggestions ?? []).length > 0;
        case 'list':
            return selected(state.suggestions) || (state.items ?? []).length > 0;
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
 * Plain copy of the synced parts (Alpine proxies become plain values). Data URLs (photos) are
 * not synced, they stay on the device.
 */
export function syncedState(state) {
    const picked = Object.fromEntries(SYNCED_KEYS.filter((key) => state[key] !== undefined).map((key) => [key, state[key]]));

    return JSON.parse(JSON.stringify(picked, (key, value) => (typeof value === 'string' && value.startsWith('data:') ? null : value)));
}

/**
 * Suggestions after a new search: the new ones replace the shown ones, only the recipes picked
 * for the list stay (on top, still selected), so the list step keeps what it was built from.
 */
export function replaceSuggestions(current, added) {
    const kept = (current ?? []).filter((suggestion) => suggestion.selected);
    const keys = new Set(kept.map((suggestion) => suggestion.key));

    return [...kept, ...added.filter((suggestion) => !keys.has(suggestion.key))];
}

/** Recipes not to suggest again (`exclude` of POST /planner/suggestions). */
export function suggestionExclude(suggestions) {
    return {
        recipe_ids: suggestions.map((suggestion) => suggestion.recipe_id).filter(Boolean),
        names: suggestions.map((suggestion) => suggestion.name),
    };
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
 * Whether the server's state replaces the local one: newer than the local change (last write
 * wins). `local`/`remote`: { updated_at } in milliseconds, 0 if never changed.
 */
export function remoteIsNewer(local, remote) {
    return Boolean(remote?.state) && (remote.updated_at ?? 0) > (local?.updated_at ?? 0);
}
