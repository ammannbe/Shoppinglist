// Open dialogs and menus ("layers") get a history entry with the same URL, so the back gesture
// (Android) or the browser's back button closes them instead of leaving the page. Closing a
// layer in the UI drops its entry again. Browsers that close a dialog on back themselves
// (CloseWatcher) leave the entry, which is then dropped the same way.

/**
 * @param {object} options
 * @param {() => boolean} options.menuOpen whether the context menu is open
 * @param {() => void} options.closeMenu
 * @returns {{ opened: () => void, closed: () => void }} to call when the menu opens or closes
 */
export function closeLayersOnBack({ menuOpen, closeMenu }) {
    // history.back() calls of our own whose popstate is still to come.
    let ownBacks = 0;
    const openDialogs = () => [...document.querySelectorAll('dialog[open]')];
    const anyOpen = () => menuOpen() || openDialogs().length > 0;

    const opened = () => {
        if (!history.state?.layer) history.pushState({ layer: true }, '');
    };
    const closed = () => {
        if (anyOpen() || !history.state?.layer) return;
        ownBacks++;
        history.back();
    };

    window.addEventListener('popstate', () => {
        if (ownBacks) {
            ownBacks--;
            // Another layer opened right after one closed (e.g. edit → move): it needs an entry.
            if (anyOpen()) opened();
            return;
        }
        if (!anyOpen()) return;
        if (menuOpen()) closeMenu();
        else openDialogs().at(-1)?.close();
        // More layers still open: keep one entry for them.
        if (anyOpen()) opened();
    });

    new MutationObserver((mutations) => {
        for (const { target } of mutations) {
            if (target.tagName !== 'DIALOG') continue;
            if (target.open) opened();
            else closed();
        }
    }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['open'] });

    return { opened, closed };
}
