import { describe, expect, it } from 'vitest';
import {
    allowedStep, builtRecipe, migrateState, plannerSelection, recipePayload, remoteIsNewer, selectedOfferIds, stepAllowed, stepTrail, suggestionExclude,
    syncedState, queueUsed, unbuiltRecipes, withoutPlanned, withoutUsed,
} from '../../resources/js/planner-state.js';

const empty = { offerIds: [], pantry: [], planned: [], items: [] };

describe('stepAllowed', () => {
    it('always allows offers and pantry', () => {
        expect(stepAllowed('offers', empty)).toBe(true);
        expect(stepAllowed('pantry', empty)).toBe(true);
        expect(stepAllowed('unknown', empty)).toBe(false);
    });

    it('always allows the recipes (own recipes need neither offers nor pantry)', () => {
        expect(stepAllowed('recipes', empty)).toBe(true);
        expect(stepAllowed('recipes', { ...empty, offerIds: ['o1'] })).toBe(true);
    });

    it('needs planned recipes (or a built list) for the list', () => {
        expect(stepAllowed('list', { ...empty, offerIds: ['o1'] })).toBe(false);
        expect(stepAllowed('list', { ...empty, planned: [{ id: 'r1' }] })).toBe(true);
        expect(stepAllowed('list', { ...empty, items: [{ name: 'Reis' }] })).toBe(true);
    });
});

describe('allowedStep', () => {
    it('falls back to the last step that can be opened', () => {
        expect(allowedStep('list', empty)).toBe('recipes');
        expect(allowedStep('list', { ...empty, offerIds: ['o1'] })).toBe('recipes');
        expect(allowedStep('recipes', { ...empty, offerIds: ['o1'] })).toBe('recipes');
        expect(allowedStep('offers', empty)).toBe('offers');
        expect(allowedStep(undefined, empty)).toBe('offers');
    });
});

describe('stepTrail', () => {
    it('lists the skipped steps when jumping forward', () => {
        expect(stepTrail('offers', 'list')).toEqual(['pantry', 'recipes']);
        expect(stepTrail('offers', 'pantry')).toEqual([]);
        expect(stepTrail('list', 'offers')).toEqual([]);
        expect(stepTrail(null, 'list')).toEqual([]);
    });
});

describe('syncedState', () => {
    it('keeps only the synced parts as plain values', () => {
        const state = { ...empty, offerIds: ['a'], suggestions: [], busy: 'Loading', error: 'x', warning: 'y', step: 'pantry', wishes: 'quick', savedOffers: [{ id: 'a' }] };

        expect(syncedState(state)).toEqual({ offerIds: ['a'], pantry: [], items: [], suggestions: [], wishes: 'quick' });
    });

    it('drops data URLs', () => {
        expect(syncedState({ pantry: [{ name: 'Reis', photo: 'data:image/jpeg;base64,AAAA' }] })).toEqual({ pantry: [{ name: 'Reis', photo: null }] });
    });
});

describe('remoteIsNewer', () => {
    it('takes the server state only if it is newer', () => {
        expect(remoteIsNewer({ updated_at: 10 }, { state: {}, updated_at: 20 })).toBe(true);
        expect(remoteIsNewer({ updated_at: 20 }, { state: {}, updated_at: 20 })).toBe(false);
        expect(remoteIsNewer({ updated_at: 30 }, { state: {}, updated_at: 20 })).toBe(false);
        expect(remoteIsNewer({ updated_at: 0 }, { state: null, updated_at: 0 })).toBe(false);
        expect(remoteIsNewer(null, { state: { offers: [] }, updated_at: 1 })).toBe(true);
    });
});

describe('migrateState', () => {
    it('turns the taken offers into the selection', () => {
        const old = { offers: [{ id: 'a', selected: true }, { id: 'b', selected: false }, { name: 'without id', selected: true }, { id: 'c' }], pantry: [] };

        expect(migrateState(old)).toEqual({ offerIds: ['a', 'c'], pantry: [], builtRecipes: [] });
    });

    it('drops the selection of suggestions and keeps the links of a built list', () => {
        const old = {
            suggestions: [
                { key: 'recipe-5', name: 'Lasagne', url: 'https://recipes.test/5', selected: true, saved: true, planned_id: 'r1' },
                { key: 'idea-1', name: 'Omelette', selected: false, saved: false },
            ],
            items: [{ name: 'Hackfleisch', recipes: ['Lasagne'] }],
        };
        const migrated = migrateState(old);

        expect(migrated.suggestions).toEqual([{ key: 'recipe-5', name: 'Lasagne', url: 'https://recipes.test/5' }, { key: 'idea-1', name: 'Omelette' }]);
        expect(migrated.builtRecipes).toEqual([{ name: 'Lasagne', url: 'https://recipes.test/5', key: 'r1' }]);
        expect(migrated.offerIds).toEqual([]);
    });

    it('keeps the current shape and tolerates anything else', () => {
        const current = { offerIds: ['a'], suggestions: [{ key: 'idea-1', name: 'Omelette' }], items: [], builtRecipes: [] };

        expect(migrateState(current)).toEqual(current);
        expect(migrateState(null)).toEqual({});
        expect(migrateState([])).toEqual({});
    });
});

describe('selectedOfferIds', () => {
    it('drops offers no longer in the pool once it is loaded', () => {
        expect(selectedOfferIds(['a', 'gone'], [{ id: 'a' }, { id: 'b' }])).toEqual(['a']);
        expect(selectedOfferIds(['a', 'gone'], null)).toEqual(['a', 'gone']);
        expect(selectedOfferIds(undefined, [])).toEqual([]);
    });
});

describe('withoutPlanned', () => {
    it('leaves out suggestions planned already', () => {
        expect(withoutPlanned([{ key: 'recipe-5' }, { key: 'idea-1' }], [{ id: 'r1', key: 'recipe-5' }])).toEqual([{ key: 'idea-1' }]);
        expect(withoutPlanned([{ key: 'idea-1' }], undefined)).toEqual([{ key: 'idea-1' }]);
    });
});

describe('builtRecipe', () => {
    it('keeps name, url and the saved recipe', () => {
        expect(builtRecipe({ name: 'Omelette', source: 'ai' }, 'r2')).toEqual({ name: 'Omelette', url: null, key: 'r2' });
        expect(builtRecipe({ name: 'Lasagne', url: 'https://recipes.test/5' })).toEqual({ name: 'Lasagne', url: 'https://recipes.test/5', key: null });
    });
});

describe('plannerSelection', () => {
    it('sends the selected offers and pantry items, also of an older state', () => {
        expect(plannerSelection({ offerIds: ['a'], pantry: [{ name: 'Reis', selected: true }, { name: 'Milch', selected: false }] }))
            .toEqual({ offer_ids: ['a'], pantry: ['Reis'] });
        expect(plannerSelection({ offers: [{ id: 'b', selected: true }] })).toEqual({ offer_ids: ['b'], pantry: [] });
        expect(plannerSelection(null)).toEqual({ offer_ids: [], pantry: [] });
    });
});

describe('suggestionExclude', () => {
    it('lists recipe ids and names of the given suggestions', () => {
        expect(suggestionExclude([{ recipe_id: 5, name: 'Lasagne' }, { recipe_id: null, name: 'Omelette' }]))
            .toEqual({ recipe_ids: [5], names: ['Lasagne', 'Omelette'] });
    });
});

describe('recipePayload', () => {
    it('keeps the saved fields only, as plain values', () => {
        const suggestion = {
            key: 'idea-1', source: 'ai', name: 'Omelette', servings: '3', selected: true, saved: false, savedUrl: null, url: null,
            reason: 'Eier', ingredients: [{ amount: 4, unit: 'Stk.', name: 'Eier' }], instructions: '1. Braten', uses_offers: [],
        };

        expect(recipePayload(suggestion)).toEqual({
            key: 'idea-1', source: 'ai', name: 'Omelette', servings: 3, reason: 'Eier',
            ingredients: [{ amount: 4, unit: 'Stk.', name: 'Eier' }], instructions: '1. Braten', uses_offers: [],
        });
        expect(recipePayload(suggestion).ingredients).not.toBe(suggestion.ingredients);
    });

    it('falls back to one serving for invalid numbers', () => {
        expect(recipePayload({ source: 'recipes', recipe_id: 5, name: 'Lasagne', servings: '' }).servings).toBe(1);
    });
});

describe('unbuiltRecipes', () => {
    it('leaves out the planned recipes the items were built from', () => {
        const planned = [{ id: 'r1' }, { id: 'r2' }];
        expect(unbuiltRecipes(planned, [])).toEqual(planned);
        expect(unbuiltRecipes(planned, [{ name: 'A', key: 'r1' }, { name: 'Old', key: null }])).toEqual([{ id: 'r2' }]);
        expect(unbuiltRecipes(planned, [{ key: 'r1' }, { key: 'r2' }])).toEqual([]);
    });
});

describe('used recipes queue', () => {
    it('queues the saved ids of the built recipes', () => {
        expect(queueUsed([], [{ key: 'r1' }, { key: null }, { key: 'r1' }, { key: 'r2' }], 1000)).toEqual([{ ids: ['r1', 'r2'], added_at: 1000 }]);
        expect(queueUsed([{ ids: ['r0'], added_at: 1 }], [{ key: 'r1' }], 2)).toEqual([{ ids: ['r0'], added_at: 1 }, { ids: ['r1'], added_at: 2 }]);
        // Nothing saved: nothing to send.
        expect(queueUsed([], [{ key: null }], 1000)).toEqual([]);
    });

    it('hides queued recipes from the planned ones unless planned again after adding', () => {
        const added = Date.parse('2026-11-07T10:00:00Z');
        const queue = [{ ids: ['r1', 'r2'], added_at: added }];
        const planned = [
            { id: 'r1', planned_at: '2026-11-07T09:00:00+00:00' },
            { id: 'r2', planned_at: '2026-11-07T11:00:00+00:00' },
            { id: 'r3', planned_at: null },
        ];
        expect(withoutUsed(planned, queue).map((recipe) => recipe.id)).toEqual(['r2', 'r3']);
        expect(withoutUsed(planned, []).map((recipe) => recipe.id)).toEqual(['r1', 'r2', 'r3']);
    });
});
