// Shops: icons, countries and the order of the shop choice.

import { normalizeName } from './helpers.js';

// Icons of the standard shops (stores.icon is the file name without extension), built and precached by Vite.
const ICONS = Object.fromEntries(Object.entries(import.meta.glob('../images/shops/*.{svg,png,webp}', { eager: true, import: 'default' }))
    .map(([path, url]) => [path.split('/').pop().replace(/\.\w+$/, ''), url]));

export const COUNTRIES = ['CH', 'DE', 'AT'];

// Time zones of the supported countries; Liechtenstein shops in Switzerland.
const ZONES = {
    'Europe/Zurich': 'CH', 'Europe/Vaduz': 'CH', 'Europe/Busingen': 'DE', 'Europe/Berlin': 'DE', 'Europe/Vienna': 'AT',
};

/**
 * Country of the device without asking for the location: time zone, then the region of the
 * browser languages, otherwise Switzerland.
 */
export function deviceCountry() {
    const zone = ZONES[Intl.DateTimeFormat().resolvedOptions().timeZone];
    if (zone) return zone;
    for (const language of navigator.languages ?? []) {
        const region = language.split('-')[1]?.toUpperCase();
        if (COUNTRIES.includes(region)) return region;
    }

    return 'CH';
}

/** URL of a shop's icon: the file of a standard shop or the own image (data URL), otherwise null. */
export function storeIconUrl(store) {
    const icon = store?.icon;
    if (!icon) return null;

    return icon.startsWith('data:image/') ? icon : ICONS[icon] ?? null;
}

/** Shop offered in a country (standard shops have countries, own ones none = everywhere). */
export function inCountry(store, country) {
    return !store.countries?.length || store.countries.includes(country);
}

// Usage of a tag fades with the age of the item: half of it after this many days.
const HALF_LIFE_DAYS = 30;

/**
 * Usage score per shop tag (normalized) from the items: every item counts, recent ones more.
 * Item ids are UUID v7, their first 48 bits are the creation time in ms.
 *
 * @returns {Map<string, number>}
 */
export function storeUsage(items, now = Date.now()) {
    const usage = new Map();
    for (const item of items) {
        if (!item.stores?.length) continue;
        const created = parseInt(item.id.replace(/-/g, '').slice(0, 12), 16);
        const days = Number.isFinite(created) ? Math.max(0, (now - created) / 86_400_000) : 365;
        // 1 for a new item, 0.5 after the half-life, never below 0.1 (counts still matter).
        const weight = Math.max(0.1, 0.5 ** (days / HALF_LIFE_DAYS));
        for (const tag of item.stores) {
            const key = normalizeName(tag);
            usage.set(key, (usage.get(key) ?? 0) + weight);
        }
    }

    return usage;
}
