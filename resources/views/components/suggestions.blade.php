@props(['id'])

{{--
    Dropdown of a suggestField (resources/js/suggest.js), placed next to its input
    (role="combobox", aria-controls = $id) inside a relative wrapper.
    pointerdown/mousedown are prevented so the input keeps the focus (and the keyboard stays open).
--}}
<ul id="{{ $id }}" role="listbox" x-show="visible" x-cloak
    class="absolute inset-x-0 top-full z-20 mt-1 max-h-[min(22rem,50dvh)] overflow-y-auto rounded-lg border border-stone-200 bg-white py-1 shadow-lg">
    <template x-for="(option, index) in options" :key="`${option.kind}:${option.name}`">
        <li :id="`{{ $id }}-${index}`" role="option" :aria-selected="index === active"
            class="flex cursor-pointer items-center gap-2 px-3 py-2.5"
            :class="index === active ? 'bg-stone-100' : 'hover:bg-stone-50'"
            @pointerdown.prevent @mousedown.prevent @click="pick(option)">
            <span x-show="option.kind === 'store'" class="grid h-5 min-w-5 shrink-0 place-items-center rounded bg-orange-100 px-1 text-xs font-semibold text-orange-800" x-text="option.code"></span>
            <span class="min-w-0 flex-1 truncate" x-text="option.name"></span>
            <span x-show="option.kind === 'unit' && source === 'item'" class="shrink-0 rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600" x-text="$t('Unit')"></span>
            <span x-show="option.onList" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800" x-text="$t('on the list')"></span>
        </li>
    </template>
</ul>
