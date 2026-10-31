import { describe, expect, it } from 'vitest';
import { inCountry, storeIconUrl, storeUsage } from '../../resources/js/stores.js';

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
