import { readFileSync } from 'node:fs';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        {
            name: 'kraftink-bootstrap-icon-catalog',
            apply: 'build',
            generateBundle() {
                this.emitFile({
                    type: 'asset',
                    fileName: 'bootstrap-icons.json',
                    source: readFileSync(new URL('node_modules/bootstrap-icons/font/bootstrap-icons.json', import.meta.url)),
                });
            },
        },
        laravel({
            input: [
                'resources/scss/app.scss',
                'resources/scss/public.scss',
                'resources/scss/admin.scss',
                'resources/js/app.js',
                'resources/js/admin.js',
            ],
            refresh: true,
        }),
    ],
    build: { sourcemap: false },
});
