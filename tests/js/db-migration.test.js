import { createRxDatabase } from 'rxdb/plugins/core';
import { getRxStorageMemory } from 'rxdb/plugins/storage-memory';
import { describe, expect, it } from 'vitest';
import { createDatabase } from '../../resources/js/db/index.js';
import * as schemas from '../../resources/js/db/schemas.js';

describe('local database upgrade', () => {
    it('migrates items stored by the previous app version', async () => {
        const storage = getRxStorageMemory();
        const name = `migration${Date.now()}`;

        // Items schema version 4 (before the recipe links).
        const old = await createRxDatabase({ name, storage, multiInstance: false });
        const recipeItem = { type: 'object', properties: { name: { type: 'string' }, url: { type: ['string', 'null'] } }, required: ['name'] };
        await old.addCollections({
            ...Object.fromEntries(['lists', 'products', 'units', 'stores'].map((collection) => [collection, { schema: schemas[collection], migrationStrategies: schemas.migrations[collection] }])),
            items: {
                schema: { ...schemas.items, version: 4, properties: { ...schemas.items.properties, recipes: { type: 'array', default: [], items: recipeItem } } },
                migrationStrategies: Object.fromEntries([1, 2, 3, 4].map((v) => [v, schemas.migrations.items[v]])),
            },
        });
        await old.items.insert({
            id: '0190a0b0-0000-7000-8000-000000000001', shopping_list_id: '0190a0b0-0000-7000-8000-000000000002', name: 'Hackfleisch',
            amount: 500, unit: 'g', stores: ['M'], note: null, recipes: [{ name: 'Lasagne', url: 'https://recipes.test/5' }],
            image: null, done: false, position: 1, creator_email: null, version: 3, updated_at: null,
        });
        await old.close();

        const db = await createDatabase({ storage, name, multiInstance: false });
        const item = (await db.items.findOne('0190a0b0-0000-7000-8000-000000000001').exec()).toJSON();
        expect(item).toMatchObject({ name: 'Hackfleisch', stores: ['M'], version: 3, recipes: [{ name: 'Lasagne', url: 'https://recipes.test/5', key: null }] });
        await db.remove();
    });
});
