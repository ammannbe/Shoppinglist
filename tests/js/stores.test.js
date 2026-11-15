import { describe, expect, it } from 'vitest';
import { inCountry, rankStores, storeIconUrl, storeUsage } from '../../resources/js/stores.js';

// UUID v7 with the creation time `ms`.
const idAt = (ms) => `${ms.toString(16).padStart(12, '0').replace(/(.{8})(.{4})/, '$1-$2')}-7000-8000-000000000000`;
const DAY = 86_400_000;
const NOW = Date.UTC(2026, 10, 1);

describe('storeUsage', () => {
    it('counts recent items more, never less than 0.1', () => {
        const usage = storeUsage([
            { id: idAt(NOW), stores: ['M'] },
            { id: idAt(NOW - 30 * DAY), stores: ['m', 'Volg'] },
            { id: idAt(NOW - 3650 * DAY), stores: ['C'] },
            { id: idAt(NOW), stores: [] },
        ], NOW);

        expect(usage.get('m')).toBeCloseTo(1.5);
        expect(usage.get('volg')).toBeCloseTo(0.5);
        expect(usage.get('c')).toBeCloseTo(0.1);
    });
});

describe('shops', () => {
    it('offers standard shops in their countries, own ones everywhere', () => {
        expect(inCountry({ countries: ['CH'] }, 'DE')).toBe(false);
        expect(inCountry({ countries: ['CH', 'DE'] }, 'DE')).toBe(true);
        expect(inCountry({ countries: [] }, 'AT')).toBe(true);
    });

    it('finds icons of standard shops and keeps own images', () => {
        // Small files are inlined as data URLs by Vite, larger ones are file URLs.
        expect(storeIconUrl({ icon: 'migros' })).toMatch(/migros.*\.png$|^data:image\/png/);
        expect(storeIconUrl({ icon: 'lidl' })).toMatch(/lidl.*\.svg$|^data:image\/svg/);
        expect(storeIconUrl({ icon: 'data:image/png;base64,AAAA' })).toBe('data:image/png;base64,AAAA');
        expect(storeIconUrl({ icon: 'unknown' })).toBeNull();
        expect(storeIconUrl({ icon: null })).toBeNull();
    });
});

describe('rankStores', () => {
    const stores = Array.from({ length: 20 }, (_, i) => ({ name: `Laden ${String(i + 1).padStart(2, '0')}`, code: '' }))
        .concat([{ name: 'Migros', code: 'M' }, { name: 'Coop', code: 'C' }, { name: 'Volg', code: '' }]);

    it('lists every shop without a search: most used first, then by name', () => {
        const ranked = rankStores(stores, '', new Map([['m', 3], ['volg', 1]]));

        expect(ranked).toHaveLength(stores.length);
        expect(ranked.slice(0, 4).map((store) => store.name)).toEqual(['Migros', 'Volg', 'Coop', 'Laden 01']);
    });

    it('finds by code or name: exact, start, anywhere, and keeps all matches', () => {
        expect(rankStores(stores, 'm', new Map()).map((store) => store.name)).toEqual(['Migros']);
        expect(rankStores(stores, 'laden', new Map())).toHaveLength(20);
        expect(rankStores(stores, 'olg', new Map()).map((store) => store.name)).toEqual(['Volg']);
    });
});
