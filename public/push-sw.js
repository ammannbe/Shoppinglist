// Web Push, loaded into the generated service worker (vite.config.js, workbox.importScripts).
// Payload: WebPushMessage of the server (App\Notifications\AnnouncementPush). Same file as in Chorelist.

self.addEventListener('push', (event) => {
    let message = {};
    try {
        message = event.data?.json() ?? {};
    } catch {
        message = { title: event.data?.text() ?? 'Shoppinglist' };
    }
    const { title = 'Shoppinglist', ...options } = message;

    event.waitUntil(self.registration.showNotification(title, options));
});

// Opens the app at the notification's page, reusing an open window.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url ?? '/', self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const open = windows.find((client) => new URL(client.url).origin === self.location.origin);
        if (open) {
            await open.focus();
            return open.navigate(url).catch(() => open.postMessage({ type: 'navigate', url }));
        }

        return self.clients.openWindow(url);
    })());
});
