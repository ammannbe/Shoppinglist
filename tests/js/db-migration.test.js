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

    it('migrates lists stored before the pantry lists to shopping lists', async () => {
        const storage = getRxStorageMemory();
        const name = `migrationlists${Date.now()}`;

        // Lists schema version 3 (before the kind).
        const old = await createRxDatabase({ name, storage, multiInstance: false });
        const { kind, ...properties } = schemas.lists.properties;
        await old.addCollections({
            ...Object.fromEntries(['items', 'products', 'units', 'stores'].map((collection) => [collection, { schema: schemas[collection], migrationStrategies: schemas.migrations[collection] }])),
            lists: {
                schema: { ...schemas.lists, version: 3, properties },
                migrationStrategies: Object.fromEntries([1, 2, 3].map((v) => [v, schemas.migrations.lists[v]])),
            },
        });
        await old.lists.insert({ id: '0190a0b0-0000-7000-8000-000000000003', name: 'Migros', item_sort: null, members: [], households: [], position: 1, version: 2, updated_at: null });
        await old.close();

        const db = await createDatabase({ storage, name, multiInstance: false });
        const list = (await db.lists.findOne('0190a0b0-0000-7000-8000-000000000003').exec()).toJSON();
        expect(list).toMatchObject({ name: 'Migros', kind: 'shopping', version: 2 });
        await db.remove();
    });

    it('migrates products stored before the categories', async () => {
        const storage = getRxStorageMemory();
        const name = `migrationproducts${Date.now()}`;

        // Products schema version 1 (before the category).
        const old = await createRxDatabase({ name, storage, multiInstance: false });
        const { category, ...properties } = schemas.products.properties;
        await old.addCollections({
            ...Object.fromEntries(['lists', 'items', 'units', 'stores'].map((collection) => [collection, { schema: schemas[collection], migrationStrategies: schemas.migrations[collection] }])),
            products: {
                schema: { ...schemas.products, version: 1, properties },
                migrationStrategies: { 1: schemas.migrations.products[1] },
            },
        });
        await old.products.insert({ id: '0190a0b0-0000-7000-8000-000000000004', name: 'Tofu', image: null, is_public: false, version: 2, updated_at: null });
        await old.close();

        const db = await createDatabase({ storage, name, multiInstance: false });
        const product = (await db.products.findOne('0190a0b0-0000-7000-8000-000000000004').exec()).toJSON();
        expect(product).toMatchObject({ name: 'Tofu', category: null, version: 2 });
        await db.remove();
    });
});
