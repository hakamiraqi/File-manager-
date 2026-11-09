<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, ArrowDownTrayIcon, PrinterIcon } from '@heroicons/vue/24/outline';

// A group's marks across its exams: the mark that counts in each, and the average percentage.
defineProps({ group: Object, columns: Array, rows: Array });
const { t } = useTrans();
const printPage = () => window.print();
</script>

<template>
    <Head :title="t('exam.gradebook', 'كشف الدرجات') + ' — ' + group.name" />
    <div class="min-h-screen bg-gray-50 p-4 text-gray-900 print:bg-white dark:bg-gray-900 dark:text-gray-100 print:text-black">
        <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
            <Link :href="route('exams.manage.groups', { group: group.id })" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
            <h1 class="flex-1 text-lg font-semibold">{{ t('exam.gradebook', 'كشف الدرجات') }} — {{ group.name }}</h1>
            <a :href="route('exams.manage.groups.gradebook', { group: group.id, csv: 1 })" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600"><ArrowDownTrayIcon class="h-4 w-4" /> Excel</a>
            <button @click="printPage" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm text-white"><PrinterIcon class="h-4 w-4" /> {{ t('exam.print', 'طباعة') }}</button>
        </div>
        <h1 class="mb-3 hidden text-center text-lg font-bold print:block">{{ t('exam.gradebook', 'كشف الدرجات') }} — {{ group.name }} <span v-if="group.stage">· {{ group.stage }}</span></h1>
        <p v-if="!columns.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">{{ t('exam.gradebook_empty', 'لم يُعطَ أي امتحان لهذه المجموعة بعد.') }}</p>
        <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white print:border-black dark:border-gray-700 dark:bg-gray-800 print:bg-white">
            <table class="w-full border-collapse text-sm">
                <thead class="bg-gray-50 text-xs dark:bg-gray-700/50 print:bg-gray-100">
                    <tr>
                        <th class="border-b px-3 py-2 text-start print:border print:border-black">#</th>
                        <th class="border-b px-3 py-2 text-start print:border print:border-black">{{ t('exam.student', 'الطالب') }}</th>
                        <th v-for="c in columns" :key="c.id" class="border-b px-2 py-2 print:border print:border-black" dir="auto">{{ c.title }} <span class="block font-normal text-gray-400">/ {{ c.max }}</span></th>
                        <th class="border-b px-3 py-2 print:border print:border-black">{{ t('exam.average', 'المعدّل') }} %</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="r.email" class="border-t border-gray-100 dark:border-gray-700">
                        <td class="px-3 py-1.5 text-gray-400 print:border print:border-black">{{ i + 1 }}</td>
                        <td class="px-3 py-1.5 print:border print:border-black"><Link v-if="r.id" :href="route('exams.manage.students.report', r.id)" class="hover:underline print:no-underline">{{ r.name }}</Link><template v-else>{{ r.name }}</template></td>
                        <td v-for="(m, k) in r.marks" :key="k" class="px-2 py-1.5 text-center print:border print:border-black"
                            :class="m.percent !== null && columns[k].pass_mark !== null && m.percent < columns[k].pass_mark ? 'text-red-600' : ''">{{ m.score ?? '—' }}</td>
                        <td class="px-3 py-1.5 text-center font-semibold print:border print:border-black">{{ r.average !== null ? Math.round(r.average * 10) / 10 : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
