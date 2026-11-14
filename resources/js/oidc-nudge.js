import { createApi } from './api.js';

// Banner "Link with …" in the app shell (resources/views/partials/oidc-nudge.blade.php, App\Auth\OidcNudge):
// for logged-in users whose account is not linked to the OIDC login (AUTH_OIDC_NUDGE). The state comes from
// /me (`account.oidc.nudge`: {days, dismissed_at} or null). "Later" hides it right away, also offline: the
// dismissal is kept on the device and sent to the server when online (POST /account/oidc-nudge).
// "Link now" opens the link dialog of the settings (resources/js/account.js).
// Same file as in Chorelist.

const api = createApi();
const DAY = 24 * 60 * 60 * 1000;
export const NUDGE_KEY = 'oidc-nudge';

/**
 * Whether the banner is shown for the user of /me and the dismissal on this device ({email, at, sent} or null).
 *
 * @param {object|null} user
 * @param {{email: string, at: number, sent?: boolean}|null} local
 * @param {number} now
 */
export function nudgeVisible(user, local, now = Date.now()) {
    const nudge = user?.account?.oidc?.nudge;
    if (!nudge || user.linked) return false;

    const localAt = local && local.email === user.email ? local.at : 0;
    const dismissed = Math.max(nudge.dismissed_at ?? 0, localAt ?? 0);
    if (!dismissed) return true;

    // Without days dismissed for good, otherwise back after the days.
    return Boolean(nudge.days) && now >= dismissed + nudge.days * DAY;
}

/**
 * A dismissal on this device that the server does not know yet.
 */
export function pendingDismissal(user, local) {
    return Boolean(user?.email && local && local.email === user.email && !local.sent);
}

function readLocal(storage) {
    try {
        return JSON.parse(storage.getItem(NUDGE_KEY) ?? 'null');
    } catch {
        return null;
    }
}

export function oidcNudge({ storage = globalThis.localStorage } = {}) {
    let sending = false;

    return {
        nudgeLocal: readLocal(storage),
        // Re-evaluated when the app comes back to the foreground (the days may have passed).
        nudgeNow: Date.now(),

        init() {
            const retry = () => {
                this.nudgeNow = Date.now();
                this.sendNudgeDismissal();
            };
            window.addEventListener('online', retry);
            document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && retry());
            this.$watch('$store.sync.user', retry);
            retry();
        },

        get nudge() {
            const user = this.$store.sync.user;

            return nudgeVisible(user, this.nudgeLocal, this.nudgeNow) ? user.account.oidc : null;
        },

        saveNudgeLocal(value) {
            this.nudgeLocal = value;
            try {
                storage.setItem(NUDGE_KEY, JSON.stringify(value));
            } catch {
                // Private mode: hidden until the next start.
            }
        },

        dismissNudge() {
            const user = this.$store.sync.user;
            if (!user?.email) return;
            this.saveNudgeLocal({ email: user.email, at: Date.now(), sent: false });
            this.sendNudgeDismissal();
        },

        /**
         * Sends a dismissal of this device; failures (offline, server down) are retried when online,
         * on the next /me and when the app comes to the foreground.
         */
        async sendNudgeDismissal() {
            if (sending || !navigator.onLine || !pendingDismissal(this.$store.sync.user, this.nudgeLocal)) return;
            sending = true;
            try {
                await api.post('/account/oidc-nudge', {});
                this.saveNudgeLocal({ ...this.nudgeLocal, sent: true });
            } catch {
                // Kept as pending, still hidden on this device.
            } finally {
                sending = false;
            }
        },

        /**
         * Same flow as "Link …" in the settings: the dialog there asks for the password if the account has one.
         */
        linkNow() {
            if (!navigator.onLine) return this.$store.notice.show(this.$t('This needs an internet connection.'));
            if (location.hash !== '#/settings') location.hash = '#/settings';
            window.dispatchEvent(new CustomEvent('account-dialog', { detail: 'link' }));
        },
    };
}
