import { addRxPlugin, createRxDatabase, removeRxDatabase } from 'rxdb/plugins/core';
import { RxDBMigrationSchemaPlugin } from 'rxdb/plugins/migration-schema';
import { replicateRxCollection } from 'rxdb/plugins/replication';
import { Subject, map } from 'rxjs';
import * as schemas from './schemas.js';

// Local documents are migrated when a schema version increases (schemas.migrations).
addRxPlugin(RxDBMigrationSchemaPlugin);

export const SYNCED_COLLECTIONS = ['lists', 'items', 'products', 'units', 'stores'];

// Replication run per collection (default 1). After a deploy the old app version keeps syncing
// until the update is accepted: it pulls server fields it does not know yet and its checkpoint
// moves past them. When a schema version adds fields the server already fills, increase the
// collection's number here as well, so every client pulls the collection once more.
// stores 2: icon and countries (schema version 2).
const REPLICATION_RUNS = { stores: 2 };

/**
 * Local database. Everything the UI shows is read from here, the
 * replication syncs it with the server whenever a connection is available.
 *
 * @param {object} options
 * @param {import('rxdb').RxStorage} options.storage Dexie (IndexedDB) in the browser
 */
export async function createDatabase({ storage, name = 'shoppinglist', multiInstance = true }) {
    const db = await createRxDatabase({
        name,
        storage,
        multiInstance,
        closeDuplicates: true,
    });

    await db.addCollections(Object.fromEntries(
        SYNCED_COLLECTIONS.map((collection) => [collection, { schema: schemas[collection], migrationStrategies: schemas.migrations[collection] ?? {} }]),
    ));

    // Every local change gets a new version, the server detects conflicts with it.
    for (const collection of SYNCED_COLLECTIONS) {
        db[collection].preSave((data, doc) => {
            data.version = (doc.version ?? 0) + 1;
        }, false);
    }

    return db;
}

/**
 * Deletes the local database (last resort when it cannot be opened anymore).
 */
export function removeDatabase({ storage, name = 'shoppinglist' }) {
    return removeRxDatabase(name, storage);
}

/**
 * Starts the live replication of all collections (RxDB HTTP replication).
 *
 * @param {import('rxdb').RxDatabase} db
 * @param {ReturnType<import('../api.js').createApi>} api
 */
export function startReplication(db, api, { retryTime = 5000 } = {}) {
    const resync$ = new Subject();

    const states = SYNCED_COLLECTIONS.map((collection) => replicateRxCollection({
        collection: db[collection],
        replicationIdentifier: `shoppinglist-${collection}-v${REPLICATION_RUNS[collection] ?? 1}`,
        live: true,
        retryTime,
        waitForLeadership: true,
        pull: {
            batchSize: 100,
            handler: (checkpoint, batchSize) => api.get(`/sync/${collection}/pull?${query({ ...checkpoint, limit: batchSize })}`),
            // The server has no push channel: re-check on demand (online, app visible, interval).
            stream$: resync$.pipe(map(() => 'RESYNC')),
        },
        push: {
            batchSize: 50,
            handler: (rows) => api.post(`/sync/${collection}/push`, rows),
        },
    }));

    return {
        states,
        resync: () => resync$.next(),
        awaitInSync: () => Promise.all(states.map((state) => state.awaitInSync())),
        cancel: () => Promise.all(states.map((state) => state.cancel())),
    };
}

function query(params) {
    return new URLSearchParams(Object.entries(params).filter(([, value]) => value !== null && value !== undefined));
}
