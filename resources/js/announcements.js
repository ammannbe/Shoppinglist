// Announcements of the AccountManager (ANNOUNCEMENTS_DRIVER=account-manager): bell with unread counter, list
// "News" (#/news) and banner. GET /announcements returns the visible ones in the user's language with read_at and
// dismissed_at (ms); the last answer is kept on the device (localStorage), so the news are readable offline. Reading
// and dismissing work offline too: the marks are applied locally and sent with POST /announcements/read when online
// (retried on the next refresh, when online again and when the app comes to the foreground). Images are cached by
// the service worker (vite.config.js) and fetched once after a refresh.

export const ANNOUNCEMENTS_KEY = 'shoppinglist.announcements';
// Refresh on foreground at most this often.
const REFRESH_INTERVAL = 60_000;

const emptyPending = () => ({ read: [], dismissed: [] });

export function isExpired(announcement, now = Date.now()) {
    return Boolean(announcement.expires_at) && announcement.expires_at <= now;
}

/** Announcements of the list "News", newest first. */
export function newsItems(items, now = Date.now()) {
    return items.filter((item) => item.in_app && !isExpired(item, now)).sort((a, b) => b.published_at - a.published_at);
}

export function unreadCount(items, now = Date.now()) {
    return newsItems(items, now).filter((item) => !item.read_at).length;
}

/** The newest banner not dismissed yet (one at a time). */
export function bannerItem(items, now = Date.now()) {
    return items.filter((item) => item.banner && !item.dismissed_at && !isExpired(item, now))
        .sort((a, b) => b.published_at - a.published_at)[0] ?? null;
}

/**
 * Applies marks not known to the server yet (pending) to its announcements.
 */
export function withPending(items, pending, at = Date.now()) {
    const read = new Set(pending.read);
    const dismissed = new Set(pending.dismissed);

    return items.map((item) => ({
        ...item,
        read_at: item.read_at ?? (read.has(item.id) ? at : null),
        dismissed_at: item.dismissed_at ?? (dismissed.has(item.id) ? at : null),
    }));
}

/**
 * Pending marks after a successful send: the sent ids are removed, marks added meanwhile stay.
 */
export function afterSent(pending, sent) {
    return {
        read: pending.read.filter((id) => !sent.read.includes(id)),
        dismissed: pending.dismissed.filter((id) => !sent.dismissed.includes(id)),
    };
}

function readStored(storage) {
    try {
        return JSON.parse(storage.getItem(ANNOUNCEMENTS_KEY) ?? 'null');
    } catch {
        return null;
    }
}

export function clearAnnouncements(storage = globalThis.localStorage) {
    storage.removeItem(ANNOUNCEMENTS_KEY);
}

/**
 * Alpine store `announcements`.
 */
export function createAnnouncementsStore({ api, storage = globalThis.localStorage, online = () => navigator.onLine } = {}) {
    let sending = false;
    let refreshedAt = 0;
    const prefetched = new Set();

    return {
        // Logged-in user with announcements enabled (/me announcements).
        enabled: false,
        email: null,
        items: [],
        pending: emptyPending(),
        now: Date.now(),

        get news() {
            return newsItems(this.items, this.now);
        },
        get unread() {
            return unreadCount(this.items, this.now);
        },
        get banner() {
            return bannerItem(this.items, this.now);
        },

        /** Cached state of the user (offline start). */
        restore(user) {
            this.enabled = Boolean(user?.announcements);
            const stored = readStored(storage);
            if (!this.enabled || !stored || stored.email !== user.email) {
                this.email = user?.email ?? null;
                this.items = [];
                this.pending = emptyPending();
                return;
            }
            this.email = stored.email;
            this.pending = { ...emptyPending(), ...stored.pending };
            this.items = withPending(stored.items ?? [], this.pending);
        },

        save() {
            try {
                storage.setItem(ANNOUNCEMENTS_KEY, JSON.stringify({ email: this.email, items: this.items, pending: this.pending }));
            } catch {
                // Private mode or full storage: only the current session.
            }
        },

        /**
         * After /me: shows the cached state, then loads the current one.
         */
        async connect(user) {
            this.restore(user);
            if (!this.enabled) {
                clearAnnouncements(storage);
                return;
            }
            await this.refresh(true);
        },

        async refresh(force = false) {
            this.now = Date.now();
            if (!this.enabled || !online() || (!force && Date.now() - refreshedAt < REFRESH_INTERVAL)) return;
            refreshedAt = Date.now();
            await this.send();
            try {
                const { data } = await api.get('/announcements');
                this.items = withPending(data ?? [], this.pending);
                this.save();
                this.prefetchImages();
            } catch {
                // Offline or server down: the cached state stays.
            }
        },

        /** Loads the images once, so the service worker has them for offline use. */
        prefetchImages() {
            this.news.forEach(({ image_url: url }) => {
                if (!url || prefetched.has(url)) return;
                prefetched.add(url);
                fetch(url, { mode: 'no-cors', credentials: 'omit' }).catch(() => prefetched.delete(url));
            });
        },

        mark(kind, ids) {
            const field = kind === 'read' ? 'read_at' : 'dismissed_at';
            const fresh = ids.filter((id) => this.items.some((item) => item.id === id && !item[field]));
            if (!fresh.length) return;
            const at = Date.now();
            this.items = this.items.map((item) => (fresh.includes(item.id) ? { ...item, [field]: at } : item));
            this.pending = { ...this.pending, [kind]: [...new Set([...this.pending[kind], ...fresh])] };
            this.save();
            this.send();
        },

        /** Opened in "News": all shown ones are read. */
        markAllRead() {
            this.mark('read', this.news.filter((item) => !item.read_at).map((item) => item.id));
        },

        dismiss(id) {
            this.mark('dismissed', [id]);
        },

        async send() {
            const pending = this.pending;
            if (sending || !online() || (!pending.read.length && !pending.dismissed.length)) return;
            sending = true;
            try {
                await api.post('/announcements/read', pending);
                this.pending = afterSent(this.pending, pending);
                this.save();
            } catch {
                // Kept for the next try.
            } finally {
                sending = false;
            }
        },

        clear() {
            this.enabled = false;
            this.items = [];
            this.pending = emptyPending();
            clearAnnouncements(storage);
        },
    };
}
