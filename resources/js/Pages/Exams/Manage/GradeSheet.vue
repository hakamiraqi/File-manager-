<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { PrinterIcon, ArrowRightIcon } from '@heroicons/vue/24/outline';

// The official grade sheet, ready to print and sign. The committee's names are typed here
// before printing (kept on this device for the next sheet).
const props = defineProps({ exam: Object, rows: Array, site: String, counts: Object });
const { t } = useTrans();
const KEY = 'gradesheet-committee';
const committee = reactive((() => { try { return JSON.parse(localStorage.getItem(KEY)) || null; } catch { return null; } })()
    ?? { university: '', college: '', head: '', member1: '', member2: '' });
const keep = () => { try { localStorage.setItem(KEY, JSON.stringify(committee)); } catch {} };
const STATUS = { passed: ['ناجح', 'exam.gs_passed'], failed: ['راسب', 'exam.gs_failed'], absent: ['غائب', 'exam.gs_absent'], pending: ['ينتظر التصحيح', 'exam.gs_pending'],
    not_submitted: ['لم يسلّم', 'exam.gs_not_submitted'], done: ['—', 'exam.gs_done'] };
const date = props.exam.date ? new Date(props.exam.date).toLocaleDateString(document.documentElement.lang || 'ar', { dateStyle: 'long' }) : '';
const printPage = () => window.print();
const field = 'w-full border-0 border-b border-dotted border-gray-400 bg-transparent p-0 text-center focus:ring-0 print:border-transparent';
</script>

<template>
    <Head :title="t('exam.gradesheet', 'كشف الدرجات') + ' — ' + exam.title" />
    <div class="min-h-screen bg-gray-100 text-black print:bg-white">
        <div class="flex items-center gap-2 border-b border-gray-200 bg-white px-4 py-2 text-sm print:hidden">
            <Link :href="route('exams.manage.results', exam.id)" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
            <span class="flex-1 font-medium">{{ t('exam.gradesheet', 'كشف الدرجات') }} — {{ exam.title }}</span>
            <span class="text-xs text-gray-500">{{ t('exam.gradesheet_hint', 'اكتب أسماء الجهة واللجنة قبل الطباعة.') }}</span>
            <button @click="printPage" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-white"><PrinterIcon class="h-4 w-4" /> {{ t('exam.print', 'طباعة') }}</button>
        </div>

        <div class="mx-auto my-6 max-w-[210mm] bg-white p-[12mm] text-[11pt] print:m-0 print:max-w-none print:p-0">
            <header class="grid grid-cols-3 items-start gap-4 text-center">
                <div class="space-y-1 text-start">
                    <input v-model="committee.university" @change="keep" :placeholder="t('exam.gs_university', 'الجامعة')" :class="field" class="!text-start font-semibold" />
                    <input v-model="committee.college" @change="keep" :placeholder="t('exam.gs_college', 'الكلية')" :class="field" class="!text-start" />
                    <p>{{ exam.department }}</p>
                </div>
                <div>
                    <p class="text-lg font-bold">{{ t('exam.gradesheet', 'كشف الدرجات') }}</p>
                    <p class="mt-1 font-semibold">{{ exam.title }}</p>
                </div>
                <div class="space-y-1 text-end text-sm">
                    <p v-if="exam.subject">{{ t('exam.subject', 'المادة') }}: {{ exam.subject }}</p>
                    <p v-if="exam.stage">{{ t('exam.stage', 'المرحلة') }}: {{ exam.stage }}</p>
                    <p v-if="date">{{ t('exam.gs_date', 'التاريخ') }}: {{ date }}</p>
                    <p>{{ t('exam.gs_max', 'الدرجة القصوى') }}: {{ exam.max }}<template v-if="exam.pass_mark !== null"> · {{ t('exam.gs_pass', 'النجاح') }} {{ exam.pass_mark }}%</template></p>
                </div>
            </header>

            <table class="mt-6 w-full border-collapse text-center">
                <thead>
                    <tr class="bg-gray-100 print:bg-gray-100">
                        <th class="w-10 border border-black px-1 py-1.5">#</th>
                        <th class="border border-black px-2 py-1.5 text-start">{{ t('exam.gs_name', 'اسم الطالب') }}</th>
                        <th class="w-28 border border-black px-2 py-1.5">{{ t('exam.card_number', 'الرقم الجامعي') }}</th>
                        <th class="w-20 border border-black px-2 py-1.5">{{ t('exam.gs_score', 'الدرجة') }}</th>
                        <th class="w-20 border border-black px-2 py-1.5">%</th>
                        <th class="w-28 border border-black px-2 py-1.5">{{ t('exam.gs_result', 'النتيجة') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="i" class="break-inside-avoid">
                        <td class="border border-black px-1 py-1">{{ i + 1 }}</td>
                        <td class="border border-black px-2 py-1 text-start">{{ r.name }}</td>
                        <td class="border border-black px-2 py-1 font-mono" dir="ltr">{{ r.number || '' }}</td>
                        <td class="border border-black px-2 py-1 font-semibold">{{ r.score ?? '' }}</td>
                        <td class="border border-black px-2 py-1">{{ r.percent ?? '' }}</td>
                        <td class="border border-black px-2 py-1">{{ t(STATUS[r.status][1], STATUS[r.status][0]) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-3 text-sm">
                {{ t('exam.gs_total', 'العدد الكلي') }}: {{ counts.total }}
                <template v-if="counts.passed"> · {{ t('exam.gs_passed', 'ناجح') }}: {{ counts.passed }}</template>
                <template v-if="counts.failed"> · {{ t('exam.gs_failed', 'راسب') }}: {{ counts.failed }}</template>
                <template v-if="counts.absent"> · {{ t('exam.gs_absent', 'غائب') }}: {{ counts.absent }}</template>
                <template v-if="counts.pending"> · {{ t('exam.gs_pending', 'ينتظر التصحيح') }}: {{ counts.pending }}</template>
            </p>

            <footer class="mt-12 grid grid-cols-4 items-end gap-6 text-center text-sm break-inside-avoid">
                <div v-for="k in ['member2', 'member1', 'head']" :key="k" class="space-y-6">
                    <p class="font-semibold">{{ k === 'head' ? t('exam.gs_head', 'رئيس اللجنة الامتحانية') : t('exam.gs_member', 'عضو اللجنة') }}</p>
                    <div class="border-b border-black" />
                    <input v-model="committee[k]" @change="keep" :placeholder="t('exam.gs_name_ph', 'الاسم')" :class="field" />
                </div>
                <div class="flex h-28 items-center justify-center rounded-full border-2 border-dashed border-gray-400 text-xs text-gray-400">{{ t('exam.gs_stamp', 'الختم') }}</div>
            </footer>
            <p class="mt-8 text-center text-[8pt] text-gray-500">{{ site }}</p>
        </div>
    </div>
</template>
