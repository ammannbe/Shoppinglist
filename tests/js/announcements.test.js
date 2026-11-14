import { describe, expect, it, vi } from 'vitest';
import { afterSent, ANNOUNCEMENTS_KEY, bannerItem, createAnnouncementsStore, newsItems, unreadCount, withPending } from '../../resources/js/announcements.js';

const now = Date.UTC(2026, 10, 14, 12);
const item = (id, extra = {}) => ({ id, title: id, in_app: true, banner: false, published_at: now - 1000, expires_at: null, read_at: null, dismissed_at: null, image_url: null, ...extra });

function memoryStorage(initial = {}) {
    const data = { ...initial };

    return {
        getItem: (key) => data[key] ?? null,
        setItem: (key, value) => { data[key] = String(value); },
        removeItem: (key) => { delete data[key]; },
        data,
    };
}

describe('newsItems and unreadCount', () => {
    it('lists in-app announcements newest first and hides expired ones', () => {
        const items = [
            item('old', { published_at: now - 5000 }),
            item('new', { published_at: now - 100 }),
            item('banner-only', { in_app: false, banner: true }),
            item('expired', { expires_at: now - 1 }),
        ];

        expect(newsItems(items, now).map((each) => each.id)).toEqual(['new', 'old']);
        expect(unreadCount(items, now)).toBe(2);
        expect(unreadCount([...items, item('read', { read_at: now })], now)).toBe(2);
    });
});

describe('bannerItem', () => {
    it('shows the newest banner that was not dismissed', () => {
        const items = [
            item('a', { banner: true, published_at: now - 5000 }),
            item('b', { banner: true, published_at: now - 100, dismissed_at: now }),
            item('c', { banner: true, published_at: now - 200, expires_at: now - 1 }),
            item('d', { published_at: now }),
        ];

        expect(bannerItem(items, now)?.id).toBe('a');
        expect(bannerItem([item('x')], now)).toBeNull();
    });
});

describe('pending marks', () => {
    it('applies marks the server does not know yet', () => {
        const [a, b] = withPending([item('a'), item('b', { read_at: 5 })], { read: ['a', 'b'], dismissed: ['b'] }, 42);

        expect(a.read_at).toBe(42);
        expect(a.dismissed_at).toBeNull();
        expect(b.read_at).toBe(5);
        expect(b.dismissed_at).toBe(42);
    });

    it('keeps marks added while sending', () => {
        expect(afterSent({ read: ['a', 'b'], dismissed: ['c'] }, { read: ['a'], dismissed: ['c'] })).toEqual({ read: ['b'], dismissed: [] });
    });
});

describe('createAnnouncementsStore', () => {
    const user = { email: 'anna@example.com', announcements: { emails: 'maintenance' } };

    it('loads the announcements, caches them and marks them read offline', async () => {
        const storage = memoryStorage();
        let online = true;
        const api = { get: vi.fn(async () => ({ data: [item('a'), item('b', { banner: true })] })), post: vi.fn(async () => null) };
        const store = createAnnouncementsStore({ api, storage, online: () => online });

        await store.connect(user);
        expect(api.get).toHaveBeenCalledWith('/announcements');
        expect(store.unread).toBe(2);
        expect(store.banner.id).toBe('b');

        online = false;
        store.markAllRead();
        store.dismiss('b');
        expect(store.unread).toBe(0);
        expect(store.banner).toBeNull();
        expect(api.post).not.toHaveBeenCalled();

        // Next start offline: the cached state with the marks.
        const offline = createAnnouncementsStore({ api, storage, online: () => false });
        offline.restore(user);
        expect(offline.news.map((each) => each.id)).toEqual(['a', 'b']);
        expect(offline.unread).toBe(0);
        expect(offline.pending).toEqual({ read: ['a', 'b'], dismissed: ['b'] });

        // Back online: the marks are sent first, the server's answer keeps them.
        online = true;
        api.get.mockResolvedValueOnce({ data: [item('a'), item('b', { banner: true })] });
        await store.refresh(true);
        expect(api.post).toHaveBeenCalledWith('/announcements/read', { read: ['a', 'b'], dismissed: ['b'] });
        expect(store.pending).toEqual({ read: [], dismissed: [] });
        expect(JSON.parse(storage.data[ANNOUNCEMENTS_KEY]).pending).toEqual({ read: [], dismissed: [] });
    });

    it('keeps the cached state when the server is not reachable', async () => {
        const storage = memoryStorage({ [ANNOUNCEMENTS_KEY]: JSON.stringify({ email: user.email, items: [item('a')], pending: { read: [], dismissed: [] } }) });
        const api = { get: vi.fn(async () => { throw new Error('offline'); }), post: vi.fn() };
        const store = createAnnouncementsStore({ api, storage, online: () => true });

        await store.connect(user);

        expect(store.news.map((each) => each.id)).toEqual(['a']);
    });

    it('does not show another account\'s cache and clears it without announcements', async () => {
        const storage = memoryStorage({ [ANNOUNCEMENTS_KEY]: JSON.stringify({ email: 'max@example.com', items: [item('a')], pending: { read: [], dismissed: [] } }) });
        const api = { get: vi.fn(async () => ({ data: [] })), post: vi.fn() };
        const store = createAnnouncementsStore({ api, storage, online: () => false });

        store.restore(user);
        expect(store.items).toEqual([]);

        await store.connect({ email: 'max@example.com', announcements: null });
        expect(store.enabled).toBe(false);
        expect(storage.data[ANNOUNCEMENTS_KEY]).toBeUndefined();
        expect(api.get).not.toHaveBeenCalled();
    });
});
