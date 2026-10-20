import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    // RevoGrid loads parts of itself on demand. Preload links would point at
    // /build/ instead of the addon's published folder, so leave them out.
    build: {
        modulePreload: false,
    },
    plugins: [
        laravel({
            input: ['resources/js/addon.js', 'resources/css/addon.css'],
            publicDirectory: 'resources/dist',
        }),
        statamic(),
        tailwindcss(),
    ],
});
