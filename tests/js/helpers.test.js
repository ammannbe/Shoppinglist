import { describe, expect, it } from 'vitest';
import { csvFirstColumn, csvValue, mergedAmount, normalizeName, parseItemInput, sameName, splitItemInput, suggestNames } from '../../resources/js/helpers.js';

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
