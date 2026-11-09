<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { UserIcon, PrinterIcon } from '@heroicons/vue/24/outline';

// One student across exams, for academic advising: marks, and strengths and weaknesses by tag.
defineProps({ student: Object, exams: Array, by_tag: Array, average: Number });
const { t } = useTrans();
const fmt = (iso) => (iso ? new Date(iso).toLocaleDateString(document.documentElement.lang || 'ar') : '');
const bar = (p) => (p === null ? 'bg-gray-300' : p >= 75 ? 'bg-emerald-500' : p >= 50 ? 'bg-amber-500' : 'bg-red-500');
const printPage = () => window.print();
</script>

<template>
    <Head :title="t('exam.student_report', 'تقرير الطالب') + ' — ' + student.name" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <h2 class="flex-1 text-lg font-semibold">{{ t('exam.student_report', 'تقرير الطالب') }}</h2>
                <button @click="printPage" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm print:hidden dark:border-gray-600"><PrinterIcon class="h-4 w-4" /> {{ t('exam.print', 'طباعة') }}</button>
            </div>
        </template>
        <div class="mx-auto max-w-4xl space-y-4 p-4 sm:p-6">
            <section class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <img v-if="student.photo" :src="student.photo" alt="" class="h-20 w-15 rounded object-cover" />
                <div v-else class="flex h-20 w-16 items-center justify-center rounded bg-gray-100 text-gray-400 dark:bg-gray-700"><UserIcon class="h-8 w-8" /></div>
                <div class="min-w-0 flex-1">
                    <p class="text-lg font-semibold">{{ student.name }}</p>
                    <p class="text-sm text-gray-500" dir="ltr">{{ [student.number, student.email].filter(Boolean).join(' · ') }}</p>
                </div>
                <div class="text-center"><p class="text-xs text-gray-500">{{ t('exam.average', 'المعدّل') }}</p><p class="text-2xl font-bold">{{ average ?? '—' }}<span v-if="average !== null" class="text-sm">%</span></p></div>
            </section>

            <section class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-700/50"><tr><th class="px-4 py-2 text-start">{{ t('exam.exam', 'الامتحان') }}</th><th class="px-2 py-2">{{ t('exam.gs_date', 'التاريخ') }}</th><th class="px-2 py-2">{{ t('exam.gs_score', 'الدرجة') }}</th><th class="w-40 px-4 py-2">%</th></tr></thead>
                    <tbody>
                        <tr v-for="e in exams" :key="e.id" class="border-t border-gray-100 dark:border-gray-700">
                            <td class="px-4 py-2"><p class="font-medium">{{ e.title }}</p><p v-if="e.subject" class="text-xs text-gray-500">{{ e.subject }}</p></td>
                            <td class="px-2 py-2 text-center text-xs">{{ fmt(e.date) }}</td>
                            <td class="px-2 py-2 text-center">{{ e.score ?? '—' }} / {{ e.max }}</td>
                            <td class="px-4 py-2"><div class="flex items-center gap-2"><div class="h-2 flex-1 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-2 rounded-full" :class="bar(e.percent)" :style="{ width: (e.percent ?? 0) + '%' }" /></div><span class="w-10 text-end text-xs">{{ e.percent ?? '—' }}</span></div></td>
                        </tr>
                        <tr v-if="!exams.length"><td colspan="4" class="p-6 text-center text-gray-500">{{ t('exam.report_empty', 'لا امتحانات مسلّمة لهذا الطالب.') }}</td></tr>
                    </tbody>
                </table>
            </section>

            <section v-if="by_tag.length" class="space-y-2 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <p class="font-semibold">{{ t('exam.by_tag', 'حسب الوسم (الفصل أو الهدف)') }}</p>
                <p class="text-xs text-gray-500">{{ t('exam.by_tag_hint', 'الأضعف أولاً: ما حصّله الطالب من درجة الأسئلة الموسومة بكل وسم.') }}</p>
                <div v-for="r in by_tag" :key="r.tag" class="flex items-center gap-3 text-sm">
                    <span class="w-40 truncate">{{ r.tag }}</span>
                    <div class="h-2.5 flex-1 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-2.5 rounded-full" :class="bar(r.percent)" :style="{ width: (r.percent ?? 0) + '%' }" /></div>
                    <span class="w-20 text-end text-xs text-gray-500">{{ r.percent }}% · {{ r.n }}</span>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
