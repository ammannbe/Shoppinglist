import { describe, expect, it } from 'vitest';
import {
    allowedStep, builtRecipe, linkImportedSuggestions, migrateState, newSuggestions, plannerSelection, recipePayload, rejectSuggestion, REJECTED_LIMIT,
    remoteIsNewer, selectedOfferIds, stepAllowed, stepTrail, suggestionExclude, suggestionRequest, SYNCED_KEYS, syncedState, queueUsed, unbuiltRecipes,
    withoutPlanned, withoutUsed,
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

describe('rejected suggestions', () => {
    const suggestions = [
        { key: 'recipe-5', recipe_id: 5, name: 'Lasagne' },
        { key: 'idea-1', name: 'Omelette', ingredients: [] },
    ];

    it('removes a rejected suggestion and remembers it for the run', () => {
        const result = rejectSuggestion(suggestions, [], suggestions[1]);

        expect(result.suggestions).toEqual([suggestions[0]]);
        expect(result.rejected).toEqual([{ key: 'idea-1', name: 'Omelette', recipe_id: null }]);
        expect(rejectSuggestion(result.suggestions, result.rejected, suggestions[0]).rejected.map((each) => each.key)).toEqual(['idea-1', 'recipe-5']);
    });

    it('keeps the latest rejected ones only', () => {
        const rejected = Array.from({ length: REJECTED_LIMIT }, (_, index) => ({ key: `idea-${index}`, name: `Gericht ${index}`, recipe_id: null }));
        const result = rejectSuggestion([], rejected, { key: 'idea-new', name: 'Neu' });

        expect(result.rejected).toHaveLength(REJECTED_LIMIT);
        expect(result.rejected[0].key).toBe('idea-1');
        expect(result.rejected.at(-1).key).toBe('idea-new');
    });

    it('is part of the synced state and of older states', () => {
        expect(SYNCED_KEYS).toContain('rejected');
        expect(syncedState({ rejected: [{ key: 'idea-1', name: 'Omelette', recipe_id: null }] })).toEqual({ rejected: [{ key: 'idea-1', name: 'Omelette', recipe_id: null }] });
        expect(migrateState({ offerIds: [], builtRecipes: [], rejected: 'broken' }).rejected).toEqual([]);
        expect(migrateState({ offerIds: [], builtRecipes: [] })).not.toHaveProperty('rejected');
    });
});

describe('suggestionRequest', () => {
    it('sends the planned and shown recipes as exclude and the rejected ones separately', () => {
        expect(suggestionRequest({
            planned: [{ key: 'recipe-7', recipe_id: 7, name: 'Chili' }],
            suggestions: [{ key: 'idea-1', name: 'Omelette' }],
            rejected: [{ key: 'recipe-5', recipe_id: 5, name: 'Lasagne' }, { key: 'idea-2', name: 'Tacos', recipe_id: null }],
        })).toEqual({
            exclude: { recipe_ids: [7], names: ['Chili', 'Omelette'] },
            rejected: { recipe_ids: [5], names: ['Lasagne', 'Tacos'] },
        });
    });

    it('sends nothing for a fresh search', () => {
        expect(suggestionRequest({ planned: [], suggestions: [], rejected: [] })).toEqual({ exclude: undefined, rejected: undefined });
    });
});

describe('newSuggestions', () => {
    it('leaves out planned, shown and rejected recipes', () => {
        const found = [
            { key: 'recipe-5', recipe_id: 5, name: 'Lasagne' },
            { key: 'recipe-6', recipe_id: 6, name: 'Chili' },
            { key: 'recipe-7', recipe_id: 7, name: 'Risotto' },
            { key: 'idea-9', name: ' omelette ' },
            { key: 'idea-10', name: 'Burger' },
            { key: 'idea-10', name: 'Burger' },
            { key: 'idea-11', name: 'Tacos' },
        ];

        expect(newSuggestions(found, {
            planned: [{ key: 'recipe-6', recipe_id: 6, name: 'Chili' }],
            suggestions: [{ key: 'idea-1', name: 'Omelette' }],
            rejected: [{ key: 'recipe-5', recipe_id: 5, name: 'Lasagne' }, { key: 'idea-2', name: 'Tacos', recipe_id: null }],
        }).map((each) => each.key)).toEqual(['recipe-7', 'idea-10']);
    });
});

describe('linkImportedSuggestions', () => {
    const suggestions = [
        { key: 'idea-1', source: 'ai', name: 'Omelette', ingredients: [{ name: 'Eier' }], instructions: '1.', category: 'Eier', complexity: 'simple', savedUrl: 'https://recipes.test/import?draft=a', servings: 3, reason: 'Eier' },
        { key: 'recipe-5', source: 'recipes', recipe_id: 5, name: 'Lasagne' },
    ];

    it('turns an imported idea into the own recipe', () => {
        const linked = linkImportedSuggestions(suggestions, [
            { id: 'p1', key: 'recipe-8', source: 'recipes', recipe_id: 8, name: 'Omelette mit Kräutern', url: 'https://recipes.test/recipes/8', idea_key: 'idea-1' },
        ]);

        expect(linked).toEqual([
            { key: 'recipe-8', source: 'recipes', recipe_id: 8, name: 'Omelette mit Kräutern', url: 'https://recipes.test/recipes/8', savedUrl: null, servings: 3, reason: 'Eier' },
            suggestions[1],
        ]);
    });

    it('keeps the suggestions when nothing was imported and drops a duplicate own recipe', () => {
        expect(linkImportedSuggestions(suggestions, [{ key: 'idea-1', source: 'ai' }])).toBe(suggestions);
        expect(linkImportedSuggestions(suggestions, [{ key: 'recipe-5', source: 'recipes', recipe_id: 5, name: 'Lasagne', idea_key: 'idea-1' }]).map((each) => each.key))
            .toEqual(['recipe-5']);
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
