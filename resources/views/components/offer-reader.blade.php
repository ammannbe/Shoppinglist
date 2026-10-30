{{--
    Reading offers with the AI (resources/js/offers.js offerReader): files, pasted text, per-shop
    instructions and whether to share them. Used by the week planner and the offers page; needs
    requests() and offerReader() in the surrounding component.
--}}
<p class="text-sm text-stone-500" x-text="$t('Read new offers: screenshots from the shop app, the flyer as PDF or photos of it. Migros, Coop, Aldi, Lidl and Denner get their shop code automatically.')"></p>
<label class="flex items-center gap-2 text-sm">
    <input type="checkbox" class="size-4 accent-emerald-700" :checked="shareOffers" @change="setShareOffers($event.target.checked)">
    <span x-text="$t('Share read offers with all users')"></span>
</label>
<label class="button-secondary inline-flex cursor-pointer items-center gap-2" :class="busy && 'pointer-events-none opacity-50'">
    <input type="file" accept="image/*,application/pdf" multiple class="sr-only" :disabled="busy" @change="readOfferFiles($event)">
    <x-spinner x-show="busyKey === 'offerFiles'" />
    <span x-text="label('offerFiles', $t('Add screenshots, photos or PDF'))"></span>
</label>
<x-planner-error key="offerFiles" />
@php
    // How to get the offers of each shop (shop pages are German, the labels quoted here too).
    $offerSources = [
        ['Migros', 'https://www.migros.ch/de/offers/home', [
            'Open the offers page.',
            'Select all text (Ctrl+A, on the Mac Cmd+A) and copy it.',
            'Paste it into the text field below and tap “Read text”.',
        ]],
        ['Coop', 'https://www.coop.ch/de/aktionen/aktuelle-aktionen/c/m_1011', [
            'Open the offers page and choose your store.',
            'Click “Aktionsmagazin auswählen”.',
            'Menu → “Speichern”, choose “Alle” pages.',
            'Add the PDF above.',
        ]],
        ['Aldi', 'https://www.aldi-suisse.ch/de/broschuere', [
            'Open the flyer and click “Durchblättern”.',
            'Download it with “PDF herunterladen”.',
            'Add the PDF above.',
        ]],
        ['Lidl', 'https://www.lidl.ch/de/unternehmen/werbeprospekte-als-pdf', [
            'Open the flyer, then “Menü” → “PDF Download”.',
            'Add the PDF above.',
        ]],
        ['Denner', 'https://www.denner.ch/de/aktionen/aktuelle-aktionen', [
            'Open the offers page.',
            'For more offers, click “Weitere Produkte anzeigen” at the end of the page (repeatedly if needed).',
            'Select all text (Ctrl+A, on the Mac Cmd+A) and copy it.',
            'Paste it into the text field below and tap “Read text”.',
        ]],
    ];
@endphp
<details class="group/details overflow-hidden rounded-lg border border-stone-200 bg-white text-sm">
    <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 hover:bg-stone-50">
        <span x-text="$t('How to get the offers of each shop')"></span>
        <span class="transition-transform group-open/details:rotate-90" aria-hidden="true">›</span>
    </summary>
    <div class="space-y-4 border-t border-stone-200 px-4 py-3">
        @foreach ($offerSources as [$shop, $url, $steps])
            <div>
                <a href="{{ $url }}" target="_blank" rel="noopener" class="font-medium underline">{{ $shop }}</a>
                <ol class="mt-1 list-decimal space-y-0.5 pl-5 text-stone-600">
                    @foreach ($steps as $step)
                        <li x-text="$t(@js($step))"></li>
                    @endforeach
                </ol>
            </div>
        @endforeach
        <p class="text-stone-500" x-text="$t('Copying works best on a computer. Long texts are read in several parts.')"></p>
    </div>
</details>
<form @submit.prevent="readOfferText()" class="space-y-2">
    <textarea x-model="offerText" rows="2" class="field w-full text-sm" :placeholder="$t('… or paste the offers as text')"></textarea>
    <button x-show="offerText.trim() || busyKey === 'offerText'" class="button-secondary inline-flex items-center gap-2 text-sm" :disabled="busy">
        <x-spinner x-show="busyKey === 'offerText'" />
        <span x-text="label('offerText', $t('Read text'))"></span>
    </button>
    <x-planner-error key="offerText" />
</form>

