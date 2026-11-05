import { useTrans } from '@/Composables/useTrans';

// Labels and colours shared by the library pages.
export function useLibrary() {
    const { t } = useTrans();

    const statusLabels = {
        available: () => t('library.status.available', 'متاحة'),
        loaned: () => t('library.status.loaned', 'مُعارة'),
        on_hold: () => t('library.status.on_hold', 'محجوزة'),
        damaged: () => t('library.status.damaged', 'تالفة'),
        lost: () => t('library.status.lost', 'مفقودة'),
    };
    const statusClasses = {
        available: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        loaned: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
        on_hold: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
        damaged: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        lost: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    };

    return {
        statusLabel: (s) => (statusLabels[s] ? statusLabels[s]() : s),
        statusClass: (s) => statusClasses[s] ?? 'bg-gray-100 text-gray-600',
    };
}
