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
            // Favicons and public/site.webmanifest come from `npm run favicon` (RealFaviconGenerator).
            manifest: false,
            workbox: {
                globDirectory: 'public',
                // Every precached file must exist on the server, one 404 aborts the whole installation.
                globPatterns: [
                    'build/**/*.{js,css,woff,woff2}',
                    'favicon.ico',
                    'favicon-96x96.png',
                    'apple-touch-icon.png',
                    'web-app-manifest-*.png',
                    'site.webmanifest',
                ],
                // Favicon URLs carry a cache busting ?v=<hash>.
                ignoreURLParametersMatching: [/^v$/],
                // The app shell is rendered by Laravel; a new revision on every build updates it.
                additionalManifestEntries: [{ url: '/', revision: Date.now().toString() }],
                navigateFallback: '/',
                navigateFallbackAllowlist: [/^\/$/],
                // Other pages (login, password reset, …) need the server; offline they fall back to the app shell.
                runtimeCaching: [{
                    urlPattern: ({ request }) => request.mode === 'navigate',
                    handler: 'NetworkOnly',
                    options: { precacheFallback: { fallbackURL: '/' } },
                }],
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
