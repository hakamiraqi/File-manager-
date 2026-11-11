// The library map without the server (2026-11-11): what the map page takes from Inertia,
// answered from the data the app saved on the device.
import { h, reactive } from 'vue';

export const page = reactive({ props: { auth: { user: null }, translations: {}, inApp: true, appSettings: {} }, url: '/' });

export const usePage = () => page;
export const Head = { render: () => null };
export const Link = {
    props: { href: String, as: String, method: String },
    setup: (props, { slots, attrs }) => () => h('a', { ...attrs, href: props.href || '#' }, slots.default?.()),
};
const noop = () => {};
export const router = { visit: noop, get: noop, post: noop, put: noop, patch: noop, delete: noop, reload: noop, on: () => noop };
export function useForm(data = {}) {
    return reactive({ ...data, errors: {}, processing: false, data: () => data, post: noop, put: noop, patch: noop, delete: noop, get: noop, reset: noop, clearErrors: noop, transform() { return this; } });
}
export function createInertiaApp() {}
