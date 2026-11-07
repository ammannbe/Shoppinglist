import { createApi, HttpError } from './api.js';

// "Manage account" at the bottom of the settings (resources/views/partials/account.blade.php,
// AccountController): log out, link or unlink the OIDC login, the AccountManager's profile
// (linked accounts) or e-mail, password and passkeys (local accounts), deleting the account.
// Everything needs a connection; the state comes from /me (`account`, AccountController::state).
// Unlinking and deleting a linked account need a recent login at the provider: the app goes
// there and comes back with `?account=unlink|delete`, which opens the dialog again.
// Same file as in Chorelist.

const api = createApi();

/**
 * Which rows "Manage account" shows for the user of /me (null: not logged in or an older answer).
 *
 * @param {object|null} user
 */
export function accountRows(user) {
    const account = user?.account;
    if (!account) return null;
    const oidc = account.oidc?.enabled ? account.oidc : null;

    return {
        provider: account.oidc?.provider ?? null,
        // Linked accounts: unlinking needs the OIDC login enabled (otherwise only "Connected with …").
        unlink: Boolean(user.linked && oidc),
        link: Boolean(!user.linked && oidc),
        // Linked accounts manage name, address and password in the AccountManager (if the app knows it).
        manager: user.linked ? account.manager ?? null : null,
        // Local accounts: e-mail, password and passkeys here.
        credentials: !user.linked,
        passkeys: !user.linked,
        hasPassword: Boolean(account.has_password),
        // How deleting is confirmed: the password, a recent login at the provider, or not possible right now.
        deletion: user.linked
            ? (oidc ? (oidc.confirmed ? 'confirmed' : 'provider') : 'unavailable')
            : (account.has_password ? 'password' : 'no-password'),
    };
}

/**
 * First validation message of a failed request (or the server's message).
 */
export function errorMessage(error, fallback) {
    if (!(error instanceof HttpError)) return fallback;

    return Object.values(error.body?.errors ?? {})[0]?.[0] ?? error.body?.message ?? fallback;
}

/**
 * Name of a new passkey when none is given ("iPhone · Safari").
 */
export function deviceName(ua = navigator.userAgent) {
    const device = [/iPhone/, /iPad/, /Android/, /Mac/, /Windows/, /Linux/].map((re) => ua.match(re)?.[0]).find(Boolean) ?? 'Passkey';
    const browser = [/Edg/, /Firefox/, /Chrome/, /Safari/].map((re) => ua.match(re)?.[0]).find(Boolean);

    return [device, browser === 'Edg' ? 'Edge' : browser].filter(Boolean).join(' · ');
}

const passkeysClient = () => import('@laravel/passkeys');

/**
 * @param {object} options
 * @param {string} options.ownerKey localStorage key of the device's account (the address; kept when it changes)
 */
export function accountSettings({ ownerKey }) {
    // The passkey action waiting for the password confirmation (not reactive: Alpine calls functions in expressions).
    let pendingAction = null;

    return {
        // Open dialog: 'unlink' | 'link' | 'delete'
        accountDialog: null,
        accountBusy: false,
        accountError: null,
        accountForm: { password: '', password_confirmation: '' },
        emailForm: { email: '', current_password: '' },
        passwordForm: { current_password: '', password: '', password_confirmation: '' },
        emailError: null,
        passwordError: null,
        passkeyList: null,
        passkeyName: '',
        passkeyError: null,
        // A passkey action waits for the password confirmation (Fortify: 423 without one).
        passkeyConfirming: false,
        passkeyPassword: '',
        passkeysSupported: typeof window !== 'undefined' && Boolean(window.PublicKeyCredential),

        init() {
            // Back from the confirmation at the provider: open the dialog again.
            const intent = new URLSearchParams(location.search).get('account');
            if (intent) {
                history.replaceState(null, '', `/${location.hash}`);
                if (['unlink', 'delete'].includes(intent)) this.$nextTick(() => this.openAccountDialog(intent, { confirmed: true }));
            }
        },

        get rows() {
            return accountRows(this.$store.sync.user);
        },

        get onlineNow() {
            return this.$store.sync.online;
        },

        /**
         * The current account state (the dialogs depend on the recent confirmation at the provider).
         */
        async refreshAccount() {
            const { notice, ...user } = await api.get('/me');
            this.$store.sync.user = user;
            if (notice) this.$store.notice.show(notice);
        },

        async openAccountDialog(kind, { confirmed = false } = {}) {
            if (!this.requireOnline()) return;
            this.accountError = null;
            this.accountForm = { password: '', password_confirmation: '' };
            try {
                await this.refreshAccount();
            } catch {
                return this.$store.notice.show(this.$t('This needs an internet connection.'));
            }
            const rows = this.rows;
            // Unlinking needs the recent login at the provider first (comes back with ?account=unlink).
            if (kind === 'unlink' && !confirmed && !rows?.unlink) return;
            if (kind === 'unlink' && !confirmed && !this.$store.sync.user.account.oidc?.confirmed) {
                location.assign('/auth/oidc/redirect?confirm=1');
                return;
            }
            this.accountDialog = kind;
            this.$nextTick(() => this.$refs.accountDialog?.showModal());
        },

        closeAccountDialog() {
            this.$refs.accountDialog?.close();
        },

        requireOnline() {
            if (navigator.onLine) return true;
            this.$store.notice.show(this.$t('This needs an internet connection.'));

            return false;
        },

        /**
         * Runs a request of a dialog: errors are shown in the dialog, 409 goes to the provider to confirm.
         */
        async accountRequest(action) {
            if (!this.requireOnline()) return;
            this.accountBusy = true;
            this.accountError = null;
            try {
                await action();
            } catch (error) {
                if (error instanceof HttpError && error.status === 409 && error.body?.redirect) {
                    location.assign(error.body.redirect);
                    return;
                }
                this.accountError = errorMessage(error, this.$t('This needs an internet connection.'));
            } finally {
                this.accountBusy = false;
            }
        },

        unlink() {
            return this.accountRequest(async () => {
                const { message } = await api.delete('/account/oidc', { ...this.accountForm });
                this.closeAccountDialog();
                await this.refreshAccount();
                this.$store.notice.show(message);
            });
        },

        link() {
            return this.accountRequest(async () => {
                const { redirect } = await api.post('/account/oidc', this.rows.hasPassword ? { password: this.accountForm.password } : {});
                location.assign(redirect);
            });
        },

        deleteAccount() {
            return this.accountRequest(async () => {
                const { redirect } = await api.delete('/account', this.rows.deletion === 'password' ? { password: this.accountForm.password } : {});
                location.replace(redirect);
            });
        },

        confirmAtProvider() {
            location.assign('/auth/oidc/redirect?confirm=1&for=delete');
        },

        // ---- local accounts: e-mail and password (Fortify)

        async changeEmail() {
            if (!this.requireOnline()) return;
            this.accountBusy = true;
            this.emailError = null;
            try {
                const email = this.emailForm.email.trim();
                await api.put('/user/profile-information', { email, current_password: this.emailForm.current_password });
                // Same account: the local data stays on this device (app.js compares the address).
                localStorage.setItem(ownerKey, email);
                this.emailForm = { email: '', current_password: '' };
                await this.refreshAccount();
                this.$store.notice.show(this.$t('Done. Confirm the new address with the link we sent you.'));
            } catch (error) {
                this.emailError = errorMessage(error, this.$t('This needs an internet connection.'));
            } finally {
                this.accountBusy = false;
            }
        },

        async changePassword() {
            if (!this.requireOnline()) return;
            this.accountBusy = true;
            this.passwordError = null;
            try {
                await api.put('/user/password', { ...this.passwordForm });
                this.passwordForm = { current_password: '', password: '', password_confirmation: '' };
                this.$store.notice.show(this.$t('Password changed.'));
            } catch (error) {
                this.passwordError = errorMessage(error, this.$t('This needs an internet connection.'));
            } finally {
                this.accountBusy = false;
            }
        },

        // ---- local accounts: passkeys (Fortify + @laravel/passkeys)

        async loadPasskeys() {
            if (!navigator.onLine) return;
            try {
                this.passkeyList = await api.get('/account/passkeys');
            } catch (error) {
                this.passkeyError = errorMessage(error, this.$t('This needs an internet connection.'));
            }
        },

        /**
         * Adding and removing passkeys needs a recent password confirmation: asked first when missing.
         */
        async withConfirmation(action) {
            if (!this.requireOnline()) return;
            this.passkeyError = null;
            try {
                const { confirmed } = await api.get('/user/confirmed-password-status');
                if (!confirmed) {
                    pendingAction = action;
                    this.passkeyConfirming = true;
                    return;
                }
                await action();
            } catch (error) {
                this.passkeyError = errorMessage(error, this.$t('This needs an internet connection.'));
            }
        },

        async confirmPassword() {
            if (!this.requireOnline()) return;
            this.accountBusy = true;
            this.passkeyError = null;
            try {
                await api.post('/user/confirm-password', { password: this.passkeyPassword });
                await this.confirmed();
            } catch (error) {
                this.passkeyError = errorMessage(error, this.$t('This needs an internet connection.'));
            } finally {
                this.accountBusy = false;
            }
        },

        async confirmWithPasskey() {
            const { Passkeys, UserCancelledError } = await passkeysClient();
            try {
                await Passkeys.verify({ routes: { options: '/passkeys/confirm/options', submit: '/passkeys/confirm' } });
                await this.confirmed();
            } catch (error) {
                if (!(error instanceof UserCancelledError)) this.passkeyError = error?.message || this.$t('This needs an internet connection.');
            }
        },

        async confirmed() {
            const action = pendingAction;
            this.cancelConfirmation();
            await action?.();
        },

        cancelConfirmation() {
            pendingAction = null;
            this.passkeyConfirming = false;
            this.passkeyPassword = '';
        },

        addPasskey() {
            return this.withConfirmation(async () => {
                const { Passkeys, UserCancelledError } = await passkeysClient();
                try {
                    await Passkeys.register({ name: this.passkeyName.trim() || deviceName() });
                    this.passkeyName = '';
                    this.$store.notice.show(this.$t('Passkey added.'));
                    await Promise.all([this.loadPasskeys(), this.refreshAccount()]);
                } catch (error) {
                    if (!(error instanceof UserCancelledError)) this.passkeyError = error?.message || this.$t('This needs an internet connection.');
                }
            });
        },

        removePasskey(passkey) {
            if (!confirm(this.$t('Remove this passkey?'))) return;

            return this.withConfirmation(async () => {
                await api.delete(`/user/passkeys/${passkey.id}`);
                this.$store.notice.show(this.$t('Passkey removed.'));
                await Promise.all([this.loadPasskeys(), this.refreshAccount()]);
            });
        },
    };
}
