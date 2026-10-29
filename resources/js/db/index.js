import { addRxPlugin, createRxDatabase } from 'rxdb/plugins/core';
import { RxDBMigrationSchemaPlugin } from 'rxdb/plugins/migration-schema';
import { replicateRxCollection } from 'rxdb/plugins/replication';
import { Subject, map } from 'rxjs';
import * as schemas from './schemas.js';

// Local documents are migrated when a schema version increases (schemas.migrations).
addRxPlugin(RxDBMigrationSchemaPlugin);

export const SYNCED_COLLECTIONS = ['lists', 'items', 'products', 'units'];

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
    for (const collection of ['lists', 'items', 'products', 'units']) {
        db[collection].preSave((data, doc) => {
            data.version = (doc.version ?? 0) + 1;
        }, false);
    }

    return db;
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
        replicationIdentifier: `shoppinglist-${collection}-v1`,
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
