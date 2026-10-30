import Alpine from 'alpinejs';
import { normalizeName, splitItemInput, suggestNames, usageOf } from './helpers.js';

/**
 * Suggestions below a text field, searched in the local products, units and shops.
 * Replaces <datalist>: matches only the relevant part of "2 l Milch", ranks by usage
 * and behaves the same in every browser.
 *
 * - source "item": quick entry. After the amount it suggests units (picking one keeps
 *   typing), then products (picking one runs onPick, e.g. adds the item).
 * - source "product": the whole text is a product name.
 * - source "unit": the whole text is a unit, all units are listed on focus.
 * - source "store": the whole text is a shop (name or code), all shops are listed on focus;
 *   picking one runs onPick(option) (adds it as a chip) instead of filling in the text.
 *
 * Usage: x-data="suggestField({ source: 'item', get: () => text, set: (v) => text = v, units: () => unitNames, onPick, openItems })"
 *
 * @param {object} options
 * @param {'item'|'product'|'unit'|'store'} options.source
 * @param {() => string} options.get current text
 * @param {(value: string) => void} options.set
 * @param {() => string[]} [options.units] known units
 * @param {() => {name: string, code: string}[]} [options.stores] known shops
 * @param {(option: object) => void} [options.onPick] runs after a product (or shop) was picked
 * @param {() => object[]} [options.openItems] open items of the list, marked in the suggestions
 */
export function suggestField({ source, get, set, units = () => [], stores = () => [], onPick = null, openItems = () => [] }) {
    return {
        source,
        open: false,
        active: -1,

        get parts() {
            const text = get() ?? '';

            return source === 'item'
                ? splitItemInput(text, units())
                : { prefix: '', query: text.trim(), amount: false, unit: false };
        },

        get options() {
            const data = Alpine.store('data');
            const { query, amount, unit } = this.parts;

            if (source === 'unit') return this.unitOptions(query, 8);
            if (source === 'store') return this.storeOptions(query);

            // Right after the amount the next word may be a unit.
            const unitOptions = source === 'item' && amount && !unit && !/\s/.test(query)
                ? this.unitOptions(query, query ? 3 : 8)
                : [];

            const products = data.products.filter((product) => !Alpine.store('undo').hidden(product.id)).map((product) => product.name);
            const onList = new Set(openItems().map((item) => normalizeName(item.name)));
            const productOptions = suggestNames(query, products, usageOf(data.items))
                .map((name) => ({ kind: 'product', name, onList: onList.has(normalizeName(name)) }));

            return [...unitOptions, ...productOptions].slice(0, 8);
        },

        unitOptions(query, limit) {
            return suggestNames(query, units(), usageOf(Alpine.store('data').items, 'unit'), { limit, all: true })
                .map((name) => ({ kind: 'unit', name, onList: false }));
        },

        /**
         * Shops by name or code: exact, then start, then anywhere in the name; then by usage.
         */
        storeOptions(query) {
            const needle = normalizeName(query);
            const usage = new Map();
            for (const item of Alpine.store('data').items) {
                for (const tag of item.stores ?? []) usage.set(normalizeName(tag), (usage.get(normalizeName(tag)) ?? 0) + 1);
            }
            const matches = stores().map((store) => {
                const name = normalizeName(store.name);
                const code = normalizeName(store.code);
                const rank = !needle || name === needle || (code && code === needle) ? 0
                    : name.startsWith(needle) || (code && code.startsWith(needle)) ? 1
                        : name.includes(needle) ? 2 : -1;

                return { store, rank, count: usage.get(code || name) ?? 0 };
            }).filter((match) => match.rank >= 0);
            return matches
                .sort((a, b) => a.rank - b.rank || b.count - a.count)
                .slice(0, 8)
                .map(({ store }) => ({ kind: 'store', name: store.name, code: store.code, onList: false }));
        },

        get visible() {
            return this.open && this.options.length > 0;
        },

        input() {
            this.open = true;
            this.active = -1;
        },

        /**
         * Delayed: a tap on a suggestion must still arrive if the browser moved the focus anyway.
         */
        blur() {
            setTimeout(() => this.close(), 150);
        },

        close() {
            this.open = false;
            this.active = -1;
        },

        move(step) {
            if (!this.visible) {
                this.open = true;
                return;
            }
            // -1 is the typed text itself, so the arrows cycle through it too.
            const slots = this.options.length + 1;
            this.active = ((this.active + 1 + step + slots) % slots) - 1;
        },

        /**
         * Enter picks the highlighted suggestion instead of submitting the typed text.
         */
        enter(event) {
            if (!this.visible || this.active < 0) return;
            event.preventDefault();
            this.pick(this.options[this.active]);
        },

        pick(option) {
            if (option.kind === 'store' && onPick) {
                onPick(option);
                this.close();
                return;
            }
            if (source === 'item' && option.kind === 'unit') {
                // Continue with the product name.
                set(`${this.parts.prefix}${option.name} `);
                this.input();
                return;
            }
            set(this.parts.prefix + option.name);
            this.close();
            if (option.kind === 'product') onPick?.();
        },
    };
}
