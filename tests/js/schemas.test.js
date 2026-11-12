import { describe, expect, it } from 'vitest';
import * as schemas from '../../resources/js/db/schemas.js';

describe('local migrations', () => {
    it('exist for every schema version', () => {
        for (const [collection, strategies] of Object.entries(schemas.migrations)) {
            const version = schemas[collection].version;
            for (let v = 1; v <= version; v++) expect(strategies[v], `${collection} ${v}`).toBeTypeOf('function');
        }
    });

    it('keep values the document already has', () => {
        // An old app version may already have stored fields of the new schema (pulled from the server).
        const store = { id: 'x', name: 'Migros', icon: 'migros', countries: ['CH'] };
        expect(schemas.migrations.stores[2](store)).toMatchObject({ icon: 'migros', countries: ['CH'] });
        expect(schemas.migrations.stores[2]({ id: 'y', name: 'Eigener' })).toMatchObject({ icon: null, countries: [] });
        const household = { id: 'h', name: 'Familie Muster' };
        expect(schemas.migrations.lists[3]({ id: 'l', name: 'Coop', households: [household] })).toMatchObject({ households: [household] });
        expect(schemas.migrations.lists[3]({ id: 'l', name: 'Coop' })).toMatchObject({ households: [] });
        expect(schemas.migrations.lists[4]({ id: 'l', name: 'Coop' })).toMatchObject({ kind: 'shopping' });
        expect(schemas.migrations.lists[4]({ id: 'l', name: 'Küche', kind: 'pantry' })).toMatchObject({ kind: 'pantry' });
        expect(schemas.migrations.products[2]({ id: 'p', name: 'Milch' })).toMatchObject({ category: null });
        expect(schemas.migrations.products[2]({ id: 'p', name: 'Milch', category: 'dairy' })).toMatchObject({ category: 'dairy' });
    });

    it('turn the single shop of an item into a list', () => {
        expect(schemas.migrations.items[4]({ id: 'i', store: 'M' })).toEqual({ id: 'i', stores: ['M'], recipes: [] });
        expect(schemas.migrations.items[4]({ id: 'i', store: null })).toEqual({ id: 'i', stores: [], recipes: [] });
    });

    it('give the recipes of items a key, keeping pulled ones', () => {
        expect(schemas.migrations.items[5]({ id: 'i', recipes: [{ name: 'Lasagne', url: null }, { name: 'Salat', url: null, key: 'r2' }] }))
            .toEqual({ id: 'i', recipes: [{ name: 'Lasagne', url: null, key: null }, { name: 'Salat', url: null, key: 'r2' }] });
        expect(schemas.migrations.items[5]({ id: 'i' })).toEqual({ id: 'i', recipes: [] });
    });
});
