import { describe, expect, it } from 'vitest';
import { nudgeVisible, pendingDismissal } from '../../resources/js/oidc-nudge.js';

// Same file as in Chorelist.

const DAY = 24 * 60 * 60 * 1000;
const now = Date.UTC(2026, 10, 14, 12);
const user = (nudge, extra = {}) => ({ email: 'anna@example.com', linked: false, account: { oidc: { enabled: true, provider: 'Konto', nudge } }, ...extra });

describe('nudgeVisible', () => {
    it('shows the banner when the server offers it and nothing was dismissed', () => {
        expect(nudgeVisible(user({ days: null, dismissed_at: null }), null, now)).toBe(true);
    });

    it('hides it without a nudge, for linked users and logged-out devices', () => {
        expect(nudgeVisible(user(null), null, now)).toBe(false);
        expect(nudgeVisible(user({ days: null, dismissed_at: null }, { linked: true }), null, now)).toBe(false);
        expect(nudgeVisible(null, null, now)).toBe(false);
        expect(nudgeVisible({ email: 'a@example.com' }, null, now)).toBe(false);
    });

    it('hides it for good without days', () => {
        expect(nudgeVisible(user({ days: null, dismissed_at: now - 400 * DAY }), null, now)).toBe(false);
    });

    it('shows it again after the days', () => {
        expect(nudgeVisible(user({ days: 30, dismissed_at: now - 29 * DAY }), null, now)).toBe(false);
        expect(nudgeVisible(user({ days: 30, dismissed_at: now - 30 * DAY }), null, now)).toBe(true);
    });

    it('hides it right away after a dismissal on this device (offline, not sent yet)', () => {
        const local = { email: 'anna@example.com', at: now - 1000, sent: false };
        expect(nudgeVisible(user({ days: null, dismissed_at: null }), local, now)).toBe(false);
        // The newer of both dismissals counts for the days.
        expect(nudgeVisible(user({ days: 30, dismissed_at: now - 40 * DAY }), local, now)).toBe(false);
    });

    it('ignores the dismissal of another account on this device', () => {
        const local = { email: 'other@example.com', at: now, sent: true };
        expect(nudgeVisible(user({ days: null, dismissed_at: null }), local, now)).toBe(true);
    });
});

describe('pendingDismissal', () => {
    it('is pending until the server got it', () => {
        const local = { email: 'anna@example.com', at: now, sent: false };
        expect(pendingDismissal(user(null), local)).toBe(true);
        expect(pendingDismissal(user(null), { ...local, sent: true })).toBe(false);
        expect(pendingDismissal(user(null), { ...local, email: 'other@example.com' })).toBe(false);
        expect(pendingDismissal(null, local)).toBe(false);
        expect(pendingDismissal(user(null), null)).toBe(false);
    });
});
