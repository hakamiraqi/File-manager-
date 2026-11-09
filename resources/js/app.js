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
    // Plain modules (notifications.js) read the page's translations from here.
    window.__inertiaTranslations = page.props.translations ?? {};
    const locale = page.props.auth?.user?.locale ?? page.props.appSettings?.locale ?? 'ar';
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
    // The clock is measured like NTP (2026-11-09): the server's time against the middle of the
    // round trip; the result goes with the next ping, and pages can use window.__serverOffset.
    let offset = null;
    const ping = async () => {
        if (navigator.onLine === false) return;
        const t0 = Date.now();
        try {
            const r = await fetch('/hall/ping?t=' + t0 + (offset !== null ? '&o=' + Math.round(offset) : ''), { credentials: 'same-origin', cache: 'no-store' });
            const t1 = Date.now();
            const j = await r.json();
            if (j?.server && t1 - t0 < 5000) { offset = (t0 + t1) / 2 - j.server; window.__serverOffset = offset; }
        } catch {}
    };
    setTimeout(ping, 1500);
    setInterval(ping, 30000);
})();
