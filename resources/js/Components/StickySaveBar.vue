<script setup>
// Save bar that stays at the bottom of the screen on settings pages (2026-11-05, by request:
// the save button was hard to find at the end of long pages).
import { useTrans } from '@/Composables/useTrans';
import { CheckCircleIcon, ExclamationCircleIcon, ArrowUturnRightIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
defineProps({
    form: { type: Object, required: true },
    label: { type: String, default: '' },
    dirty: { type: Boolean, default: undefined },
});
const emit = defineEmits(['save', 'reset']);
</script>

<template>
    <div class="sticky bottom-0 z-20 -mx-4 mt-6 border-t border-gray-200 bg-white/95 shadow-[0_-4px_16px_-8px_rgba(0,0,0,0.15)] backdrop-blur sm:-mx-6 dark:border-gray-700 dark:bg-gray-900/95 print:hidden">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <p class="flex min-w-0 items-center gap-1.5 text-sm">
                <template v-if="form.hasErrors">
                    <ExclamationCircleIcon class="h-5 w-5 shrink-0 text-red-500" />
                    <span class="truncate font-medium text-red-600 dark:text-red-400">{{ t('settings.has_errors', 'راجع الحقول المؤشرة') }}</span>
                </template>
                <template v-else-if="dirty ?? form.isDirty">
                    <span class="h-2 w-2 shrink-0 rounded-full bg-amber-500" />
                    <span class="truncate font-medium text-amber-600 dark:text-amber-400">{{ t('settings.unsaved', 'تغييرات غير محفوظة') }}</span>
                </template>
                <template v-else-if="form.recentlySuccessful">
                    <CheckCircleIcon class="h-5 w-5 shrink-0 text-emerald-500" />
                    <span class="truncate text-emerald-600 dark:text-emerald-400">{{ t('settings.saved', 'تم الحفظ') }}</span>
                </template>
                <template v-else>
                    <span class="truncate text-gray-500 dark:text-gray-400">{{ t('settings.no_changes', 'لا توجد تغييرات') }}</span>
                </template>
            </p>
            <div class="flex shrink-0 gap-2">
                <button
                    v-if="dirty ?? form.isDirty"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                    @click="emit('reset')"
                >
                    <ArrowUturnRightIcon class="h-4 w-4" />
                    <span class="hidden sm:inline">{{ t('action.undo', 'تراجع') }}</span>
                </button>
                <button
                    type="button"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-2 rounded-xl bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:brightness-110 disabled:opacity-50"
                    @click="emit('save')"
                >
                    <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" /><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" /></svg>
                    {{ label || t('action.save', 'حفظ') }}
                </button>
            </div>
        </div>
    </div>
</template>
