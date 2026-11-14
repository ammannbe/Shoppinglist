{{-- Banner of an announcement (channel "banner", resources/js/announcements.js): the newest one not closed yet, same
     pattern as the banner "Link with …" (partials/oidc-nudge.blade.php). Closing works offline and is done for good. --}}
<template x-if="announcementsEnabled && $store.announcements.banner">
    <section class="notice mt-3 space-y-3" :aria-label="$store.announcements.banner.title">
        <div class="flex items-start gap-2">
            <div class="min-w-0 flex-1 space-y-1">
                <p class="font-medium" x-text="$store.announcements.banner.title"></p>
                <p x-show="$store.announcements.banner.excerpt" class="text-sm" x-text="$store.announcements.banner.excerpt"></p>
            </div>
            <button type="button" class="-m-1 shrink-0 p-1 text-lg leading-none" @click="$store.announcements.dismiss($store.announcements.banner.id)" :aria-label="$t('Close')">✕</button>
        </div>
        <div x-show="$store.announcements.banner.in_app || $store.announcements.banner.link" class="flex flex-wrap gap-2">
            <button type="button" class="button" @click="openBanner($store.announcements.banner)" x-text="$t('Learn more')"></button>
        </div>
    </section>
</template>
