// Product categories for sorting the items of a list by category (`item_sort` category).
// Same list as the server (App\Support\ProductCategories): keys in the default walking order.
import ALL from '../../database/data/product-categories.json';
import { normalizeName } from './helpers.js';

export const CATEGORIES = ALL.map((category) => category.key);

/** English label of a category (translation key), the key itself if unknown. */
export const CATEGORY_LABELS = Object.fromEntries(ALL.map((category) => [category.key, category.label]));

/**
 * Words in item names that point to a category (accents ignored), for names without a known
 * product. Longer matches win over shorter ones and over shorter product names
 * ("Kokosmilch" is not dairy, "Zahnpasta" is no pasta).
 */
const KEYWORDS = {
    fruit: ['apfel', 'birne', 'banane', 'orange', 'mandarine', 'zitrone', 'limette', 'beere', 'kirsche', 'traube', 'pfirsich', 'aprikose', 'nektarine', 'zwetschge', 'pflaume', 'melone', 'wassermelone', 'kiwi', 'ananas', 'mango'],
    vegetables: ['kartoffel', 'zwiebel', 'karotte', 'ruebli', 'tomate', 'gurke', 'peperoni', 'zucchetti', 'zucchini', 'aubergine', 'broccoli', 'kohl', 'lauch', 'sellerie', 'spinat', 'pilz', 'champignon', 'knoblauch', 'kurbis', 'fenchel', 'randen', 'spargel'],
    salad: ['salat', 'rucola', 'nusslisalat'],
    herbs: ['basilikum', 'petersilie', 'schnittlauch', 'koriander', 'minze', 'rosmarin', 'thymian', 'dill', 'salbei', 'kresse'],
    bread: ['brot', 'weggli', 'gipfeli', 'zopf', 'toast', 'baguette', 'croissant', 'burli', 'semmel', 'brezel', 'kuchen'],
    dairy: ['milch', 'joghurt', 'jogurt', 'rahm', 'sahne', 'butter', 'quark', 'eier', 'kefir', 'creme fraiche'],
    cheese: ['kase', 'mozzarella', 'parmesan', 'gruyere', 'emmentaler', 'feta', 'raclette', 'fondue', 'ricotta', 'mascarpone'],
    meat_fish: ['fleisch', 'wurst', 'schinken', 'speck', 'poulet', 'huhn', 'hahnchen', 'rind', 'schwein', 'kalb', 'lamm', 'hack', 'salami', 'cervelat', 'wienerli', 'aufschnitt', 'steak', 'fisch', 'lachs', 'crevetten'],
    spreads: ['konfiture', 'marmelade', 'honig', 'aufstrich', 'nutella', 'erdnussbutter'],
    pantry: ['mehl', 'zucker', 'reis', 'teigwaren', 'pasta', 'spaghetti', 'nudel', 'hornli', 'linsen', 'kichererbsen', 'haferflocken', 'muesli', 'polenta', 'couscous', 'griess', 'hefe', 'olivenol', 'rapsol', 'konserve', 'kokosmilch'],
    spices_sauces: ['salz', 'pfeffer', 'gewurz', 'senf', 'ketchup', 'mayonnaise', 'sauce', 'essig', 'bouillon', 'curry', 'zimt', 'dressing'],
    sweets_snacks: ['schokolade', 'chips', 'guetzli', 'keks', 'bonbon', 'gummibar', 'praline', 'riegel', 'snack', 'popcorn'],
    frozen: ['tiefkuhl', 'glace', 'fischstabchen', 'pommes'],
    drinks: ['wasser', 'mineral', 'saft', 'bier', 'wein', 'cola', 'limonade', 'sirup', 'eistee', 'kaffee', 'prosecco'],
    household: ['toilettenpapier', 'haushaltpapier', 'spulmittel', 'abwaschmittel', 'waschmittel', 'putzmittel', 'reiniger', 'schwamm', 'abfallsack', 'kehrichtsack', 'alufolie', 'frischhaltefolie', 'backpapier', 'batterie', 'kerze', 'teelicht'],
    personal_care: ['shampoo', 'duschgel', 'seife', 'zahnpasta', 'zahnburste', 'deo', 'sonnencreme', 'handcreme', 'rasier', 'wattestabchen', 'taschentucher'],
    baby: ['baby', 'windel'],
    pet: ['katze', 'hund', 'futter', 'katzenstreu'],
};

// Product names shorter than this are only matched as the whole name ("Eis" is not in "Reis").
const MIN_PART = 4;

const clean = (name) => String(name ?? '').trim().replace(/\s+/g, ' ').toLowerCase();

/**
 * The user's category order: known keys of the setting first, missing ones appended in the
 * default order.
 *
 * @param {string[]|null|undefined} saved preferences.category_order
 * @returns {string[]}
 */
export function categoryOrder(saved) {
    const known = [...new Set((Array.isArray(saved) ? saved : []).filter((key) => CATEGORIES.includes(key)))];

    return [...known, ...CATEGORIES.filter((key) => !known.includes(key))];
}

/**
 * Category of every product name: an own product's category wins, an own product without one
 * (e.g. the copy carrying the photo of a standard product) has the one of the standard product.
 *
 * @param {object[]} products documents of the products collection
 * @returns {Map<string, string>} cleaned name => category
 */
function productCategories(products) {
    const byName = new Map();
    for (const product of [...products].sort((a, b) => Number(b.is_public) - Number(a.is_public))) {
        // Public ones first, then own ones overwrite them when they have a category.
        if (product.category && CATEGORIES.includes(product.category)) byName.set(clean(product.name), product.category);
    }

    return byName;
}

/**
 * Best product name or keyword contained in a name: the first word with a match, in it the
 * match ending last (the head of a German compound: "Tomatensauce" is a sauce, "Orangensaft" a
 * drink), then the longest ("Kokosmilch" is no milk).
 */
function bestPart(text, parts) {
    let best = null;
    for (const part of parts) {
        const index = text.lastIndexOf(part.term);
        if (index === -1) continue;
        const end = index + part.term.length;
        const word = text.slice(0, end - 1).split(' ').length;
        const rank = { word, end, length: part.term.length };
        if (!best || rank.word < best.rank.word || (rank.word === best.rank.word
            && (rank.end > best.rank.end || (rank.end === best.rank.end && rank.length > best.rank.length)))) {
            best = { part, rank };
        }
    }

    return best?.part.category ?? null;
}

/**
 * Resolver of item names to categories, from the local products only (works offline):
 * 1. a product of the same name (case and spaces ignored) with a category;
 * 2. a product name or keyword contained in the name (accents ignored, see bestPart);
 * 3. `other`.
 *
 * @param {object[]} products
 * @returns {(name: string) => string}
 */
export function categoryResolver(products = []) {
    const byName = productCategories(products);
    // Product names before keywords, they win a tie.
    const parts = [
        ...[...byName].filter(([name]) => name.length >= MIN_PART).map(([name, category]) => ({ term: normalizeName(name), category })),
        ...Object.entries(KEYWORDS).flatMap(([category, words]) => words.map((term) => ({ term, category }))),
    ];
    const cache = new Map();

    return (name) => {
        const key = clean(name);
        if (!cache.has(key)) cache.set(key, byName.get(key) ?? bestPart(normalizeName(name), parts) ?? 'other');

        return cache.get(key);
    };
}

/**
 * Items in the order of the categories (stable: the order within a category is kept).
 *
 * @param {object[]} items already sorted (A–Z)
 * @param {(name: string) => string} categoryOf
 * @param {string[]} order categoryOrder()
 */
export function sortByCategory(items, categoryOf, order) {
    const rank = (item) => {
        const index = order.indexOf(categoryOf(item.name));
        return index === -1 ? order.length : index;
    };

    return items.map((item) => ({ item, rank: rank(item) }))
        .sort((a, b) => a.rank - b.rank)
        .map(({ item }) => item);
}

/**
 * Items grouped under their category headings, in the order of the categories; empty
 * categories are left out.
 *
 * @returns {{ key: string, items: object[] }[]}
 */
export function groupByCategory(items, categoryOf, order) {
    const groups = [];
    for (const item of sortByCategory(items, categoryOf, order)) {
        const key = categoryOf(item.name);
        if (groups.at(-1)?.key !== key) groups.push({ key, items: [] });
        groups.at(-1).items.push(item);
    }

    return groups;
}

/** Moves a category one place up (-1) or down (1) in the order; returns the new order. */
export function moveCategory(order, key, step) {
    const next = [...order];
    const from = next.indexOf(key);
    const to = from + step;
    if (from === -1 || to < 0 || to >= next.length) return next;
    next.splice(to, 0, ...next.splice(from, 1));

    return next;
}
