// Install instructions per platform. Chromium browsers offer a native install
// prompt (beforeinstallprompt), all others need manual steps.
// Menu labels differ between browser versions, the steps name the usual ones.

const DISMISSED_KEY = 'shoppinglist.install-hint';

export const PLATFORMS = {
    'ios-safari': {
        name: 'iPhone / iPad · Safari',
        steps: [
            'Tap the share button (square with arrow) in the toolbar.',
            'Choose “Add to Home Screen”.',
            'Confirm with “Add”.',
        ],
    },
    'ios-other': {
        name: 'iPhone / iPad · Chrome, Edge, Firefox',
        steps: [
            'Tap the share button (square with arrow) next to the address bar.',
            'Choose “Add to Home Screen” (iOS 16.4 or newer).',
            'Confirm with “Add”.',
        ],
        note: 'On older iOS versions this only works in Safari.',
    },
    'android-chrome': {
        name: 'Android · Chrome, Edge',
        steps: [
            'Open the browser menu (⋮ or …).',
            'Choose “Install app” or “Add to Home screen”.',
            'Confirm with “Install”.',
        ],
    },
    'android-samsung': {
        name: 'Android · Samsung Internet',
        steps: [
            'Tap the menu (☰) at the bottom.',
            'Choose “Add page to” and then “Home screen”.',
        ],
    },
    'android-firefox': {
        name: 'Android · Firefox',
        steps: [
            'Open the menu (⋮).',
            'Choose “Add to Home screen” or “Install”.',
        ],
    },
    'desktop-chrome': {
        name: 'Computer · Chrome',
        steps: [
            'Click the install icon at the right end of the address bar.',
            'Or: menu (⋮) → “Cast, save and share” → “Install page as app”.',
        ],
    },
    'desktop-edge': {
        name: 'Computer · Edge',
        steps: [
            'Click the install icon at the right end of the address bar.',
            'Or: menu (…) → “Apps” → “Install this site as an app”.',
        ],
    },
    'desktop-safari': {
        name: 'Mac · Safari',
        steps: [
            'Choose “File” → “Add to Dock” in the menu bar (macOS 14 or newer).',
        ],
    },
    'desktop-firefox': {
        name: 'Computer · Firefox',
        // Nothing to do here, so no first visit hint.
        installable: false,
        steps: [
            'Firefox cannot install web apps on computers. Use Chrome, Edge or Safari, or set a bookmark.',
        ],
    },
};

export function detectPlatform(ua = navigator.userAgent) {
    // iPadOS reports itself as Mac, but has touch.
    const ios = /iphone|ipad|ipod/i.test(ua) || (/macintosh/i.test(ua) && navigator.maxTouchPoints > 1);
    const android = /android/i.test(ua);

    if (ios) return /crios|fxios|edgios/i.test(ua) ? 'ios-other' : 'ios-safari';
    if (android) {
        if (/samsungbrowser/i.test(ua)) return 'android-samsung';
        if (/firefox/i.test(ua)) return 'android-firefox';
        return 'android-chrome';
    }
    if (/edg\//i.test(ua)) return 'desktop-edge';
    if (/firefox/i.test(ua)) return 'desktop-firefox';
    if (/chrome|chromium/i.test(ua)) return 'desktop-chrome';
    if (/safari/i.test(ua)) return 'desktop-safari';

    return 'desktop-chrome';
}

export function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
}

export function createInstallStore() {
    return {
        platform: detectPlatform(),
        installed: isStandalone(),
        // Native prompt of Chromium browsers, null until the browser offers it.
        prompt: null,
        dismissed: localStorage.getItem(DISMISSED_KEY) === 'dismissed',

        init() {
            window.addEventListener('beforeinstallprompt', (event) => {
                event.preventDefault();
                this.prompt = event;
            });
            window.addEventListener('appinstalled', () => {
                this.installed = true;
                this.prompt = null;
            });
        },

        get platforms() {
            return PLATFORMS;
        },

        get current() {
            return PLATFORMS[this.platform];
        },

        get others() {
            return Object.entries(PLATFORMS).filter(([id]) => id !== this.platform);
        },

        get showHint() {
            return !this.installed && !this.dismissed && this.current.installable !== false;
        },

        async install() {
            if (!this.prompt) return;
            this.prompt.prompt();
            const { outcome } = await this.prompt.userChoice;
            this.prompt = null;
            if (outcome === 'accepted') this.dismiss();
        },

        dismiss() {
            localStorage.setItem(DISMISSED_KEY, 'dismissed');
            this.dismissed = true;
        },
    };
}
