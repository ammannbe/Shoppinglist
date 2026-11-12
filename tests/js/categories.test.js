import { describe, expect, it } from 'vitest';
import { CATEGORIES, categoryOrder, categoryResolver, groupByCategory, moveCategory, sortByCategory } from '../../resources/js/categories.js';
import { compareBy } from '../../resources/js/helpers.js';

const PRODUCTS = [
    { id: '1', name: 'Milch', category: 'dairy', is_public: true },
    { id: '2', name: 'Äpfel', category: 'fruit', is_public: true },
    { id: '3', name: 'Rahm', category: 'dairy', is_public: true },
    { id: '4', name: 'Eis', category: 'frozen', is_public: true },
    { id: '5', name: 'Butter', category: 'dairy', is_public: true },
    // Own copy with the photo only: keeps the standard category.
    { id: '6', name: 'rahm', category: null, image: 'x', is_public: false },
    // Own copy with an own category.
    { id: '7', name: ' butter ', category: 'spreads', is_public: false },
    { id: '8', name: 'Tofu', category: 'pantry', is_public: false },
    { id: '9', name: 'Grissini', category: null, is_public: false },
];

describe('categoryOrder', () => {
    it('appends missing categories in the default order', () => {
        expect(categoryOrder([])).toEqual(CATEGORIES);
        expect(categoryOrder(null)).toEqual(CATEGORIES);
        const order = categoryOrder(['bread', 'unknown', 'fruit', 'bread']);
        expect(order.slice(0, 3)).toEqual(['bread', 'fruit', 'vegetables']);
        expect(order).toHaveLength(CATEGORIES.length);
    });

    it('starts with the walking order of a shop', () => {
        expect(CATEGORIES.slice(0, 4)).toEqual(['fruit', 'vegetables', 'salad', 'herbs']);
        expect(CATEGORIES.at(-1)).toBe('other');
    });

    it('moves a category up and down', () => {
        expect(moveCategory(['a', 'b', 'c'], 'c', -1)).toEqual(['a', 'c', 'b']);
        expect(moveCategory(['a', 'b', 'c'], 'a', 1)).toEqual(['b', 'a', 'c']);
        expect(moveCategory(['a', 'b', 'c'], 'a', -1)).toEqual(['a', 'b', 'c']);
    });
});

describe('categoryResolver', () => {
    const categoryOf = categoryResolver(PRODUCTS);

    it('takes the category of the product with the same name, own ones first', () => {
        expect(categoryOf(' MILCH ')).toBe('dairy');
        expect(categoryOf('Butter')).toBe('spreads');
        expect(categoryOf('Rahm')).toBe('dairy');
        expect(categoryOf('tofu')).toBe('pantry');
        expect(categoryOf('Eis')).toBe('frozen');
    });

    it('finds product names and keywords in longer names', () => {
        expect(categoryOf('Bio Äpfel')).toBe('fruit');
        expect(categoryOf('Vollmilch')).toBe('dairy');
        expect(categoryOf('Vollkornbrot')).toBe('bread');
        expect(categoryOf('Bergkäse')).toBe('cheese');
        expect(categoryOf('Rinderhackfleisch')).toBe('meat_fish');
        expect(categoryOf('Kartoffeln')).toBe('vegetables');
    });

    it('prefers the end of a compound and the longest match', () => {
        expect(categoryOf('Tomatensauce')).toBe('spices_sauces');
        expect(categoryOf('Orangensaft')).toBe('drinks');
        expect(categoryOf('Kokosmilch')).toBe('pantry');
        expect(categoryOf('Zahnpasta')).toBe('personal_care');
        expect(categoryOf('Milchschokolade')).toBe('sweets_snacks');
        expect(categoryOf('Joghurt Erdbeere')).toBe('dairy');
        // Short product names only count as the whole name.
        expect(categoryOf('Reis')).toBe('pantry');
    });

    it('falls back to other', () => {
        expect(categoryOf('Grissini')).toBe('other');
        expect(categoryOf('Geschenk für Anna')).toBe('other');
        expect(categoryResolver([])('Milch')).toBe('dairy');
    });
});

describe('sorting by category', () => {
    const byName = (a, b) => a.name.localeCompare(b.name, 'de');
    const categoryOf = categoryResolver(PRODUCTS);
    const items = [
        { id: '1', name: 'Vollmilch' },
        { id: '2', name: 'Zitronen' },
        { id: '3', name: 'Äpfel' },
        { id: '4', name: 'Grissini' },
        { id: '5', name: 'Brot' },
    ];

    it('groups A–Z items in the order of the categories', () => {
        const sorted = sortByCategory([...items].sort(compareBy('category', byName)), categoryOf, categoryOrder([]));
        expect(sorted.map((item) => item.name)).toEqual(['Äpfel', 'Zitronen', 'Brot', 'Vollmilch', 'Grissini']);

        const groups = groupByCategory(sorted, categoryOf, categoryOrder(['dairy']));
        expect(groups.map((group) => group.key)).toEqual(['dairy', 'fruit', 'bread', 'other']);
        expect(groups[1].items.map((item) => item.name)).toEqual(['Äpfel', 'Zitronen']);
    });
});
