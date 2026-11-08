<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ClipboardDocumentListIcon, ClockIcon, CheckCircleIcon, PlayIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';

defineProps({ exams: Array, canManage: Boolean });
const { t } = useTrans();

const fmt = (iso) => (iso ? new Date(iso).toLocaleString(document.documentElement.lang || 'ar', { dateStyle: 'medium', timeStyle: 'short' }) : '');
const stateLabel = (e) => {
    if (e.in_progress) return { text: t('exam.in_progress', 'جارٍ'), cls: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' };
    if (e.window === 'upcoming') return { text: t('exam.upcoming', 'قريباً'), cls: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300' };
    if (e.window === 'closed') return { text: t('exam.closed', 'مغلق'), cls: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' };
    if (e.attempts_used >= e.max_attempts) return { text: t('exam.done', 'منتهٍ'), cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' };
    return { text: t('exam.open', 'متاح'), cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' };
};
</script>

<template>
    <Head :title="t('nav.exams', 'امتحاناتي')" />
    <AppLayout>
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold"><ClipboardDocumentListIcon class="h-5 w-5 text-gray-500" /> {{ t('nav.exams', 'امتحاناتي') }}</h2>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <div v-if="!exams.length" class="rounded-xl border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500 dark:border-gray-600">
                {{ t('exam.none_available', 'لا توجد امتحانات منشورة حالياً.') }}
                <Link v-if="canManage" :href="route('exams.manage.index')" class="mt-3 flex items-center justify-center gap-1 text-[var(--accent-600)] hover:underline">
                    <PencilSquareIcon class="h-4 w-4" /> {{ t('exam.go_manage', 'أنشئ امتحاناً من «إدارة الامتحانات»') }}
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link v-for="e in exams" :key="e.id" :href="route('exams.show', e.id)"
                    class="flex flex-col rounded-xl border border-gray-200 bg-white p-4 transition hover:border-[var(--accent-500)] dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100" :dir="e.dir">{{ e.title }}</h3>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="stateLabel(e).cls">{{ stateLabel(e).text }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ [e.subject, e.stage, e.department].filter(Boolean).join(' · ') }}</p>
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                        <span>{{ e.questions }} {{ t('exam.questions_n', 'سؤال') }}</span>
                        <span v-if="e.duration" class="flex items-center gap-1"><ClockIcon class="h-4 w-4" /> {{ e.duration }} {{ t('exam.minutes', 'دقيقة') }}</span>
                        <span>{{ t('exam.attempts', 'المحاولات') }}: {{ e.attempts_used }} / {{ e.max_attempts }}</span>
                    </div>
                    <p v-if="e.closes_at" class="mt-1 text-xs text-gray-500">{{ t('exam.closes', 'يغلق') }}: {{ fmt(e.closes_at) }}</p>
                    <p v-else-if="e.window === 'upcoming' && e.opens_at" class="mt-1 text-xs text-gray-500">{{ t('exam.opens', 'يفتح') }}: {{ fmt(e.opens_at) }}</p>
                    <div class="mt-auto flex items-center justify-between pt-4">
                        <span v-if="e.score !== null" class="flex items-center gap-1 text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                            <CheckCircleIcon class="h-5 w-5" /> {{ e.score }} / {{ e.max_score }}
                        </span>
                        <span v-else />
                        <span v-if="e.in_progress || (e.window === 'open' && e.attempts_used < e.max_attempts)" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm text-white">
                            <PlayIcon class="h-4 w-4" /> {{ e.in_progress ? t('exam.resume', 'متابعة') : t('exam.start', 'ابدأ') }}
                        </span>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
