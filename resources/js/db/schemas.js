// RxDB schemas, mirroring the documents of app/Sync/*Resource.php.
// `version` is increased on every local change (see db/index.js), `updated_at` is set by the server.

const uuid = { type: 'string', maxLength: 36 };
const syncFields = {
    version: { type: 'integer', minimum: 1, default: 1 },
    updated_at: { type: ['string', 'null'] },
};

export const lists = {
    version: 2,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        name: { type: 'string', maxLength: 255 },
        // Sort of the items: manual, name_asc, name_desc, newest, oldest (null: manual).
        item_sort: { type: ['string', 'null'] },
        owner_email: { type: ['string', 'null'] },
        is_owner: { type: 'boolean', default: true },
        members: { type: 'array', items: { type: 'string' }, default: [] },
        // Manual order, personal per member (null: sorted by name after the positioned lists).
        position: { type: ['number', 'null'] },
        ...syncFields,
    },
    required: ['id', 'name'],
};

export const items = {
    version: 3,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        shopping_list_id: uuid,
        name: { type: 'string', maxLength: 255 },
        amount: { type: ['number', 'null'] },
        unit: { type: ['string', 'null'] },
        // Shop code (M, A, AMZ, …, see stores) and free note (e.g. the offer).
        store: { type: ['string', 'null'] },
        note: { type: ['string', 'null'] },
        // Image of the product (copied when added, so members of shared lists see it too).
        image: { type: ['string', 'null'] },
        done: { type: 'boolean', default: false },
        // Manual order, shared by all members (null: in creation order after the positioned items).
        position: { type: ['number', 'null'] },
        creator_email: { type: ['string', 'null'] },
        ...syncFields,
    },
    required: ['id', 'shopping_list_id', 'name'],
    indexes: ['shopping_list_id'],
};

export const products = {
    version: 1,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        name: { type: 'string', maxLength: 255 },
        // Image id, uploaded via POST /products/{id}/image (see ImageController).
        image: { type: ['string', 'null'] },
        is_public: { type: 'boolean', default: false },
        ...syncFields,
    },
    required: ['id', 'name'],
};

export const units = {
    version: 1,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        name: { type: 'string', maxLength: 255 },
        is_public: { type: 'boolean', default: false },
        ...syncFields,
    },
    required: ['id', 'name'],
};

export const stores = {
    version: 0,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        name: { type: 'string', maxLength: 255 },
        // Short code shown on the items (items.store keeps the code).
        code: { type: 'string', maxLength: 10 },
        is_public: { type: 'boolean', default: false },
        ...syncFields,
    },
    required: ['id', 'name', 'code'],
};

// Schema migrations of the local documents, keyed by collection and schema version.
export const migrations = {
    lists: {
        1: (doc) => ({ ...doc, position: null, item_sort: null }),
        // Version 1 briefly existed without item_sort (development builds only).
        2: (doc) => ({ ...doc, item_sort: doc.item_sort ?? null }),
    },
    items: {
        1: (doc) => ({ ...doc, position: null, image: null }),
        // Version 1 briefly existed without image (development builds only).
        2: (doc) => ({ ...doc, image: doc.image ?? null }),
        3: (doc) => ({ ...doc, store: null, note: null }),
    },
    products: { 1: (doc) => ({ ...doc, image: null }) },
    // Units were pull only, all of them public.
    units: { 1: (doc) => ({ ...doc, is_public: true, version: 1 }) },
};
