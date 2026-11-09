<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { EyeIcon, SignalIcon } from '@heroicons/vue/24/outline';
import ServerHealth from '@/Components/Exams/ServerHealth.vue';

defineProps({ exams: Array });
const { t } = useTrans();
const fmt = (iso) => (iso ? new Date(iso).toLocaleString(document.documentElement.lang || 'ar', { dateStyle: 'short', timeStyle: 'short' }) : '—');
</script>

<template>
    <Head :title="t('exam.monitor', 'المراقبة')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-lg font-semibold"><EyeIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.monitor', 'المراقبة') }}</h2>
                <Link v-if="$page.props.auth?.user?.role === 'admin'" :href="route('exams.monitor.mirror')" class="ms-auto rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">{{ t('exam.mirror', 'السيرفر الاحتياطي') }}</Link>
                <Link :href="route('exams.monitor.osce')" class="ms-auto rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">{{ t('exam.osce_assess', 'التقييم في المحطات') }}</Link>
                <Link :href="route('exams.monitor.computers')" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">{{ t('exam.computers', 'حواسيب القاعات') }}</Link>
            </div>
        </template>
        <div class="space-y-2 p-4 sm:p-6">
            <ServerHealth />
            <p v-if="!exams.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-600">{{ t('exam.monitor_empty', 'لا امتحانات جارية أو قريبة الآن.') }}</p>
            <Link v-for="e in exams" :key="e.id" :href="route('exams.monitor.show', e.id)"
                class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:hover:bg-gray-700">
                <div class="min-w-0 flex-1">
                    <p class="font-medium" dir="auto">{{ e.title }}</p>
                    <p class="text-xs text-gray-500">{{ [e.subject, e.groups.join('، ')].filter(Boolean).join(' · ') || t('exam.given_to_all_short', 'كل الطلاب') }} · {{ fmt(e.opens_at) }} → {{ fmt(e.closes_at) }}</p>
                </div>
                <span v-if="e.running" class="flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                    <SignalIcon class="h-3.5 w-3.5" /> {{ e.running }} {{ t('exam.running', 'يؤدّون الآن') }}
                </span>
            </Link>
        </div>
    </AppLayout>
</template>
