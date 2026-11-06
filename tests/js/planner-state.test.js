import { describe, expect, it } from 'vitest';
import { allowedStep, remoteIsNewer, stepAllowed, stepTrail, syncedState } from '../../resources/js/planner-state.js';

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
