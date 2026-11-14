{{-- List "News" (#/news, bell in the header): announcements of the AccountManager with the channel "in_app", cached on
     the device (resources/js/announcements.js), so they are readable offline. body_html is sanitized by the AccountManager. --}}
<section x-show="route.view === 'news'" class="space-y-4">
    <p x-show="!$store.announcements.news.length" class="py-8 text-center text-stone-500" x-text="$t('No news yet.')"></p>
    <template x-for="item in $store.announcements.news" :key="item.id">
        <article :id="`news-${item.id}`" class="scroll-mt-20 space-y-3 rounded-lg border bg-white p-4"
            :class="newsFresh.includes(item.id) ? 'border-emerald-600' : 'border-stone-200'">
            <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500">
                <span class="rounded-full px-2 py-0.5 font-medium" :class="newsType(item.type).badge" x-text="newsType(item.type).label"></span>
                <time x-text="newsDate(item.published_at)"></time>
                <span x-show="newsFresh.includes(item.id)" class="font-medium text-emerald-700" x-text="$t('New')"></span>
            </div>
            <h2 class="text-lg font-semibold" x-text="item.title"></h2>
            <template x-if="item.image_url">
                <img :src="item.image_url" alt="" loading="lazy" class="max-h-72 w-full rounded-md bg-stone-100 object-cover">
            </template>
            <div x-show="item.body_html" class="news-body" x-html="item.body_html"></div>
            <p x-show="!item.body_html && item.excerpt" x-text="item.excerpt"></p>
            <a x-show="item.link" :href="item.link" target="_blank" rel="noopener" data-online class="inline-block font-medium text-emerald-700 underline" x-text="$t('Learn more')"></a>
        </article>
    </template>
</section>
