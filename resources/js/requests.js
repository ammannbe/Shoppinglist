import { HttpError } from './api.js';

/**
 * Server requests started by buttons (week planner, offers): the button shows a spinner and
 * the progress label, an error is shown below it. Spread into an Alpine component:
 * `{ ...requests(), … }`, then `await this.run('key', label, async () => …)`.
 */
export function requests() {
    return {
        // Progress label and the button it belongs to (shows the spinner and the label).
        busy: null,
        busyKey: null,
        // Error and the button it belongs to (shown below it).
        error: null,
        errorKey: null,

        /** Label of a button: the progress while its request runs. */
        label(key, text) {
            return this.busyKey === key ? this.busy : text;
        },

        /**
         * Runs a server request with a progress label and readable errors.
         */
        async run(key, label, action) {
            this.errorKey = key;
            if (!navigator.onLine) {
                this.error = this.$t('This needs an internet connection.');
                return;
            }
            this.busy = label;
            this.busyKey = key;
            this.error = null;
            try {
                await action();
            } catch (error) {
                this.error = error instanceof HttpError
                    ? (error.status === 429 ? this.$t('Too many requests. Please try again later.') : error.body?.message ?? error.message)
                    : this.$t('This needs an internet connection.');
            } finally {
                this.busy = null;
                this.busyKey = null;
            }
        },
    };
}
