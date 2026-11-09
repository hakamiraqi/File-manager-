<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline';

defineProps({ stations: Array });
const { t } = useTrans();
</script>

<template>
    <Head :title="t('exam.osce_assess', 'التقييم في المحطات')" />
    <AppLayout>
        <template #header><h2 class="flex items-center gap-2 text-lg font-semibold"><ClipboardDocumentCheckIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.osce_assess', 'التقييم في المحطات') }}</h2></template>
        <div class="space-y-2 p-4 sm:p-6">
            <p v-if="!stations.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-600">{{ t('exam.osce_none', 'لا محطات مسندة إليك.') }}</p>
            <Link v-for="s in stations" :key="s.id" :href="route('exams.monitor.osce.station', s.id)" class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:hover:bg-gray-700">
                <div class="min-w-0 flex-1"><p class="font-medium">{{ s.name }}</p><p class="text-xs text-gray-500">{{ s.exam }}</p></div>
                <span class="text-xs text-gray-500">{{ t('exam.osce_marked', 'قُيّم :n', { n: s.marked }) }}</span>
            </Link>
        </div>
    </AppLayout>
</template>
