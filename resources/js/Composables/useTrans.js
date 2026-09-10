import { usePage } from '@inertiajs/vue3';

/**
 * Reads the `translations` prop every Inertia page already receives
 * (HandleInertiaRequests: lang/{locale}.json merged with any admin
 * TranslationOverride rows for the current user's locale).
 *
 * `fallback` is the string the component would otherwise have hardcoded —
 * passing it means a key doesn't have to exist in lang/ar.json for the
 * Arabic UI to keep working exactly as before; only lang/en.json (and,
 * optionally, an admin override) needs the key to translate it.
 */
export function useTrans() {
    const page = usePage();

    function t(key, fallback = null, replace = {}) {
        let value = page.props.translations?.[key] ?? fallback ?? key;

        for (const [name, replacement] of Object.entries(replace)) {
            value = value.replaceAll(`:${name}`, replacement);
        }

        return value;
    }

    return { t };
}
