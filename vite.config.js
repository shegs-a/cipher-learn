import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// Tailwind v3 is wired through PostCSS (see postcss.config.js) rather than the
// v4 Vite plugin, so tokens live in tailwind.config.js as the brief requires and
// the pipeline matches Filament 3's own Tailwind v3 toolchain.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
