// Passkeys on the server rendered pages (login, confirm password). Managing them: resources/js/account.js.
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
        // Cancelled autofills resolve without a response; refusals of the server (e.g. a linked account) are shown.
        Passkeys.autofill({ remember: true }).then(done).catch((error) => showError(login, error));
    }

    // Confirm the password with a passkey
    const confirm = document.querySelector('[data-passkey-confirm]');
    confirm?.addEventListener('click', () => Passkeys.verify({ routes: { options: '/passkeys/confirm/options', submit: '/passkeys/confirm' } })
        .then((response) => location.assign(response.redirect ?? '/#/settings'))
        .catch((error) => showError(confirm, error)));
}

init();
