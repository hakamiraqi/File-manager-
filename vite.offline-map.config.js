// The library map as one script and one stylesheet the apps download and open without
// internet (see resources/js/offline-map/main.js). Built after the site: `npm run build`.
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

const here = (p) => fileURLToPath(new URL(p, import.meta.url));

export default defineConfig({
    plugins: [vue()],
    publicDir: false,
    resolve: {
        alias: {
            '@inertiajs/vue3': here('./resources/js/offline-map/inertia-shim.js'),
            '@': here('./resources/js'),
        },
    },
    define: { 'process.env.NODE_ENV': JSON.stringify('production') },
    build: {
        outDir: 'public/build/offline-map',
        emptyOutDir: true,
        cssCodeSplit: false,
        assetsInlineLimit: 100000000,
        lib: {
            entry: here('./resources/js/offline-map/main.js'),
            name: 'FileNestMap',
            formats: ['iife'],
            fileName: () => 'map.js',
            cssFileName: 'map',
        },
        rollupOptions: { output: { inlineDynamicImports: true, assetFileNames: 'map.[ext]' } },
    },
});
