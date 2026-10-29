@props(['actions'])

{{--
    List row with actions: touch swipe left or long press (user setting, resources/js/swipe.js),
    a "…" menu (mouse: visible on hover, keyboard: on focus) and the right-click menu.
    $actions: JS expression returning [{ label, run, danger? }].
--}}
<li x-data="swipeRow" {{ $attributes->merge(['class' => 'group relative overflow-hidden bg-white']) }} :class="lifted && 'shadow-lg ring-1 ring-stone-200'"
    @pointerdown.window="open && !$root.contains($event.target) && close()"
    @row-menu="openMenu($el, {{ $actions }})">
    <div x-ref="actions" aria-hidden="true" class="absolute inset-y-0 right-0 flex overflow-hidden" :style="actionsStyle"
        :class="dragging || 'transition-[width] duration-200 motion-reduce:transition-none'">
        <template x-for="action in {{ $actions }}" :key="action.label">
            <button type="button" tabindex="-1" class="flex w-16 items-center text-white" :title="action.label" :aria-label="action.label"
                :class="['shrink-0 justify-center', action.danger ? 'grow bg-red-600' : action.icon === 'share' ? 'bg-sky-700' : 'bg-stone-500', action.online && !$store.sync.online && 'opacity-50']"
                @click="act(action.run)">
                <svg viewBox="0 0 24 24" class="size-6 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path x-show="action.icon === 'edit'" d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/>
                    <g x-show="action.icon === 'share'"><circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M18 8v6M15 11h6"/></g>
                    <path x-show="action.icon === 'delete'" d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>
                    <path x-show="action.icon === 'leave'" d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h10"/>
                </svg>
            </button>
        </template>
    </div>

    <div class="relative flex touch-pan-y items-center bg-white select-none [-webkit-touch-callout:none]" :style="contentStyle"
        :class="dragging || 'transition-transform duration-200 motion-reduce:transition-none'"
        @pointerdown="down($event)" @pointermove="move($event)" @pointerup="up()" @pointercancel="up()"
        @click.capture="guardClick($event)" @contextmenu="contextMenu($event)">
        {{ $slot }}

        <button type="button" class="icon-button mr-2 text-stone-500 pointer-coarse:hidden pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 pointer-fine:group-focus-within:opacity-100"
            :aria-label="$t('More actions')" aria-haspopup="menu" @click.stop="openMenu($event.currentTarget, {{ $actions }})">
            <svg viewBox="0 0 24 24" class="size-5" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
        </button>
    </div>
</li>
