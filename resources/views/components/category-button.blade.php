{{-- Category of a product in the products view (`entry`): opens the category picker. --}}
<button type="button" class="shrink-0 rounded-full border border-stone-300 bg-white px-2.5 py-0.5 text-xs text-stone-600 hover:bg-stone-50"
    @click.stop="openCategoryPicker(entry)" :title="$t('Category')" x-text="categoryLabel(categoryOf(entry.name))"></button>
