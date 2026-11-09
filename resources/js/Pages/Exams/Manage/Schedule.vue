<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { CalendarDaysIcon, ExclamationTriangleIcon, ChevronLeftIcon, ChevronRightIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';

// The department's exam timetable, by day, with clashes of hall or group.
const props = defineProps({ from: String, entries: Array });
const { t } = useTrans();
const lang = document.documentElement.lang || 'ar';
const dayKey = (iso) => new Date(iso).toLocaleDateString('en-CA');
const days = computed(() => {
    const out = {};
    props.entries.forEach((e) => (out[dayKey(e.start)] ??= []).push(e));
    return Object.entries(out);
});
const dayTitle = (key) => new Date(key + 'T12:00').toLocaleDateString(lang, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const time = (iso) => new Date(iso).toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit' });
const clashCount = computed(() => props.entries.filter((e) => e.clashes.length).length);
function shift(days) {
    const d = new Date(props.from + 'T12:00');
    d.setDate(d.getDate() + days);
    router.get(route('exams.manage.schedule'), { from: d.toLocaleDateString('en-CA') }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('exam.schedule', 'جدول الامتحانات')" />
    <AppLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="flex flex-1 items-center gap-2 text-lg font-semibold"><CalendarDaysIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.schedule', 'جدول الامتحانات') }}</h2>
                <button @click="shift(-30)" class="rounded-lg border border-gray-300 p-1.5 dark:border-gray-600" :aria-label="t('exam.earlier', 'أبكر')"><ChevronRightIcon class="h-4 w-4 ltr:rotate-180" /></button>
                <span class="text-sm text-gray-500">{{ t('exam.from_date', 'من :d', { d: new Date(from + 'T12:00').toLocaleDateString(lang) }) }}</span>
                <button @click="shift(30)" class="rounded-lg border border-gray-300 p-1.5 dark:border-gray-600" :aria-label="t('exam.later', 'أبعد')"><ChevronLeftIcon class="h-4 w-4 ltr:rotate-180" /></button>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <p v-if="clashCount" class="flex items-center gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300"><ExclamationTriangleIcon class="h-5 w-5" /> {{ t('exam.clashes_n', ':n امتحاناً في تعارض: القاعة نفسها أو المجموعة نفسها في الوقت نفسه.', { n: clashCount }) }}</p>
            <p v-if="!entries.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-600">{{ t('exam.schedule_empty', 'لا امتحانات بمواعيد في هذه الفترة. حدّد «يفتح» أو الجلسات في إعدادات الامتحان.') }}</p>
            <section v-for="[key, list] in days" :key="key" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <p class="border-b border-gray-100 px-4 py-2 text-sm font-semibold dark:border-gray-700">{{ dayTitle(key) }}</p>
                <div v-for="e in list" :key="e.id" class="flex flex-wrap items-start gap-3 border-b border-gray-50 px-4 py-2.5 text-sm last:border-0 dark:border-gray-700/50" :class="e.clashes.length ? 'bg-red-50/60 dark:bg-red-900/10' : ''">
                    <span class="w-28 shrink-0 font-mono text-xs" dir="ltr">{{ time(e.start) }} – {{ time(e.end) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ e.title }}<span v-if="e.session" class="text-gray-500"> · {{ e.session }}</span>
                            <span v-if="!e.published" class="ms-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700">{{ t('exam.draft', 'مسودة') }}</span></p>
                        <p class="text-xs text-gray-500">{{ [e.subject, e.hall ? t('exam.hall_short', 'القاعة') + ' ' + e.hall : null, e.groups.join('، '), e.author].filter(Boolean).join(' · ') }}</p>
                        <p v-for="(c, k) in e.clashes" :key="k" class="mt-0.5 flex items-center gap-1 text-xs font-medium text-red-700 dark:text-red-300"><ExclamationTriangleIcon class="h-3.5 w-3.5" /> {{ t('exam.clash_with', 'يتعارض مع «:x» (:why)', { x: c.with, why: c.why }) }}</p>
                    </div>
                    <Link v-if="e.can_edit" :href="route('exams.manage.edit', e.exam_id)" class="rounded p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700"><PencilSquareIcon class="h-4 w-4" /></Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
