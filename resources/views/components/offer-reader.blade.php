{{--
    Reading offers with the AI (resources/js/offers.js offerReader): files or pasted text,
    whether to share them, and per-shop instructions. Used by the week planner and the offers
    page; needs requests() and offerReader() in the surrounding component.
--}}
@php
    // How to get the offers of each shop (shop pages are German, the labels quoted here too).
    $offerSources = [
        ['Migros', 'https://www.migros.ch/de/offers/home', [
            'Open the offers page.',
            'Select all text (Ctrl+A, on the Mac Cmd+A) and copy it.',
            'Paste it into the text field and tap “Read text”.',
        ]],
        ['Coop', 'https://www.coop.ch/de/aktionen/aktuelle-aktionen/c/m_1011', [
            'Open the offers page and choose your store.',
            'Click “Aktionsmagazin auswählen”.',
            'Menu → “Speichern”, choose “Alle” pages.',
            'Add the PDF.',
        ]],
        ['Aldi', 'https://www.aldi-suisse.ch/de/broschuere', [
            'Open the flyer and click “Durchblättern”.',
            'Download it with “PDF herunterladen”.',
            'Add the PDF.',
        ]],
        ['Lidl', 'https://www.lidl.ch/de/unternehmen/werbeprospekte-als-pdf', [
            'Open the flyer, then “Menü” → “PDF Download”.',
            'Add the PDF.',
        ]],
        ['Denner', 'https://www.denner.ch/de/aktionen/aktuelle-aktionen', [
            'Open the offers page.',
            'For more offers, click “Weitere Produkte anzeigen” at the end of the page (repeatedly if needed).',
            'Select all text (Ctrl+A, on the Mac Cmd+A) and copy it.',
            'Paste it into the text field and tap “Read text”.',
        ]],
    ];
@endphp
<div class="space-y-3">
    <label class="button-secondary flex w-full cursor-pointer items-center justify-center gap-2" :class="busy && 'pointer-events-none opacity-50'">
        <input type="file" accept="image/*,application/pdf" multiple class="sr-only" :disabled="busy" @change="readOfferFiles($event)">
        <x-spinner x-show="busyKey === 'offerFiles'" />
        <span x-text="label('offerFiles', $t('Add screenshots, photos or PDF'))"></span>
    </label>
    <x-planner-error key="offerFiles" />
    <form @submit.prevent="readOfferText()" class="space-y-2">
        <textarea x-model="offerText" rows="2" class="field w-full text-sm" :placeholder="$t('… or paste the text of an offers page')"></textarea>
        <button x-show="offerText.trim() || busyKey === 'offerText'" class="button-secondary inline-flex items-center gap-2 text-sm" :disabled="busy">
            <x-spinner x-show="busyKey === 'offerText'" />
            <span x-text="label('offerText', $t('Read text'))"></span>
        </button>
        <x-planner-error key="offerText" />
    </form>
    <div x-data="{ help: false }" class="space-y-2 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <label class="flex items-center gap-2 text-stone-600">
                <input type="checkbox" class="size-4 accent-emerald-700" :checked="shareOffers" @change="setShareOffers($event.target.checked)">
                <span x-text="$t('Share with all users')"></span>
            </label>
            <button type="button" class="text-stone-500 underline" :aria-expanded="help" @click="help = !help" x-text="$t('Where do I get the offers?')"></button>
        </div>
        <div x-show="help" x-cloak class="space-y-3 rounded-lg bg-stone-50 p-3">
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
    </div>
</div>
