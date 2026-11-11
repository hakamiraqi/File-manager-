// «خريطة المكتبة» bundled for the apps to keep and open without internet (2026-11-11, by
// request): the same map page as the website (3D and flat plan), fed from window.FILENEST_MAP,
// which the app writes next to this file. Built by vite.offline-map.config.js as one script.
import '@fontsource/cairo/400.css';
import '@fontsource/cairo/700.css';
import '../../css/app.css';
import axios from 'axios';
import { createApp, h } from 'vue';
import MapPage from '@/Pages/Library/Map.vue';
import { page } from './inertia-shim.js';

const data = window.FILENEST_MAP || {};
page.props.auth.user = data.user || null;
page.props.translations = data.translations || {};

// No server here: links go nowhere, and the map's searches answer from the saved books.
window.route = (name, params) => `offline:${name}${params ? `:${typeof params === 'object' ? (params.id ?? JSON.stringify(params)) : params}` : ''}`;
const books = data.books || [];
axios.interceptors.request.use((config) => {
    const url = String(config.url || '');
    if (!url.startsWith('offline:')) return config;
    config.adapter = async () => {
        let out = {};
        if (url.startsWith('offline:library.map.search')) {
            const q = String(config.params?.q || '').trim().toLowerCase();
            out = { books: q.length < 2 ? [] : books.filter((b) => `${b.title} ${b.authors || ''}`.toLowerCase().includes(q)).slice(0, 8) };
        } else if (url.startsWith('offline:library.map.shelf-books')) {
            const id = Number(url.split(':').pop());
            out = {
                books: books.flatMap((b) => (b.spots || []).filter((s) => s.shelf_id === id).map((s) => ({ id: b.id, title: b.title, authors: b.authors, cover_url: null, level: s.level, copies: s.total }))).slice(0, 60),
            };
        }
        return { data: out, status: 200, statusText: 'OK', headers: {}, config };
    };
    return config;
});

document.documentElement.lang = 'ar';
document.documentElement.dir = 'rtl';
if (data.dark) document.documentElement.classList.add('dark');

const app = createApp({
    render: () => h(MapPage, {
        floor: data.floor,
        shelves: data.shelves || [],
        stock: data.stock || {},
        target: data.target || null,
        canEdit: false,
        startAt: null,
        embedded: true,
        status: null,
    }),
});
app.config.globalProperties.route = window.route;
app.mount('#app');
