import { describe, expect, it } from 'vitest';
import { accountRows, deviceName, errorMessage } from '../../resources/js/account.js';
import { HttpError } from '../../resources/js/api.js';

const oidc = (overrides = {}) => ({ enabled: true, provider: 'NarrenKonto', signup_url: null, confirmed: false, ...overrides });
const user = ({ linked = false, ...account } = {}) => ({
    email: 'anna@example.com',
    linked,
    account: { has_password: !linked, oidc: oidc(), manager: null, passkeys: 0, ...account },
});

describe('accountRows', () => {
    it('shows nothing without an account state', () => {
        expect(accountRows(null)).toBeNull();
        expect(accountRows({ email: 'anna@example.com' })).toBeNull();
    });

    it('offers linking, e-mail, password and passkeys to local accounts', () => {
        expect(accountRows(user())).toEqual({
            provider: 'NarrenKonto',
            unlink: false,
            link: true,
            manager: null,
            credentials: true,
            passkeys: true,
            hasPassword: true,
            deletion: 'password',
        });
    });

    it('does not offer linking without the OIDC login', () => {
        const rows = accountRows(user({ oidc: null }));

        expect(rows.link).toBe(false);
        expect(rows.provider).toBeNull();
        expect(rows.credentials).toBe(true);
    });

    it('asks local accounts without password to set one before deleting', () => {
        expect(accountRows(user({ has_password: false })).deletion).toBe('no-password');
    });

    it('offers unlinking and the AccountManager to linked accounts, no local credentials', () => {
        const manager = { name: 'NarrenKonto', url: 'https://konto.example.com/profile' };
        const rows = accountRows(user({ linked: true, manager }));

        expect(rows).toMatchObject({ unlink: true, link: false, manager, credentials: false, passkeys: false, deletion: 'provider' });
        expect(accountRows(user({ linked: true, oidc: oidc({ confirmed: true }) })).deletion).toBe('confirmed');
    });

    it('hides the AccountManager when the app is not connected', () => {
        expect(accountRows(user({ linked: true })).manager).toBeNull();
    });

    it('names the provider of linked accounts while the OIDC login is off, without unlinking or deleting', () => {
        const rows = accountRows(user({ linked: true, oidc: oidc({ enabled: false }) }));

        expect(rows).toMatchObject({ provider: 'NarrenKonto', unlink: false, link: false, deletion: 'unavailable' });
    });
});

describe('errorMessage', () => {
    const error = (status, body) => new HttpError({ status }, body);

    it('takes the first validation message, then the message', () => {
        expect(errorMessage(error(422, { message: 'Invalid', errors: { password: ['Wrong password.'] } }), 'Offline')).toBe('Wrong password.');
        expect(errorMessage(error(409, { message: 'Confirm first.' }), 'Offline')).toBe('Confirm first.');
        expect(errorMessage(new TypeError('Failed to fetch'), 'Offline')).toBe('Offline');
    });
});

describe('deviceName', () => {
    it('names device and browser', () => {
        expect(deviceName('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile/15E148 Safari/604.1')).toBe('iPhone · Safari');
        expect(deviceName('Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36 Edg/130.0')).toBe('Windows · Edge');
        expect(deviceName('curl')).toBe('Passkey');
    });
});
