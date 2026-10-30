@props(['tag', 'muted' => false])
{{-- Shop of an item: its icon, otherwise the tag (code or name). `tag` is a JS expression. --}}
<span {{ $attributes->class(['mr-1.5 inline-flex shrink-0']) }} :title="storeLabel({{ $tag }})">
    <img x-show="storeIcon({{ $tag }})" :src="storeIcon({{ $tag }})" :alt="storeLabel({{ $tag }})" decoding="async"
        @class(['h-6 w-auto max-w-20 rounded bg-white object-contain', 'opacity-50' => $muted])>
    <span x-show="!storeIcon({{ $tag }})" x-text="{{ $tag }}"
        @class([
            'grid h-5 min-w-5 max-w-24 place-items-center truncate rounded px-1 text-xs font-semibold',
            'bg-stone-100' => $muted,
            'bg-orange-100 text-orange-800' => ! $muted,
        ])></span>
</span>
