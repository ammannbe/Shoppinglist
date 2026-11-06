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
 * Whether the server's state replaces the local one: newer than the local change (last write
 * wins). `local`/`remote`: { updated_at } in milliseconds, 0 if never changed.
 */
export function remoteIsNewer(local, remote) {
    return Boolean(remote?.state) && (remote.updated_at ?? 0) > (local?.updated_at ?? 0);
}
