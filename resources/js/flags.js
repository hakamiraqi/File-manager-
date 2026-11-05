// Country flags bundled from flag-icons (no CDN, works offline) and plain icons for languages.
import { LanguageIcon, GlobeAltIcon, ChatBubbleLeftRightIcon, BookOpenIcon, AcademicCapIcon } from '@heroicons/vue/24/outline';

const FLAGS = import.meta.glob('/node_modules/flag-icons/flags/4x3/*.svg', { eager: true, query: '?url', import: 'default' });

export const FLAG_URLS = {};
for (const [path, url] of Object.entries(FLAGS)) FLAG_URLS[path.split('/').pop().replace('.svg', '')] = url;

export const FLAG_CODES = Object.keys(FLAG_URLS).filter((c) => /^[a-z]{2}$/.test(c));

export const LANGUAGE_ICONS = { language: LanguageIcon, globe: GlobeAltIcon, chat: ChatBubbleLeftRightIcon, book: BookOpenIcon, academic: AcademicCapIcon };

/** Country name in the viewer's language (built into the browser). */
export function countryName(code, locale = 'ar') {
    try {
        return new Intl.DisplayNames([locale], { type: 'region' }).of(code.toUpperCase()) ?? code;
    } catch {
        return code;
    }
}
