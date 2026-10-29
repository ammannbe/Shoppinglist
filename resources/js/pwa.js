import { registerSW } from 'virtual:pwa-register';

const CHECK_INTERVAL = 60 * 60 * 1000;

/**
 * The service worker caches the app shell and assets, so the app starts offline.
 * Browsers only look for a new version on navigation (and installed apps rarely
 * navigate), so check actively: when the app comes to the foreground, when the
 * connection is back and every hour.
 *
 * @param {(apply: () => Promise<void>) => void} onNeedRefresh called when a new version is ready
 */
export function registerServiceWorker(onNeedRefresh) {
    if (!('serviceWorker' in navigator)) return;

    registerSW({
        immediate: true,
        onNeedRefresh: () => onNeedRefresh(activateNewVersion),
        onRegisteredSW(swUrl, registration) {
            if (!registration) return;

            const check = () => {
                if (navigator.onLine && !registration.installing) registration.update().catch(() => {});
            };
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') check();
            });
            window.addEventListener('online', check);
            setInterval(check, CHECK_INTERVAL);
        },
    });
}

/**
 * Activate the waiting service worker and reload once it controls the page.
 * If it is already active (e.g. activated by another tab), just reload.
 */
async function activateNewVersion() {
    const registration = await navigator.serviceWorker.getRegistration();
    const waiting = registration?.waiting;
    if (!waiting) {
        location.reload();
        return;
    }
    navigator.serviceWorker.addEventListener('controllerchange', () => location.reload(), { once: true });
    waiting.postMessage({ type: 'SKIP_WAITING' });
}
