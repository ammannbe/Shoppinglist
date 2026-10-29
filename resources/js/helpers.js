/**
 * UUID v7 (RFC 9562): time ordered, so sorting by id keeps the order of creation.
 */
export function uuid7() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    const time = BigInt(Date.now());
    for (let i = 0; i < 6; i++) {
        bytes[i] = Number((time >> BigInt(8 * (5 - i))) & 0xffn);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x70;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * Quick entry: "2 l Milch", "500g Mehl", "1,5 kg Äpfel", "3 Eier", "Brot".
 * A word after the amount only counts as unit if it is a known unit.
 *
 * @param {string} input
 * @param {string[]} units
 * @returns {{ name: string, amount: number|null, unit: string|null }}
 */
export function parseItemInput(input, units = []) {
    const text = input.trim().replace(/\s+/g, ' ');
    const match = text.match(/^(\d+(?:[.,]\d+)?)\s*(.*)$/);
    if (!match || !match[2]) return { name: text, amount: null, unit: null };

    const amount = Number(match[1].replace(',', '.'));
    const rest = match[2];
    const [word, ...others] = rest.split(' ');
    const unit = units.find((u) => u.toLowerCase() === word.toLowerCase());

    if (unit && others.length) return { name: others.join(' '), amount, unit };

    // No known unit: "3 grosse Äpfel".
    return { name: rest, amount, unit: null };
}

/**
 * Comparable form of a product name: case and accents are ignored ("apfel" finds "Äpfel").
 */
export function normalizeName(name) {
    return String(name ?? '').trim().replace(/\s+/g, ' ').toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');
}

/**
 * Same product: case and spaces are ignored, accents not ("Apfel" is not "Äpfel").
 */
export function sameName(a, b) {
    const clean = (name) => String(name ?? '').trim().replace(/\s+/g, ' ').toLowerCase();

    return clean(a) === clean(b);
}

/**
 * Splits the quick entry into the amount/unit prefix and the text typed after it,
 * so suggestions only search the name and keep "2 l " when picked.
 *
 * @returns {{ prefix: string, query: string, amount: boolean, unit: boolean }}
 */
export function splitItemInput(input, units = []) {
    const match = input.match(/^(\s*\d+(?:[.,]\d+)?\s*)(.*)$/);
    if (!match) return { prefix: '', query: input.trim(), amount: false, unit: false };

    let [, prefix, rest] = match;
    const unit = rest.match(/^(\S+)\s+/);
    const hasUnit = Boolean(unit && units.some((u) => u.toLowerCase() === unit[1].toLowerCase()));
    if (hasUnit) {
        prefix += unit[0];
        rest = rest.slice(unit[0].length);
    }
    if (!/\s$/.test(prefix)) prefix += ' ';

    return { prefix: prefix.trimStart(), query: rest.trim(), amount: true, unit: hasUnit };
}

/**
 * How often and how recently each product name (or unit) was used, from the items of all lists.
 * Item ids are UUID v7, so the larger id is the more recent one.
 *
 * @param {object[]} items
 * @param {'name'|'unit'} field
 * @returns {Map<string, { count: number, last: string }>}
 */
export function usageOf(items, field = 'name') {
    const usage = new Map();
    for (const item of items) {
        if (!item[field]) continue;
        const key = normalizeName(item[field]);
        const entry = usage.get(key) ?? { count: 0, last: '' };
        entry.count++;
        if (item.id > entry.last) entry.last = item.id;
        usage.set(key, entry);
    }

    return usage;
}

/**
 * Best matching names (products or units): exact match, then start of the name, start of a word,
 * anywhere, then by usage, recency and length. An empty query lists everything if `all` is set.
 *
 * @param {string} query
 * @param {string[]} names
 * @param {Map<string, { count: number, last: string }>} usage
 * @returns {string[]}
 */
export function suggestNames(query, names, usage = new Map(), { limit = 8, all = false } = {}) {
    const needle = normalizeName(query);
    if (!needle && !all) return [];

    const seen = new Set();
    const matches = [];
    for (const name of names) {
        const unique = name.trim().toLowerCase();
        if (seen.has(unique)) continue;
        seen.add(unique);

        const key = normalizeName(name);
        const index = key.indexOf(needle);
        if (index === -1) continue;
        const rank = !needle || key === needle ? 0 : index === 0 ? 1 : /[\s-]/.test(key[index - 1]) ? 2 : 3;
        matches.push({ name, key, rank, use: usage.get(key) ?? { count: 0, last: '' } });
    }

    matches.sort((a, b) => a.rank - b.rank
        || b.use.count - a.use.count
        || (b.use.last > a.use.last ? 1 : b.use.last < a.use.last ? -1 : 0)
        || a.key.length - b.key.length
        || a.key.localeCompare(b.key));

    // Nothing to suggest when the only match is what was typed.
    if (needle && matches.length === 1 && matches[0].key === needle) return [];

    return matches.slice(0, limit).map((match) => match.name);
}

/**
 * Merging a new entry into an open item of the same product is only possible with the
 * same unit; a missing amount counts as one piece.
 *
 * @returns {number|null} merged amount, null if the units differ
 */
export function mergedAmount(existing, added) {
    if ((existing.unit ?? '').toLowerCase() !== (added.unit ?? '').toLowerCase()) return null;

    return Math.round(((existing.amount ?? 1) + (added.amount ?? 1)) * 1000) / 1000;
}

/**
 * Manual order first (position), entries without position after them in their default order.
 */
export function byPosition(fallback) {
    return (a, b) => (a.position ?? Infinity) - (b.position ?? Infinity) || fallback(a, b);
}

/**
 * Comparator for a sort option: manual (position), name_asc, name_desc, newest, oldest.
 * IDs are UUID v7, so they follow the creation time.
 */
export function compareBy(sort, byName, fallback) {
    const byId = (a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0);
    switch (sort) {
        case 'name_asc': return byName;
        case 'name_desc': return (a, b) => byName(b, a);
        case 'newest': return (a, b) => byId(b, a);
        case 'oldest': return byId;
        default: return byPosition(fallback);
    }
}

/**
 * Next position at the end, null as long as nothing was sorted manually.
 */
export function nextPosition(docs) {
    const positions = docs.map((doc) => doc.position).filter((position) => position !== null && position !== undefined);

    return positions.length ? Math.max(...positions) + 1 : null;
}

export function formatAmount(amount, locale) {
    if (amount === null || amount === undefined) return '';

    return new Intl.NumberFormat(locale.replace('_', '-'), { maximumFractionDigits: 3 }).format(amount);
}
