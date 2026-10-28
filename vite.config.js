import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
        // Service worker and manifest live in public/ (scope "/"), the assets in public/build.
        VitePWA({
            outDir: 'public',
            buildBase: '/',
            scope: '/',
            registerType: 'autoUpdate',
            injectRegister: false,
            // Static file public/manifest.webmanifest (the generated one would end up in public/build).
            manifest: false,
            workbox: {
                globDirectory: 'public',
                globPatterns: ['build/**/*.{js,css,woff,woff2}', 'icons/*.png', 'favicon.ico', 'manifest.webmanifest'],
                // The app shell is rendered by Laravel; a new revision on every build updates it.
                additionalManifestEntries: [{ url: '/', revision: Date.now().toString() }],
                navigateFallback: '/',
                navigateFallbackAllowlist: [/^\/$/],
                cleanupOutdatedCaches: true,
            },
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
