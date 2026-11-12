<script setup>
// The PDF reader, full screen (2026-11-12). Word/Excel/PowerPoint open here as PDF.
import { Head, Link } from '@inertiajs/vue3';
import PdfViewer from '@/Components/PdfViewer.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    file: { type: Object, required: true },
    src: { type: String, required: true },
    state: { type: Object, default: null },
    signature: { type: Array, default: null },
    canDownload: Boolean,
    inApp: Boolean,
});
const { t } = useTrans();
const back = () => (props.file.folder_id ? route('dashboard', { folder: props.file.folder_id }) : route('dashboard'));
const OFFICE = ['docx', 'odt', 'rtf', 'txt', 'xlsx', 'ods', 'csv', 'pptx', 'odp', 'pdf'];
</script>

<template>
    <Head :title="file.name" />
    <div class="flex h-[100dvh] flex-col bg-gray-100 dark:bg-gray-900">
        <header v-if="!inApp" class="flex shrink-0 items-center gap-2 border-b border-gray-200 bg-white px-3 py-1.5 dark:border-gray-700 dark:bg-gray-800">
            <Link :href="back()" class="rounded-lg p-1.5 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" :title="t('common.back', 'رجوع')">
                <ArrowRightIcon class="h-5 w-5 ltr:rotate-180" />
            </Link>
            <h1 class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-900 dark:text-gray-100"><bdi>{{ file.name }}</bdi></h1>
            <Link
                v-if="$page.props.appSettings?.officeEditor && OFFICE.includes((file.extension || '').toLowerCase())"
                :href="route('office.edit', file.id)"
                class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                <PencilSquareIcon class="h-4 w-4" />{{ t('office.open', 'فتح في المحرر') }}
            </Link>
        </header>
        <PdfViewer
            class="min-h-0 flex-1"
            :src="src"
            :file-id="file.id"
            :file-name="file.name"
            :embedded="false"
            :initial-state="state"
            :signature="signature"
            :can-download="canDownload"
            :download-url="canDownload ? route('files.download', file.id) : null"
        />
    </div>
</template>
