// RxDB schemas, mirroring the documents of app/Sync/*Resource.php.
// `version` is increased on every local change (see db/index.js), `updated_at` is set by the server.

const uuid = { type: 'string', maxLength: 36 };
const syncFields = {
    version: { type: 'integer', minimum: 1, default: 1 },
    updated_at: { type: ['string', 'null'] },
};

export const lists = {
    version: 0,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        name: { type: 'string', maxLength: 255 },
        owner_email: { type: ['string', 'null'] },
        is_owner: { type: 'boolean', default: true },
        members: { type: 'array', items: { type: 'string' }, default: [] },
        ...syncFields,
    },
    required: ['id', 'name'],
};

export const items = {
    version: 0,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        shopping_list_id: uuid,
        name: { type: 'string', maxLength: 255 },
        amount: { type: ['number', 'null'] },
        unit: { type: ['string', 'null'] },
        done: { type: 'boolean', default: false },
        creator_email: { type: ['string', 'null'] },
        ...syncFields,
    },
    required: ['id', 'shopping_list_id', 'name'],
    indexes: ['shopping_list_id'],
};

export const products = {
    version: 0,
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

export const units = {
    version: 0,
    primaryKey: 'id',
    type: 'object',
    properties: {
        id: uuid,
        name: { type: 'string', maxLength: 255 },
        updated_at: { type: ['string', 'null'] },
    },
    required: ['id', 'name'],
};
