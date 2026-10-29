// Client side translations: the app shell is cached by the service worker, so the
// UI translates itself. Keys are the English texts, same files as Laravel (`lang/*.json`).
import deCH from '../../lang/de_CH.json';

export const LOCALES = { de_CH: 'Deutsch', en: 'English' };
const DICTIONARIES = { de_CH: deCH };
const STORAGE_KEY = 'shoppinglist.locale';
const PENDING_KEY = 'shoppinglist.locale-pending';

export function initialLocale() {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored in LOCALES) return stored;

    return (navigator.language ?? '').startsWith('en') ? 'en' : 'de_CH';
}

export function translate(locale, key, replace = {}) {
    let text = DICTIONARIES[locale]?.[key] ?? key;
    for (const [name, value] of Object.entries(replace)) {
        text = text.replaceAll(`:${name}`, value);
    }

    return text;
}

export function htmlLang(locale) {
    return locale.replace('_', '-');
}

export function storeLocale(locale, { pending }) {
    localStorage.setItem(STORAGE_KEY, locale);
    pending ? localStorage.setItem(PENDING_KEY, '1') : localStorage.removeItem(PENDING_KEY);
}

export function hasPendingLocale() {
    return localStorage.getItem(PENDING_KEY) === '1';
}
