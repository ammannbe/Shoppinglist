import Alpine from 'alpinejs';
import { deviceCountry } from './stores.js';

// App settings per user (server: PUT /preferences, GET /me). Cached on the device, so
// they work offline and for guests; changes made offline are sent with the next start.

const KEY = 'shoppinglist.prefs';
const PENDING_KEY = 'shoppinglist.prefs-pending';

// Same as PreferenceController::DEFAULTS.
const DEFAULTS = { row_actions: 'swipe', list_sort: 'name_asc', hidden_stores: [], country: null, regions: {} };

export function createPrefsStore(api) {
    const stored = JSON.parse(localStorage.getItem(KEY) ?? '{}');

    return {
        ...DEFAULTS,
        ...stored,

        async set(key, value) {
            this[key] = value;
            this.save();
            const pending = { ...JSON.parse(localStorage.getItem(PENDING_KEY) ?? '{}'), [key]: value };
            if (!Alpine.store('sync').user) return;
            try {
                await api.put('/preferences', pending);
                localStorage.removeItem(PENDING_KEY);
            } catch {
                localStorage.setItem(PENDING_KEY, JSON.stringify(pending));
            }
        },

        save() {
            localStorage.setItem(KEY, JSON.stringify(Object.fromEntries(Object.keys(DEFAULTS).map((key) => [key, this[key]]))));
        },

        /**
         * After login: send changes made offline, otherwise take the account's settings.
         */
        async connect(user) {
            const pending = JSON.parse(localStorage.getItem(PENDING_KEY) ?? 'null');
            if (pending) {
                const [key, value] = Object.entries(pending)[0] ?? [];
                if (key) await this.set(key, value);
                return;
            }
            Object.keys(DEFAULTS).forEach((key) => {
                if (user.preferences?.[key]) this[key] = user.preferences[key];
            });
            this.save();
            // New accounts get the country of the device (no location request), changeable in the settings.
            if (!this.country) await this.set('country', deviceCountry());
        },
    };
}
