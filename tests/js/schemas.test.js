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
    });

    it('turn the single shop of an item into a list', () => {
        expect(schemas.migrations.items[4]({ id: 'i', store: 'M' })).toEqual({ id: 'i', stores: ['M'], recipes: [] });
        expect(schemas.migrations.items[4]({ id: 'i', store: null })).toEqual({ id: 'i', stores: [], recipes: [] });
    });
});
