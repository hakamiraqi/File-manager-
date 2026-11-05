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
    const locale = page.props.auth?.user?.locale ?? page.props.appSettings?.locale ?? 'ar';
    document.documentElement.lang = locale;
    document.documentElement.dir = RTL_LOCALES.includes(locale) ? 'rtl' : 'ltr';
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
