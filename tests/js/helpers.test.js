import { describe, expect, it } from 'vitest';
import { csvFirstColumn, csvValue, mergedAmount, normalizeName, offerMatches, parseItemInput, sameName, seasonalFood, shortDate, splitItemInput, suggestNames } from '../../resources/js/helpers.js';

const UNITS = ['l', 'kg', 'g', 'Stk.'];

describe('parseItemInput', () => {
    it('reads amount, unit and name', () => {
        expect(parseItemInput('2 l Milch', UNITS)).toEqual({ name: 'Milch', amount: 2, unit: 'l' });
        expect(parseItemInput('1,5 KG  Kartoffeln', UNITS)).toEqual({ name: 'Kartoffeln', amount: 1.5, unit: 'kg' });
    });

    it('keeps unknown words in the name', () => {
        expect(parseItemInput('3 grosse Äpfel', UNITS)).toEqual({ name: 'grosse Äpfel', amount: 3, unit: null });
        expect(parseItemInput('Brot', UNITS)).toEqual({ name: 'Brot', amount: null, unit: null });
        // A unit alone is the name.
        expect(parseItemInput('2 l', UNITS)).toEqual({ name: 'l', amount: 2, unit: null });
    });
});

describe('splitItemInput', () => {
    it('separates the amount and unit from the query', () => {
        expect(splitItemInput('2 l Mil', UNITS)).toMatchObject({ prefix: '2 l ', query: 'Mil', amount: true, unit: true });
        expect(splitItemInput('Mil', UNITS)).toMatchObject({ prefix: '', query: 'Mil', amount: false });
    });
});

describe('names', () => {
    it('compares accents only when searching', () => {
        expect(normalizeName('  Äpfel ')).toBe('apfel');
        expect(sameName('Apfel', ' apfel')).toBe(true);
        expect(sameName('Apfel', 'Äpfel')).toBe(false);
    });

    it('ranks exact and prefix matches first', () => {
        expect(suggestNames('milch', ['Kokosmilch', 'Milchreis', 'Milch'])).toEqual(['Milch', 'Milchreis', 'Kokosmilch']);
    });
});

describe('mergedAmount', () => {
    it('adds amounts of the same unit only', () => {
        expect(mergedAmount({ amount: 1, unit: 'l' }, { amount: 0.5, unit: 'L' })).toBe(1.5);
        expect(mergedAmount({ amount: null, unit: null }, { amount: null, unit: null })).toBe(2);
        expect(mergedAmount({ amount: 1, unit: 'l' }, { amount: 500, unit: 'g' })).toBeNull();
    });

    it('converts g/kg and ml/cl/dl/l into the open item\'s unit', () => {
        expect(mergedAmount({ amount: 1, unit: 'kg' }, { amount: 250, unit: 'g' })).toBe(1.25);
        expect(mergedAmount({ amount: 300, unit: 'g' }, { amount: 0.5, unit: 'kg' })).toBe(800);
        expect(mergedAmount({ amount: 5, unit: 'dl' }, { amount: 1, unit: 'l' })).toBe(15);
        expect(mergedAmount({ amount: 2, unit: 'dl' }, { amount: 50, unit: 'ml' })).toBe(2.5);
        // Not without both amounts, and not between weight and volume or pieces.
        expect(mergedAmount({ amount: null, unit: 'kg' }, { amount: 250, unit: 'g' })).toBeNull();
        expect(mergedAmount({ amount: 1, unit: 'kg' }, { amount: 1, unit: 'l' })).toBeNull();
        expect(mergedAmount({ amount: 1, unit: 'kg' }, { amount: 2, unit: 'Stk.' })).toBeNull();
    });
});

describe('CSV', () => {
    it('reads the first column of exports from Excel, Numbers and this app', () => {
        const text = '﻿name;Kategorie\r\n"Bio-Hafer, fein";x\r\nMilch\r\n\r\n  Rote   Bohnen ,y\n"Er sagt ""Hallo"""';
        expect(csvFirstColumn(text)).toEqual(['Bio-Hafer, fein', 'Milch', 'Rote Bohnen', 'Er sagt "Hallo"']);
        expect(csvFirstColumn('Milch\nBrot')).toEqual(['Milch', 'Brot']);
    });

    it('quotes values when needed and reads them back', () => {
        const names = ['Milch', 'Bio-Hafer, fein', 'A;B', 'Er sagt "Hallo"'];
        expect(names.map(csvValue)).toEqual(['Milch', '"Bio-Hafer, fein"', '"A;B"', '"Er sagt ""Hallo"""']);
        expect(csvFirstColumn(['name', ...names.map(csvValue)].join('\r\n'))).toEqual(names);
    });
});

describe('seasonalFood', () => {
    it('finds the food a product stands for, the longest match wins', () => {
        const foods = ['Tomaten', 'Lauch', 'Knoblauch', 'Zucchetti'];
        expect(seasonalFood('Cherrytomaten', foods)).toBe('Tomaten');
        expect(seasonalFood('zucchetti', foods)).toBe('Zucchetti');
        expect(seasonalFood('Knoblauch', foods)).toBe('Knoblauch');
        expect(seasonalFood('Milch', foods)).toBeNull();
        expect(seasonalFood('Tomaten', [])).toBeNull();
    });
});

describe('shortDate', () => {
    it('shows day and month of a date or a time', () => {
        expect(shortDate('2026-11-06', 'de_CH')).toBe('6.11.');
        expect(shortDate('2026-11-06T10:00:00+01:00', 'de_CH')).toBe('6.11.');
        expect(shortDate(null, 'de_CH')).toBe('');
        expect(shortDate('nonsense', 'de_CH')).toBe('');
    });
});

describe('offerMatches', () => {
    const offer = { name: 'Bio Äpfel Gala', brand: 'Naturaplan' };

    it('finds every word in name or brand, case and accents ignored', () => {
        expect(offerMatches(offer, 'apfel')).toBe(true);
        expect(offerMatches(offer, '  GALA  natura ')).toBe(true);
        expect(offerMatches(offer, 'apfel birne')).toBe(false);
        expect(offerMatches({ name: 'Rüebli', brand: null }, 'ruebli')).toBe(true);
        expect(offerMatches({ name: 'Rüebli', brand: null }, 'rubli')).toBe(false);
    });

    it('matches all with an empty search', () => {
        expect(offerMatches(offer, '')).toBe(true);
        expect(offerMatches(offer, '   ')).toBe(true);
    });
});
