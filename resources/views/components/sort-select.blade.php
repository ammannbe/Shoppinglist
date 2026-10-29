@props(['value', 'change'])

{{-- Small "Sort: A–Z" line above a list; $value/$change are JS expressions. --}}
<label {{ $attributes->merge(['class' => 'mb-2 flex items-center justify-end gap-1 text-sm text-stone-500']) }}>
    <span x-text="$t('Sort:')"></span>
    <select class="cursor-pointer appearance-none bg-transparent pr-1 font-medium text-stone-700 underline decoration-stone-300 underline-offset-4 focus:outline-none"
        @change="{{ $change }}">
        <template x-for="sort in sorts" :key="sort">
            <option :value="sort" :selected="sort === {{ $value }}" x-text="sortLabel(sort)"></option>
        </template>
    </select>
    <span aria-hidden="true">▾</span>
</label>
