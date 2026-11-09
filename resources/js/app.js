// Fonts are bundled (no CDN) so the offline server looks the same without internet.
import '@fontsource/figtree/400.css';
import '@fontsource/figtree/500.css';
import '@fontsource/figtree/600.css';
import '@fontsource/cairo/400.css';
import '@fontsource/cairo/500.css';
import '@fontsource/cairo/600.css';
import '@fontsource/cairo/700.css';
import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// The site name from the admin settings (meta tag in app.blade.php), never the framework's name.
const appName = () => document.querySelector('meta[name="app-name"]')?.content || import.meta.env.VITE_APP_NAME || 'FileNest';
const RTL_LOCALES = ['ar'];

function applyDirection(page) {
    // Arabic only (by request): the interface is always Arabic, right to left.
    const locale = 'ar';
    document.documentElement.lang = locale;
    const dir = page.props.appSettings?.locales?.[locale]?.dir;
    document.documentElement.dir = dir ?? (RTL_LOCALES.includes(locale) ? 'rtl' : 'ltr');
}

router.on('success', (event) => applyDirection(event.detail.page));

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName()}` : appName()),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        applyDirection(props.initialPage);

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// The installable app (invigilators' phones): only where the browser allows it (https or localhost).
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
// Kept for the «install the app» button.
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    window.__installPrompt = e;
    window.dispatchEvent(new Event('app-installable'));
});

// «خريطة القاعات»: every page tells the server it is on, with the computer's clock, so the halls'
// map shows which computers are on, which are off and which have a wrong time (only hall
// computers, known by their address, are recorded).
(() => {
    const ping = () => { if (navigator.onLine !== false) fetch('/hall/ping?t=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' }).catch(() => {}); };
    setTimeout(ping, 1500);
    setInterval(ping, 30000);
})();
