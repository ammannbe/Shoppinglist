import { defineConfig } from 'vitest/config';

// Unit tests of the client logic without the browser (npm test). The app config (vite.config.js)
// is not used: its Laravel and PWA plugins only matter for the build.
export default defineConfig({
    test: {
        include: ['tests/js/**/*.test.js'],
        environment: 'node',
    },
});
