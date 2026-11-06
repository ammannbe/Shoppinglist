import { describe, expect, it } from 'vitest';
import { allowedStep, recipePayload, remoteIsNewer, replaceSuggestions, stepAllowed, stepTrail, suggestionExclude, syncedState } from '../../resources/js/planner-state.js';

const empty = { offers: [], pantry: [], suggestions: [], items: [] };

describe('stepAllowed', () => {
    it('always allows offers and pantry', () => {
        expect(stepAllowed('offers', empty)).toBe(true);
        expect(stepAllowed('pantry', empty)).toBe(true);
        expect(stepAllowed('unknown', empty)).toBe(false);
    });

    it('needs selected offers or pantry items for the recipes', () => {
        expect(stepAllowed('recipes', empty)).toBe(false);
        expect(stepAllowed('recipes', { ...empty, offers: [{ selected: false }] })).toBe(false);
        expect(stepAllowed('recipes', { ...empty, offers: [{ selected: true }] })).toBe(true);
        expect(stepAllowed('recipes', { ...empty, pantry: [{ selected: true }] })).toBe(true);
        // A recipe planned again from the history.
        expect(stepAllowed('recipes', { ...empty, suggestions: [{ selected: true }] })).toBe(true);
    });

    it('needs selected recipes (or a built list) for the list', () => {
        expect(stepAllowed('list', { ...empty, suggestions: [{ selected: false }] })).toBe(false);
        expect(stepAllowed('list', { ...empty, suggestions: [{ selected: true }] })).toBe(true);
        expect(stepAllowed('list', { ...empty, items: [{ name: 'Reis' }] })).toBe(true);
    });
});

describe('allowedStep', () => {
    it('falls back to the last step that can be opened', () => {
        expect(allowedStep('list', empty)).toBe('pantry');
        expect(allowedStep('list', { ...empty, offers: [{ selected: true }] })).toBe('recipes');
        expect(allowedStep('recipes', { ...empty, offers: [{ selected: true }] })).toBe('recipes');
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
        const state = { ...empty, offers: [{ id: 'a', selected: true }], busy: 'Loading', error: 'x', warning: 'y', step: 'pantry', wishes: 'quick' };

        expect(syncedState(state)).toEqual({ ...empty, offers: [{ id: 'a', selected: true }], wishes: 'quick' });
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

describe('replaceSuggestions', () => {
    it('replaces the shown suggestions but keeps the ones picked for the list', () => {
        const current = [{ key: 'a', selected: false }, { key: 'b', selected: true }, { key: 'c', selected: false }];
        const added = [{ key: 'd', selected: false }, { key: 'b', selected: false }];

        expect(replaceSuggestions(current, added)).toEqual([{ key: 'b', selected: true }, { key: 'd', selected: false }]);
        expect(replaceSuggestions([], added)).toEqual(added);
    });

    it('keeps the list step reachable only with picked recipes', () => {
        const state = { ...empty, suggestions: replaceSuggestions([{ key: 'a', selected: false }], [{ key: 'b', selected: false }]) };
        expect(stepAllowed('list', state)).toBe(false);
        expect(stepAllowed('list', { ...empty, suggestions: replaceSuggestions([{ key: 'a', selected: true }], []) })).toBe(true);
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
