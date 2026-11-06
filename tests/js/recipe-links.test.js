import { describe, expect, it } from 'vitest';
import { joinRecipes, linkedItems, listRecipes, plainRecipes, recipeProgress, unavailableRecipe } from '../../resources/js/recipe-links.js';

const lists = [{ id: 'l1', name: 'Wocheneinkauf' }, { id: 'l2', name: 'Coop' }];
const items = [
    { id: 'a', shopping_list_id: 'l1', name: 'Hackfleisch', done: true, recipes: [{ name: 'Lasagne', url: null, key: 'r1' }] },
    { id: 'b', shopping_list_id: 'l2', name: 'Tomaten', done: false, recipes: [{ name: 'Lasagne', url: null, key: 'r1' }, { name: 'Salat', url: null, key: 'r2' }] },
    // Older entry without key, and an item of a list not on the device.
    { id: 'c', shopping_list_id: 'l1', name: 'Käse', done: false, recipes: [{ name: 'Lasagne', url: null }] },
    { id: 'd', shopping_list_id: 'gone', name: 'Milch', done: false, recipes: [{ name: 'Lasagne', url: null, key: 'r1' }] },
];

describe('recipe links', () => {
    it('copy entries with url and key', () => {
        expect(plainRecipes([{ name: 'Lasagne' }])).toEqual([{ name: 'Lasagne', url: null, key: null }]);
        expect(plainRecipes(null)).toEqual([]);
    });

    it('match items to a saved recipe by key, open ones first', () => {
        const linked = linkedItems(items, lists, 'r1');
        expect(linked.map(({ item, list }) => [item.id, list.name])).toEqual([['b', 'Coop'], ['a', 'Wocheneinkauf']]);
        expect(recipeProgress(linked)).toEqual({ done: 1, total: 2 });
        expect(linkedItems(items, lists, null)).toEqual([]);
    });

    it('join recipes by key, entries without key by name or url', () => {
        const known = [{ name: 'Lasagne', url: 'https://recipes.test/5' }, { name: 'Salat', url: null, key: 'r2' }];
        expect(joinRecipes(known, [
            { name: 'lasagne', url: null, key: 'r1' },
            { name: 'Salat', url: null, key: 'r2' },
            { name: 'Omelette', url: null, key: 'r3' },
        ])).toEqual([
            { name: 'Lasagne', url: 'https://recipes.test/5', key: 'r1' },
            { name: 'Salat', url: null, key: 'r2' },
            { name: 'Omelette', url: null, key: 'r3' },
        ]);
        // Different saved recipes of the same name stay apart.
        expect(joinRecipes([{ name: 'Salat', key: 'r2' }], [{ name: 'Salat', key: 'r4' }]).map((recipe) => recipe.key)).toEqual(['r2', 'r4']);
    });

    it('link planned items to the saved recipe the list was built from', () => {
        const built = [{ name: 'Lasagne', url: 'https://recipes.test/5', key: 'r1' }, { name: 'Omelette', url: null, key: null }];
        expect(listRecipes(['Lasagne', 'Omelette', 'Unbekannt'], built)).toEqual([
            { name: 'Lasagne', url: 'https://recipes.test/5', key: 'r1' },
            { name: 'Omelette', url: null, key: null },
            { name: 'Unbekannt', url: null, key: null },
        ]);
    });

    it('fall back to the items for recipes that cannot be loaded', () => {
        expect(unavailableRecipe(items, 'r2')).toMatchObject({ id: 'r2', name: 'Salat', unavailable: true });
        expect(unavailableRecipe(items, 'nope')).toBeNull();
    });
});
