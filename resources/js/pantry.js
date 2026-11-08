// Pantry lists (Vorrat): a shopping list with `kind: 'pantry'`, so sharing, sync, undo and photos
// work the same. Checked items are used up and stay on the list; "Restock" copies an item onto a
// shopping list. The week planner reads the open items of a pantry ("at home") and saves
// recognized ones into it. Pure helpers, used by app.js and planner.js.

import { parseItemInput, sameName } from './helpers.js';

export const KINDS = ['shopping', 'pantry'];

export function isPantry(list) {
    return list?.kind === 'pantry';
}

/** Lists items can be bought on (lists without kind are shopping lists). */
export function shoppingLists(lists) {
    return lists.filter((list) => !isPantry(list));
}

export function pantryLists(lists) {
    return lists.filter(isPantry);
}

/**
 * Where "Restock" puts an item: the last used shopping list if it still exists, the only one if
 * there is just one; otherwise the user picks one (`pick`), or none exists yet (`create`, never
 * created without asking).
 *
 * @param {object[]} lists shopping lists
 * @param {string|null} lastId list used last time (device only)
 * @returns {{ action: 'add', list: object } | { action: 'pick' } | { action: 'create' }}
 */
export function restockTarget(lists, lastId = null) {
    const last = lastId ? lists.find((list) => list.id === lastId) : null;
    if (last) return { action: 'add', list: last };
    if (lists.length === 1) return { action: 'add', list: lists[0] };

    return { action: lists.length ? 'pick' : 'create' };
}

/** Fields of a pantry item copied onto a shopping list ("Restock"): the product with amount and photo. */
export function restockItem(item) {
    return { name: item.name, amount: item.amount ?? null, unit: item.unit ?? null, image: item.image ?? null };
}

/**
 * Week planner: the open (not used up) items of a pantry list as entries of the step "At home"
 * ({ name, amount }: the amount as text like the ones read from photos).
 *
 * @param {object[]} items items of the pantry list
 * @param {(item: object) => string} amountText formatted amount and unit ('' without)
 */
export function pantryEntries(items, amountText) {
    return items.filter((item) => !item.done).map((item) => ({ name: item.name, amount: amountText(item) || null }));
}

/**
 * Adds entries to the planner's pantry, selected; names already there (or twice in `entries`)
 * are skipped, case-insensitive.
 */
export function mergePantry(pantry, entries) {
    const known = new Set(pantry.map((item) => item.name.trim().toLowerCase()));
    const added = [];
    for (const entry of entries) {
        const key = entry.name.trim().toLowerCase();
        if (!key || known.has(key)) continue;
        known.add(key);
        added.push({ ...entry, name: entry.name.trim(), selected: true });
    }

    return [...pantry, ...added];
}

/**
 * Week planner → pantry list ("Save to pantry"): new items for the entries not open there yet.
 * An amount like "500 g" becomes amount and unit, other texts ("half a pack") the note.
 *
 * @param {{ name: string, amount?: string|null }[]} entries
 * @param {object[]} items items of the pantry list (used up ones may be added again)
 * @param {string[]} units known units
 * @returns {{ name: string, amount: number|null, unit: string|null, note: string|null }[]}
 */
export function pantryItemsToSave(entries, items, units = []) {
    const open = items.filter((item) => !item.done);
    const result = [];
    for (const entry of entries) {
        const name = entry.name.trim();
        if (!name || [...open, ...result].some((item) => sameName(item.name, name))) continue;
        const text = String(entry.amount ?? '').trim();
        const parsed = text ? parseItemInput(`${text} ${name}`, units) : null;
        if (parsed?.amount !== null && parsed?.amount !== undefined && sameName(parsed.name, name)) {
            result.push({ name, amount: parsed.amount, unit: parsed.unit, note: null });
        } else {
            result.push({ name, amount: null, unit: null, note: text ? text.slice(0, 255) : null });
        }
    }

    return result;
}
