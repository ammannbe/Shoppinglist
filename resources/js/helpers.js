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

export function formatAmount(amount, locale) {
    if (amount === null || amount === undefined) return '';

    return new Intl.NumberFormat(locale.replace('_', '-'), { maximumFractionDigits: 3 }).format(amount);
}
