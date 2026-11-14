// Web Push on this device (only used for announcements): the service worker (public/push-sw.js) shows the
// notifications. Same file as in Chorelist.
// iOS/iPadOS only allows push for apps added to the home screen (16.4+).

export function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/** granted | denied | default | unsupported */
export function pushPermission() {
    return pushSupported() ? Notification.permission : 'unsupported';
}

export async function currentSubscription() {
    if (!pushSupported()) return null;
    const registration = await navigator.serviceWorker.getRegistration();

    return (await registration?.pushManager.getSubscription()) ?? null;
}

/**
 * Asks for permission (must run in a click handler) and registers this device on the server.
 */
export async function subscribePush(api, vapidPublicKey) {
    if (!pushSupported() || !vapidPublicKey) throw new Error('unsupported');
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error(permission);

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription()
        ?? await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapidPublicKey) });
    const json = subscription.toJSON();
    await api.post('/push-subscriptions', {
        endpoint: json.endpoint,
        keys: json.keys,
        content_encoding: (PushManager.supportedContentEncodings ?? ['aesgcm'])[0],
    });

    return subscription;
}

export async function unsubscribePush(api) {
    const subscription = await currentSubscription();
    if (!subscription) return;
    await api.delete('/push-subscriptions', { endpoint: subscription.endpoint });
    await subscription.unsubscribe();
}

function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}
