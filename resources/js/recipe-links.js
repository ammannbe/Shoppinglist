import { sameName } from './helpers.js';

// Links between items and saved recipes: `items.recipes` entries are { name, url, key }, `key`
// is the id of the saved recipe (planned_recipes, Recipes tab). Older entries have no key, they
// show the name (and the recipe app link) only.

/** Plain copy for RxDB (no Alpine proxies), every entry with url and key. */
export function plainRecipes(recipes) {
    return (recipes ?? []).map(({ name, url, key }) => ({ name, url: url ?? null, key: key ?? null }));
}

function sameRecipe(a, b) {
    if (a.key && b.key) return a.key === b.key;

    return sameName(a.name, b.name) || Boolean(a.url && a.url === b.url);
}

/**
 * Recipes of an item merged with the ones of another (planned items into an open item): by key,
 * entries without key by name or url. A known entry without key gets the key of the added one.
 */
export function joinRecipes(known, added) {
    const joined = plainRecipes(known);
    for (const recipe of plainRecipes(added)) {
        const same = joined.find((each) => sameRecipe(each, recipe));
        if (!same) joined.push(recipe);
        else if (!same.key && recipe.key) same.key = recipe.key;
    }

    return joined;
}

/**
 * Recipes of a planned item (names from the planner's list step) with the url and the saved
 * recipe of the suggestion of that name (`planned_id`, set when the list was built).
 */
export function listRecipes(names, suggestions) {
    return (names ?? []).map((name) => {
        const suggestion = suggestions.find((each) => sameName(each.name, name));

        return { name, url: suggestion?.url ?? null, key: suggestion?.planned_id ?? null };
    });
}

/**
 * Items linked to a saved recipe, with their list (items of lists not on the device are left out),
 * open ones first.
 *
 * @param {object[]} items
 * @param {object[]} lists
 * @param {string} key planned recipe id
 * @returns {{ item: object, list: object }[]}
 */
export function linkedItems(items, lists, key) {
    if (!key) return [];
    const byId = new Map(lists.map((list) => [list.id, list]));

    return items
        .filter((item) => byId.has(item.shopping_list_id) && (item.recipes ?? []).some((recipe) => recipe.key === key))
        .map((item) => ({ item, list: byId.get(item.shopping_list_id) }))
        .sort((a, b) => Number(Boolean(a.item.done)) - Number(Boolean(b.item.done)));
}

/** Done and total of linked items ("3/5 done"). */
export function recipeProgress(linked) {
    return { done: linked.filter(({ item }) => item.done).length, total: linked.length };
}

/**
 * Stand-in for a recipe that cannot be loaded (removed, or saved by somebody else and not
 * shared): the name and url from the first item linked to it, null if no item knows it.
 */
export function unavailableRecipe(items, key) {
    for (const item of items) {
        const recipe = (item.recipes ?? []).find((each) => each.key === key);
        if (recipe) return { id: key, name: recipe.name, url: recipe.url ?? null, source: recipe.url ? 'recipes' : 'ai', unavailable: true };
    }

    return null;
}
