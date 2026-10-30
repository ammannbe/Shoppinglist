@props(['number', 'title'])

{{-- A step of the week planner as card: number, title (JS expression), optional actions on the right --}}
<div {{ $attributes->merge(['class' => 'space-y-4 rounded-xl border border-stone-200 bg-white p-4']) }}>
    <div class="flex items-center gap-3">
        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-emerald-700 text-sm font-semibold text-white" aria-hidden="true">{{ $number }}</span>
        <h2 class="min-w-0 flex-1 font-semibold" x-text="{{ $title }}"></h2>
        {{ $actions ?? '' }}
    </div>
    {{ $slot }}
</div>
