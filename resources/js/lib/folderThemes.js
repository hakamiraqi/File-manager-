// Folder themes (2026-11-28): one per Tikrit University college, plus a few plain ones.
// The colours follow the usual academic faculty colours (lilac for dentistry, green for
// medicine, purple for law…), not an official palette: each folder can still pick its own colour,
// logo and cover picture on top of a theme.

export const FOLDER_THEMES = [
    { key: 'tu-university', group: 'tikrit', name: 'رئاسة جامعة تكريت', primary: '#1e3a8a', secondary: '#ca8a04', icon: 'cap', pattern: 'tablet' },
    { key: 'tu-dentistry', group: 'tikrit', name: 'كلية طب الأسنان', primary: '#6d28d9', secondary: '#c4b5fd', icon: 'tooth', pattern: 'dots' },
    { key: 'tu-medicine', group: 'tikrit', name: 'كلية الطب', primary: '#15803d', secondary: '#86efac', icon: 'heart', pattern: 'cross' },
    { key: 'tu-pharmacy', group: 'tikrit', name: 'كلية الصيدلة', primary: '#4d7c0f', secondary: '#d9f99d', icon: 'beaker', pattern: 'hex' },
    { key: 'tu-nursing', group: 'tikrit', name: 'كلية التمريض', primary: '#c2410c', secondary: '#fed7aa', icon: 'heart', pattern: 'cross' },
    { key: 'tu-veterinary', group: 'tikrit', name: 'كلية الطب البيطري', primary: '#475569', secondary: '#cbd5e1', icon: 'paw', pattern: 'dots' },
    { key: 'tu-engineering', group: 'tikrit', name: 'كلية الهندسة', primary: '#c2410c', secondary: '#fdba74', icon: 'cog', pattern: 'grid' },
    { key: 'tu-petroleum', group: 'tikrit', name: 'كلية هندسة النفط والمعادن', primary: '#292524', secondary: '#f59e0b', icon: 'fire', pattern: 'grid' },
    { key: 'tu-science', group: 'tikrit', name: 'كلية العلوم', primary: '#a16207', secondary: '#fde047', icon: 'atom', pattern: 'hex' },
    { key: 'tu-computer', group: 'tikrit', name: 'كلية علوم الحاسوب والرياضيات', primary: '#0e7490', secondary: '#67e8f9', icon: 'chip', pattern: 'grid' },
    { key: 'tu-agriculture', group: 'tikrit', name: 'كلية الزراعة', primary: '#3f6212', secondary: '#facc15', icon: 'leaf', pattern: 'dots' },
    { key: 'tu-edu-pure', group: 'tikrit', name: 'كلية التربية للعلوم الصرفة', primary: '#0369a1', secondary: '#7dd3fc', icon: 'cap', pattern: 'hex' },
    { key: 'tu-edu-human', group: 'tikrit', name: 'كلية التربية للعلوم الإنسانية', primary: '#1d4ed8', secondary: '#bfdbfe', icon: 'book', pattern: 'tablet' },
    { key: 'tu-edu-women', group: 'tikrit', name: 'كلية التربية للبنات', primary: '#be185d', secondary: '#fbcfe8', icon: 'book', pattern: 'dots' },
    { key: 'tu-sports', group: 'tikrit', name: 'كلية التربية البدنية وعلوم الرياضة', primary: '#047857', secondary: '#6ee7b7', icon: 'trophy', pattern: 'grid' },
    { key: 'tu-arts', group: 'tikrit', name: 'كلية الآداب', primary: '#b91c1c', secondary: '#fecaca', icon: 'book', pattern: 'tablet' },
    { key: 'tu-law', group: 'tikrit', name: 'كلية الحقوق', primary: '#6b21a8', secondary: '#e9d5ff', icon: 'scale', pattern: 'tablet' },
    { key: 'tu-politics', group: 'tikrit', name: 'كلية العلوم السياسية', primary: '#1e40af', secondary: '#fcd34d', icon: 'globe', pattern: 'dots' },
    { key: 'tu-economics', group: 'tikrit', name: 'كلية الإدارة والاقتصاد', primary: '#9a3412', secondary: '#fdba74', icon: 'chart', pattern: 'grid' },
    { key: 'tu-islamic', group: 'tikrit', name: 'كلية العلوم الإسلامية', primary: '#166534', secondary: '#fde68a', icon: 'moon', pattern: 'star' },
    { key: 'plain-clay', group: 'plain', name: 'طين رافدي', primary: '#9a3412', secondary: '#fcd9b6', icon: 'tablet', pattern: 'tablet' },
    { key: 'plain-night', group: 'plain', name: 'ليلي', primary: '#0f172a', secondary: '#38bdf8', icon: 'folder', pattern: 'star' },
    { key: 'plain-archive', group: 'plain', name: 'أرشيف رسمي', primary: '#334155', secondary: '#e2e8f0', icon: 'archive', pattern: 'grid' },
];

export const COLOR_SWATCHES = [
    '#f59e0b', '#ef4444', '#ec4899', '#a855f7', '#6d28d9', '#2563eb', '#0284c7',
    '#0d9488', '#16a34a', '#65a30d', '#ca8a04', '#ea580c', '#78716c', '#334155',
];

export function themeByKey(key) {
    return FOLDER_THEMES.find((t) => t.key === key) ?? null;
}

function hexToRgb(hex) {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
    if (!m) return null;
    const n = parseInt(m[1], 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

function mix(rgb, target, amount) {
    const c = rgb.map((v, i) => Math.round(v + (target[i] - v) * amount));
    return '#' + c.map((v) => v.toString(16).padStart(2, '0')).join('');
}

/** The --accent-* variables for a colour, so the buttons and links in the folder take it on. */
export function accentVars(hex) {
    const rgb = hexToRgb(hex);
    if (!rgb) return {};
    const white = [255, 255, 255];
    const black = [0, 0, 0];
    return {
        '--accent-50': mix(rgb, white, 0.92),
        '--accent-100': mix(rgb, white, 0.82),
        '--accent-400': mix(rgb, white, 0.3),
        '--accent-500': mix(rgb, white, 0.12),
        '--accent-600': mix(rgb, black, 0),
        '--accent-700': mix(rgb, black, 0.2),
    };
}

/** The colour a folder shows: its own, else its theme's, else none (the default amber). */
export function folderColor(folder) {
    return folder?.color || themeByKey(folder?.theme)?.primary || null;
}
