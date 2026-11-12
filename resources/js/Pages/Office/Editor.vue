<script setup>
// The office editor (ONLYOFFICE Docs): Word, Excel, PowerPoint and PDF, full screen.
// Saving is automatic: the editor sends the file back when everyone closes it, and
// «حفظ الآن» (force save) inside it saves a copy straight away.
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, ExclamationTriangleIcon, EyeIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    file: { type: Object, required: true },
    config: { type: Object, required: true },
    script: { type: String, required: true },
    canEdit: Boolean,
    inApp: Boolean,
});

const { t } = useTrans();
const state = ref('loading'); // loading | ready | failed
let editor = null;

function backHref() {
    return props.file.folder_id ? route('dashboard', { folder: props.file.folder_id }) : route('dashboard');
}

function start() {
    try {
        editor = new window.DocsAPI.DocEditor('fn-office-editor', {
            ...props.config,
            events: {
                onAppReady: () => (state.value = 'ready'),
                onError: () => (state.value = 'failed'),
            },
        });
        // Older editors don't send onAppReady for viewers.
        setTimeout(() => state.value === 'loading' && (state.value = 'ready'), 15000);
    } catch {
        state.value = 'failed';
    }
}

onMounted(() => {
    if (window.DocsAPI) return start();
    const s = document.createElement('script');
    s.src = props.script;
    s.async = true;
    s.onload = start;
    s.onerror = () => (state.value = 'failed');
    document.head.appendChild(s);
});

onBeforeUnmount(() => {
    try {
        editor?.destroyEditor();
    } catch {
        /* already gone */
    }
});
</script>

<template>
    <Head :title="file.name" />
    <div class="flex h-[100dvh] flex-col bg-gray-100 dark:bg-gray-900">
        <header v-if="!inApp" class="flex h-11 shrink-0 items-center gap-2 border-b border-gray-200 bg-white px-2 dark:border-gray-700 dark:bg-gray-800">
            <Link :href="backHref()" class="rounded-lg p-1.5 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" :title="t('action.back', 'رجوع')">
                <ArrowRightIcon class="h-5 w-5 ltr:rotate-180" />
            </Link>
            <p class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 dark:text-gray-100"><bdi>{{ file.name }}</bdi></p>
            <span
                class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium"
                :class="canEdit ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
            >
                <component :is="canEdit ? PencilSquareIcon : EyeIcon" class="h-3.5 w-3.5" />
                {{ canEdit ? t('office.editing', 'تحرير · يُحفظ تلقائياً') : t('office.viewing', 'عرض فقط') }}
            </span>
        </header>

        <div class="relative min-h-0 flex-1">
            <div id="fn-office-editor" class="h-full w-full"></div>

            <div v-if="state === 'loading'" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-gray-100 text-sm text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                <div class="h-9 w-9 animate-spin rounded-full border-4 border-[var(--accent-500)] border-t-transparent"></div>
                {{ t('office.loading', 'جارٍ فتح المحرر…') }}
            </div>
            <div v-else-if="state === 'failed'" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-gray-100 p-6 text-center dark:bg-gray-900">
                <ExclamationTriangleIcon class="h-10 w-10 text-amber-500" />
                <p class="max-w-sm text-sm text-gray-700 dark:text-gray-200">{{ t('office.unavailable', 'المحرر غير متاح الآن. يمكنك تنزيل الملف وفتحه، أو المحاولة بعد قليل.') }}</p>
                <div class="flex gap-2">
                    <a :href="route('files.download', file.id)" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm font-semibold text-white">{{ t('action.download', 'تنزيل') }}</a>
                    <Link v-if="!inApp" :href="backHref()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600 dark:text-gray-200">{{ t('action.back', 'رجوع') }}</Link>
                </div>
            </div>
        </div>
    </div>
</template>
