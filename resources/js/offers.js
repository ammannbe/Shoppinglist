import Alpine from 'alpinejs';
import { createApi } from './api.js';
import { normalizeName } from './helpers.js';
import { prepareForAi } from './image.js';
import { requests } from './requests.js';

// Offers of the shops: read by the AI (screenshots, PDFs, text) or entered by hand and kept
// on the server (OfferController), shared ones for all users. The week planner takes them
// from here (#/offers lists and edits them). Needs a connection, nothing is synced offline.

const api = createApi();
// The server accepts up to 20,000 characters per text; a copied offers page can be longer.
const TEXT_LIMIT = 18000;
// Share read offers with all users (device setting, on by default).
const SHARE_KEY = 'shoppinglist.share-offers';

// Splits the text into parts of at most `limit` characters, at line breaks where possible.
export function splitText(text, limit) {
    const parts = [];
    let part = '';
    for (const line of text.split('\n')) {
        for (let start = 0; start < Math.max(line.length, 1); start += limit) {
            const piece = line.slice(start, start + limit);
            if (part && part.length + piece.length + 1 > limit) {
                parts.push(part);
                part = '';
            }
            part = part ? `${part}\n${piece}` : piece;
        }
    }
    if (part.trim()) parts.push(part);
    return parts;
}

/**
 * Reading offers with the AI, spread into a component with requests() and addOffers(offers).
 * The server saves them in the pool and returns them with their ids.
 */
export function offerReader() {
    return {
        offerText: '',
        shareOffers: localStorage.getItem(SHARE_KEY) !== '0',

        setShareOffers(value) {
            this.shareOffers = value;
            localStorage.setItem(SHARE_KEY, value ? '1' : '0');
        },

        async readOfferFiles(event) {
            const files = [...event.target.files];
            event.target.value = '';
            for (const [index, file] of files.entries()) {
                await this.run('offerFiles', this.$t('Reading offers :current/:total …', { current: index + 1, total: files.length }), async () => {
                    const form = new FormData();
                    form.append('file', await prepareForAi(file), file.name);
                    form.append('share', this.shareOffers ? '1' : '0');
                    this.addOffers((await api.post('/planner/offers', form)).offers);
                });
                if (this.error) break;
            }
        },

        async readOfferText() {
            const text = this.offerText.trim();
            if (!text) return;
            await this.run('offerText', this.$t('Reading offers …'), async () => {
                const parts = splitText(text, TEXT_LIMIT);
                while (parts.length) {
                    this.addOffers((await api.post('/planner/offers', { text: parts[0], share: this.shareOffers })).offers);
                    parts.shift();
                    // Keep only the unread parts, so a retry after an error does not read twice.
                    this.offerText = parts.join('\n');
                }
            });
        },
    };
}

/** Offer as entered in the form: empty fields become null, numbers accept a comma. */
function offerPayload(form) {
    const text = (value) => String(value ?? '').trim() || null;
    const number = (value) => (text(value) === null ? null : Number(String(value).replace(',', '.')));

    return {
        store: text(form.store),
        region: text(form.region),
        name: text(form.name),
        brand: text(form.brand),
        price: number(form.price),
        regular_price: number(form.regular_price),
        discount: text(form.discount),
        valid_from: text(form.valid_from),
        valid_until: text(form.valid_until),
        shared: Boolean(form.shared),
    };
}

const emptyForm = () => ({ id: null, store: '', region: '', name: '', brand: '', price: '', regular_price: '', discount: '', valid_from: '', valid_until: '', shared: true });

/**
 * #/offers: the offers pool, filtered by shop and search.
 *
 * @param {object} options
 * @param {(tag: string) => string} options.storeLabel full name of a shop tag
 */
export function offersPool({ storeLabel }) {
    return {
        ...requests(),
        ...offerReader(),
        offers: [],
        loaded: false,
        search: '',
        storeFilter: [],
        form: null,
        // The AI reader is shown on demand.
        reading: false,

        init() {
            // Load whenever the view is opened (others may have shared new offers).
            const opened = () => location.hash.startsWith('#/offers') && this.load();
            window.addEventListener('hashchange', opened);
            opened();
        },

        storeLabel(tag) {
            return tag ? storeLabel(tag) : this.$t('Other shops');
        },

        // ---- regions (Migros cooperatives): the server leaves out other regions than the user's

        /** Regions of a shop tag as [key, name] (none: its offers apply everywhere). */
        regionsOf(tag) {
            return Object.entries(Alpine.store('sync').user?.regions?.[tag] ?? {});
        },

        /** The user's region of a shop, null: all regions shown. */
        chosenRegion(tag) {
            return Alpine.store('prefs').regions?.[tag] ?? null;
        },

        /** Short name of a region, e.g. "Migros Aare". */
        regionName(key) {
            const name = Object.values(Alpine.store('sync').user?.regions ?? {}).map((regions) => regions[key]).find(Boolean);

            return name?.replace(/\s*\(.*\)$/, '') ?? key;
        },

        /** Shops with offers of several regions shown because the user has not picked one. */
        get regionHint() {
            return this.stores.filter((tag) => this.regionsOf(tag).length && !this.chosenRegion(tag)).map((tag) => this.storeLabel(tag));
        },

        async load() {
            await this.run('load', this.$t('Loading offers …'), async () => {
                this.offers = (await api.get('/offers')).offers;
                this.loaded = true;
            });
        },

        addOffers(offers) {
            const byId = new Map(this.offers.map((offer) => [offer.id, offer]));
            for (const offer of offers) byId.set(offer.id, offer);
            this.offers = [...byId.values()];
        },

        get stores() {
            return [...new Set(this.offers.map((offer) => offer.store ?? ''))]
                .sort((a, b) => (a === '' ? 1 : b === '' ? -1 : this.storeLabel(a).localeCompare(this.storeLabel(b))));
        },

        toggleStore(store) {
            this.storeFilter = this.storeFilter.includes(store) ? this.storeFilter.filter((each) => each !== store) : [...this.storeFilter, store];
        },

        get shown() {
            const search = normalizeName(this.search);

            return this.offers.filter((offer) => (!this.storeFilter.length || this.storeFilter.includes(offer.store ?? ''))
                && (!search || normalizeName(`${offer.name} ${offer.brand ?? ''}`).includes(search)));
        },

        /** Shown offers grouped by shop, in the order of the shop chips. */
        get groups() {
            return this.stores
                .map((store) => ({ store, offers: this.shown.filter((offer) => (offer.store ?? '') === store).sort((a, b) => a.name.localeCompare(b.name)) }))
                .filter((group) => group.offers.length);
        },

        validity(offer) {
            const locale = Alpine.store('i18n').locale.replace('_', '-');
            const format = (date) => new Date(`${date}T12:00:00`).toLocaleDateString(locale, { day: 'numeric', month: 'numeric' });
            if (offer.valid_from && offer.valid_until) return `${format(offer.valid_from)}–${format(offer.valid_until)}`;
            if (offer.valid_until) return this.$t('until :date', { date: format(offer.valid_until) });
            if (offer.valid_from) return this.$t('from :date', { date: format(offer.valid_from) });

            return null;
        },

        details(offer) {
            const region = offer.region && !this.chosenRegion(offer.store) ? this.regionName(offer.region) : null;

            return [region, offer.brand, offer.discount, offer.price != null ? `CHF ${Number(offer.price).toFixed(2)}` : null, this.validity(offer)]
                .filter(Boolean).join(' · ');
        },

        // ---- own offers: add, edit (incl. sharing), delete

        openForm(offer = null) {
            this.form = offer
                ? { ...emptyForm(), ...offer, region: offer.region ?? '', price: offer.price ?? '', regular_price: offer.regular_price ?? '' }
                : { ...emptyForm(), shared: this.shareOffers };
            this.error = null;
            this.$nextTick(() => this.$refs.offerDialog.showModal());
        },

        async saveForm() {
            const id = this.form.id;
            await this.run('form', this.$t('Saving …'), async () => {
                const { offer } = id
                    ? await api.patch(`/offers/${id}`, offerPayload(this.form))
                    : await api.post('/offers', offerPayload(this.form));
                this.addOffers([offer]);
                this.$refs.offerDialog.close();
            });
        },

        /** Deletes the own offers of a shop group (others' shared offers stay). */
        async removeStore(group) {
            const own = group.offers.filter((offer) => offer.own);
            if (!own.length || !confirm(this.$t('Delete your :count offers of :shop?', { count: own.length, shop: this.storeLabel(group.store) }))) return;
            await this.run(`store:${group.store}`, this.$t('Deleting …'), async () => {
                await api.delete('/offers', { store: group.store || null });
                const removed = new Set(own.map((offer) => offer.id));
                this.offers = this.offers.filter((offer) => !removed.has(offer.id));
            });
        },

        async remove(offer) {
            if (!confirm(this.$t('Delete “:name”?', { name: offer.name }))) return;
            await this.run(`delete:${offer.id}`, this.$t('Deleting …'), async () => {
                await api.delete(`/offers/${offer.id}`);
                this.offers = this.offers.filter((each) => each.id !== offer.id);
                this.$refs.offerDialog?.close();
            });
        },
    };
}
