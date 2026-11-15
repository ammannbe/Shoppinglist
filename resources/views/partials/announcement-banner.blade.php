{{-- Banner of an announcement (channel "banner", resources/js/announcements.js): the newest one not closed yet, same
     pattern as the banner "Link with …" (partials/oidc-nudge.blade.php). "Read more" opens it in "News" (channel
     in_app), "Learn more" its link; both and ✕ close it for good, also offline (sent when online). x-show, not x-if:
     the links must stay in the document while their click navigates. Same file in Shoppinglist and Chorelist. --}}
<section x-show="$store.announcements.enabled && $store.announcements.banner" x-cloak class="notice mt-3 space-y-3"
    :aria-label="$store.announcements.banner?.title">
    <div class="flex items-start gap-2">
        <div class="min-w-0 flex-1 space-y-1">
            <p class="font-medium" x-text="$store.announcements.banner?.title"></p>
            <p x-show="$store.announcements.banner?.excerpt" class="text-sm" x-text="$store.announcements.banner?.excerpt"></p>
        </div>
        <button type="button" class="-m-1 shrink-0 p-1 text-lg leading-none" @click="$store.announcements.dismiss($store.announcements.banner?.id)" :aria-label="$t('Close')">✕</button>
    </div>
    <div x-show="$store.announcements.banner?.in_app || $store.announcements.banner?.link" class="flex flex-wrap gap-2">
        <a x-show="$store.announcements.banner?.in_app" :href="`#/news/${$store.announcements.banner?.id}`" class="button"
            @click="$store.announcements.dismiss($store.announcements.banner?.id)" x-text="$t('Read more')"></a>
        <a x-show="$store.announcements.banner?.link" :href="$store.announcements.banner?.link" target="_blank" rel="noopener" data-online
            :class="$store.announcements.banner?.in_app ? 'button-secondary' : 'button'"
            @click="$store.announcements.dismiss($store.announcements.banner?.id)" x-text="$t('Learn more')"></a>
    </div>
</section>
