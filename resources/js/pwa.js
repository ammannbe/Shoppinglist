import { registerSW } from 'virtual:pwa-register';

/**
 * The service worker caches the app shell and assets, so the app starts offline.
 * New versions activate automatically and are used after the next reload.
 */
export function registerServiceWorker() {
    if ('serviceWorker' in navigator) {
        registerSW({ immediate: true });
    }
}
