import { computed, ref } from 'vue';

const STORAGE_KEY = 'theme';
const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;

// Module-scope refs make this a singleton shared by every importer — the
// same lightweight pattern useChunkedUpload's callers rely on, without
// pulling in a state-management library for one value.
const theme = ref(readStoredTheme());
const systemPrefersDark = ref(media?.matches ?? false);

function readStoredTheme() {
    if (typeof window === 'undefined') return 'system';
    const stored = localStorage.getItem(STORAGE_KEY);
    return stored === 'light' || stored === 'dark' ? stored : 'system';
}

function apply() {
    const isDark = theme.value === 'dark' || (theme.value === 'system' && systemPrefersDark.value);
    document.documentElement.classList.toggle('dark', isDark);
}

media?.addEventListener('change', (event) => {
    systemPrefersDark.value = event.matches;
    if (theme.value === 'system') apply();
});

apply();

/**
 * `theme` is the user's stored choice ('light' | 'dark' | 'system'); the
 * blade template already applies the correct class before Vue mounts (see
 * app.blade.php) so there's no flash — this composable just keeps it in
 * sync afterwards and exposes the toggle.
 */
export function useTheme() {
    const isDark = computed(() => theme.value === 'dark' || (theme.value === 'system' && systemPrefersDark.value));

    function setTheme(next) {
        theme.value = next;
        if (next === 'system') {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, next);
        }
        apply();
    }

    function toggle() {
        setTheme(isDark.value ? 'light' : 'dark');
    }

    return { theme, isDark, setTheme, toggle };
}
