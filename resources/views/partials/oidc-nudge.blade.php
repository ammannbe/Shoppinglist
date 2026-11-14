{{-- Banner "Link with …" (AUTH_OIDC_NUDGE, App\Auth\OidcNudge, resources/js/oidc-nudge.js). Part of the app shell:
     the state comes from /me, "Later" also works offline. Same file as in Chorelist. --}}
<section x-data="oidcNudge" x-show="nudge" x-cloak class="notice mt-3 space-y-3" :aria-label="$t('Link :provider', { provider: nudge?.provider })">
    <div class="flex items-start gap-2">
        <div class="min-w-0 flex-1 space-y-1">
            <p class="font-medium" x-text="$t('Link :provider', { provider: nudge?.provider })"></p>
            <p class="text-sm" x-text="$t('One account for all Narrenhaus apps: sign in once, households and unlocked features everywhere.')"></p>
        </div>
        <button type="button" class="-m-1 shrink-0 p-1 text-lg leading-none" @click="dismissNudge()" :aria-label="$t('Close')">✕</button>
    </div>
    <div class="flex flex-wrap gap-2">
        <button type="button" class="button" @click="linkNow()" :disabled="!$store.sync.online" x-text="$t('Link now')"></button>
        <button type="button" class="button-secondary" @click="dismissNudge()" x-text="$t('Later')"></button>
    </div>
</section>
