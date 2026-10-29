import Alpine from 'alpinejs';
import { normalizeName, splitItemInput, suggestNames, usageOf } from './helpers.js';

/**
 * Suggestions below a text field, searched in the local products and units.
 * Replaces <datalist>: matches only the relevant part of "2 l Milch", ranks by usage
 * and behaves the same in every browser.
 *
 * - source "item": quick entry. After the amount it suggests units (picking one keeps
 *   typing), then products (picking one runs onPick, e.g. adds the item).
 * - source "product": the whole text is a product name.
 * - source "unit": the whole text is a unit, all units are listed on focus.
 *
 * Usage: x-data="suggestField({ source: 'item', get: () => text, set: (v) => text = v, units: () => unitNames, onPick, openItems })"
 *
 * @param {object} options
 * @param {'item'|'product'|'unit'} options.source
 * @param {() => string} options.get current text
 * @param {(value: string) => void} options.set
 * @param {() => string[]} [options.units] known units
 * @param {() => void} [options.onPick] runs after a product was picked
 * @param {() => object[]} [options.openItems] open items of the list, marked in the suggestions
 */
export function suggestField({ source, get, set, units = () => [], onPick = null, openItems = () => [] }) {
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
