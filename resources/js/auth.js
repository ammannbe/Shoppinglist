// Passkeys on the server rendered account pages (login, confirm password, manage passkeys).
import { Passkeys, UserCancelledError } from '@laravel/passkeys';

const t = (element, key) => element.dataset[key] ?? '';

function showError(element, error) {
    if (error instanceof UserCancelledError) return;
    const target = document.querySelector('[data-passkey-error]');
    if (!target) return;
    target.textContent = error?.message || t(target, 'fallback');
    target.hidden = false;
}

function init() {
    if (!Passkeys.isSupported()) return;
    document.querySelectorAll('[data-passkey]').forEach((element) => { element.hidden = false; });
    document.querySelectorAll('[data-passkey-unsupported]').forEach((element) => { element.hidden = true; });

    // Login (also offered in the email field's autofill, where supported)
    const login = document.querySelector('[data-passkey-login]');
    if (login) {
        const done = (response) => { if (response) location.assign(response.redirect ?? '/'); };
        login.addEventListener('click', () => Passkeys.verify({ remember: true }).then(done).catch((error) => showError(login, error)));
        Passkeys.autofill({ remember: true }).then(done).catch(() => {});
    }

    // Confirm the password with a passkey
    const confirm = document.querySelector('[data-passkey-confirm]');
    confirm?.addEventListener('click', () => Passkeys.verify({ routes: { options: '/passkeys/confirm/options', submit: '/passkeys/confirm' } })
        .then((response) => location.assign(response.redirect ?? '/account'))
        .catch((error) => showError(confirm, error)));

    // Register a new passkey
    const register = document.querySelector('[data-passkey-register]');
    register?.addEventListener('submit', (event) => {
        event.preventDefault();
        const name = new FormData(register).get('name')?.toString().trim() || deviceName();
        Passkeys.register({ name }).then(() => location.reload()).catch((error) => showError(register, error));
    });
}

function deviceName() {
    const ua = navigator.userAgent;
    const device = [/iPhone/, /iPad/, /Android/, /Mac/, /Windows/, /Linux/].map((re) => ua.match(re)?.[0]).find(Boolean) ?? 'Passkey';
    const browser = [/Edg/, /Firefox/, /Chrome/, /Safari/].map((re) => ua.match(re)?.[0]).find(Boolean);

    return [device, browser === 'Edg' ? 'Edge' : browser].filter(Boolean).join(' · ');
}

init();
