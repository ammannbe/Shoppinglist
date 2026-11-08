@props(['done' => false])

{{--
    Content of an item row (inside <x-swipe-row>, `item` in scope): the checkbox (own 44 px hit
    area) checks the item off, a tap on the rest of the row opens the edit dialog, the thumbnail
    opens the photo. In a pantry the check means used up and shops and recipe links are not shown. The recipe links are below the row button (no links inside a button); the
    rest of that line edits too. Swipe, long press and dragging are handled by the swipe row.
--}}
{{-- With recipe links below: aligned with the first line --}}
<button type="button" class="ml-1 grid size-11 shrink-0 place-items-center" :class="rowRecipes(item).length && 'mt-1 self-start'" role="checkbox" aria-checked="{{ $done ? 'true' : 'false' }}"
    :aria-label="checkLabel(item)" @click="toggle(item)">
    @if ($done)
        <span class="check check-done mx-0" aria-hidden="true">
            <svg viewBox="0 0 24 24" class="size-4"><path d="M5 12l5 5 9-10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
    @else
        <span class="check mx-0" aria-hidden="true"></span>
    @endif
</button>
<div class="min-w-0 flex-1">
    <div class="flex min-w-0 items-center">
        <button type="button" x-show="item.image" class="mr-2 shrink-0 cursor-zoom-in rounded-md" @click="openImage(item.image)" :aria-label="$t('Show photo')">
            <img :src="item.image ? imageUrl(item.image, 'thumb') : ''" alt="" loading="lazy" decoding="async" draggable="false"
                class="size-8 rounded-md bg-stone-100 object-cover {{ $done ? 'opacity-50' : '' }}">
        </button>
        <button type="button" class="flex min-h-11 min-w-0 flex-1 items-center pr-4 text-left" :class="rowRecipes(item).length ? 'pt-3 pb-0.5' : 'py-3'"
            :aria-label="$t('Edit “:name”', { name: [amount(item), item.name].filter(Boolean).join(' ') })" @click="edit(item)">
            <template x-for="tag in rowStores(item)" :key="tag">
                <x-store-badge tag="tag" :muted="$done" />
            </template>
            <span x-show="rowStores(item).length" class="mr-0.5" aria-hidden="true"></span>
            <span x-show="amount(item)" class="mr-2 shrink-0 tabular-nums {{ $done ? 'line-through' : 'text-stone-500' }}" x-text="amount(item)"></span>
            <span class="min-w-0">
                <span class="block truncate {{ $done ? 'line-through' : '' }}" x-text="item.name"></span>
                <span x-show="item.note" class="block whitespace-pre-line break-words text-xs {{ $done ? '' : 'text-stone-500' }}" x-text="item.note"></span>
            </span>
        </button>
    </div>
    {{-- Saved recipes open in the Recipes tab, older entries (no key) show the name --}}
    <p x-show="rowRecipes(item).length" class="truncate pr-4 pb-3 text-xs {{ $done ? 'text-violet-400' : 'text-violet-700' }}" @click="$event.target.closest('a') || edit(item)">
        <span x-text="$t('For')"></span>
        <template x-for="(recipe, index) in rowRecipes(item)" :key="index">
            <span><span x-show="index > 0">, </span><a x-show="recipeHref(recipe)" :href="recipeHref(recipe)" class="underline" draggable="false" x-text="recipe.name"></a><span x-show="!recipeHref(recipe)" x-text="recipe.name"></span></span>
        </template>
    </p>
</div>
