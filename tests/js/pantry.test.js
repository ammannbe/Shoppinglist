import { describe, expect, it } from 'vitest';
import { isPantry, mergePantry, pantryEntries, pantryItemsToSave, pantryLists, restockItem, restockTarget, shoppingLists } from '../../resources/js/pantry.js';

const UNITS = ['g', 'kg', 'l', 'Stk.'];
const migros = { id: 'a', name: 'Migros', kind: 'shopping' };
const coop = { id: 'b', name: 'Coop' };
const kitchen = { id: 'c', name: 'Küche', kind: 'pantry' };

describe('kinds of lists', () => {
    it('treats lists without kind as shopping lists', () => {
        expect(isPantry(kitchen)).toBe(true);
        expect(isPantry(coop)).toBe(false);
        expect(isPantry(null)).toBe(false);
        expect(shoppingLists([migros, coop, kitchen])).toEqual([migros, coop]);
        expect(pantryLists([migros, coop, kitchen])).toEqual([kitchen]);
    });
});

describe('restockTarget', () => {
    it('uses the last shopping list if it still exists', () => {
        expect(restockTarget([migros, coop], 'b')).toEqual({ action: 'add', list: coop });
    });

    it('uses the only shopping list', () => {
        expect(restockTarget([migros], null)).toEqual({ action: 'add', list: migros });
        expect(restockTarget([migros], 'gone')).toEqual({ action: 'add', list: migros });
    });

    it('asks with several lists and none known, offers a new one without any', () => {
        expect(restockTarget([migros, coop], 'gone')).toEqual({ action: 'pick' });
        expect(restockTarget([migros, coop])).toEqual({ action: 'pick' });
        expect(restockTarget([], 'b')).toEqual({ action: 'create' });
    });
});

describe('restockItem', () => {
    it('copies the product with amount, unit and photo only', () => {
        const item = { id: 'i', shopping_list_id: 'c', name: 'Reis', amount: 1, unit: 'kg', image: 'img', note: 'Basmati', stores: ['M'], done: false };
        expect(restockItem(item)).toEqual({ name: 'Reis', amount: 1, unit: 'kg', image: 'img' });
        expect(restockItem({ name: 'Salz' })).toEqual({ name: 'Salz', amount: null, unit: null, image: null });
    });
});

describe('pantry in the week planner', () => {
    const amountText = (item) => [item.amount, item.unit].filter(Boolean).join(' ');

    it('takes the open items of a pantry list', () => {
        const items = [
            { name: 'Reis', amount: 1, unit: 'kg', done: false },
            { name: 'Milch', amount: null, unit: null, done: true },
            { name: 'Eier', amount: 6, unit: null, done: false },
        ];
        expect(pantryEntries(items, amountText)).toEqual([{ name: 'Reis', amount: '1 kg' }, { name: 'Eier', amount: '6' }]);
        expect(pantryEntries([{ name: 'Salz', done: false }], () => '')).toEqual([{ name: 'Salz', amount: null }]);
    });

    it('adds entries selected and skips names already there', () => {
        const pantry = [{ name: 'Reis', amount: null, selected: false }];
        expect(mergePantry(pantry, [{ name: 'reis', amount: '1 kg' }, { name: 'Eier', amount: null }, { name: ' eier ', amount: '6' }, { name: ' ', amount: null }]))
            .toEqual([{ name: 'Reis', amount: null, selected: false }, { name: 'Eier', amount: null, selected: true }]);
    });

    it('saves only names not open in the pantry list, amounts as amount and unit or note', () => {
        const items = [{ name: 'Reis', done: false }, { name: 'Milch', done: true }];
        const entries = [
            { name: 'reis', amount: null },
            { name: 'Milch', amount: '1 l' },
            { name: 'Mehl', amount: '500 g' },
            { name: 'Eier', amount: '6' },
            { name: 'Butter', amount: 'halbe Packung' },
            { name: 'Käse', amount: null },
            { name: 'käse', amount: null },
        ];
        expect(pantryItemsToSave(entries, items, UNITS)).toEqual([
            { name: 'Milch', amount: 1, unit: 'l', note: null },
            { name: 'Mehl', amount: 500, unit: 'g', note: null },
            { name: 'Eier', amount: 6, unit: null, note: null },
            { name: 'Butter', amount: null, unit: null, note: 'halbe Packung' },
            { name: 'Käse', amount: null, unit: null, note: null },
        ]);
    });
});
