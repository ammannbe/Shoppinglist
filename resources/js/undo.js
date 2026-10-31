// Undoable deletions: rows are hidden immediately, the actual delete runs after a
// few seconds (or when the app goes to the background). Deleting late keeps the
// sync simple: a deleted document is never revived.

const DELAY = 5000;

export function createUndoStore() {
    return {
        // id => true, reactive through Alpine.store
        pending: {},
        toast: null,
        timer: null,
        commit: null,

        init() {
            // iOS may kill a backgrounded PWA without further events.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') this.flush();
            });
            window.addEventListener('pagehide', () => this.flush());
        },

        hidden(id) {
            return this.pending[id] === true;
        },

        /**
         * @param {string|string[]} ids rows to hide until the deletion is final
         * @param {string} message toast text
         * @param {() => Promise<void>} remove the actual deletion
         * @param {() => Promise<void>} [restore] runs on undo (e.g. removes a copy made already)
         */
        schedule(ids, message, remove, restore = null) {
            this.flush();
            ids = [].concat(ids);
            ids.forEach((id) => { this.pending[id] = true; });

            this.commit = async () => {
                this.commit = null;
                try {
                    await remove();
                } finally {
                    ids.forEach((id) => delete this.pending[id]);
                }
            };
            this.toast = { message, ids, restore };
            this.timer = setTimeout(() => this.flush(), DELAY);
        },

        undo() {
            clearTimeout(this.timer);
            this.toast?.ids.forEach((id) => delete this.pending[id]);
            this.toast?.restore?.();
            this.toast = null;
            this.commit = null;
        },

        flush() {
            clearTimeout(this.timer);
            this.toast = null;
            this.commit?.();
        },
    };
}
