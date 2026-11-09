<script setup>
// «محضر الامتحان» (2026-11-09, by request): the committee's minutes on one A4 page (or more),
// printed or saved as PDF from the print window: the exam, the numbers, the absent students,
// what happened during it, and the signatures.
import { Head, Link } from '@inertiajs/vue3';
import { useTrans } from '@/Composables/useTrans';
import { PrinterIcon, ArrowRightIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ exam: Object, site: String, printed_at: String, first_start: String, last_submit: String, counts: Object, absent: Array, incidents: Array, announcements: Array });
const { t } = useTrans();
const lang = document.documentElement.lang || 'ar';
const day = (iso) => (iso ? new Date(iso).toLocaleDateString(lang, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) : '—');
const hm = (iso) => (iso ? new Date(iso).toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit' }) : '—');
const KINDS = {
    hidden: () => t('hallmap.sk.hidden', 'أخفى صفحة الامتحان'), blur: () => t('hallmap.sk.blur', 'انتقل لنافذة أخرى'), paste: () => t('hallmap.sk.paste', 'لصق نصاً'),
    copy: () => t('hallmap.sk.copy', 'نسخ نصاً'), fullscreen_exit: () => t('hallmap.sk.fullscreen_exit', 'خرج من ملء الشاشة'), ip_changed: () => t('hallmap.sk.ip_changed', 'تغيّر عنوان الجهاز'),
};
const kinds = (k) => Object.entries(k).map(([type, n]) => `${KINDS[type]?.() ?? type} (${n})`).join('، ');
const printPage = () => window.print();
const cell = 'border border-black px-2 py-1';
</script>

<template>
    <Head :title="t('report.title', 'محضر الامتحان') + ' — ' + exam.title" />
    <div class="min-h-screen bg-gray-100 print:bg-white">
        <div class="sticky top-0 z-10 flex items-center gap-2 border-b border-gray-200 bg-white px-4 py-2 text-sm print:hidden">
            <Link :href="route('exams.monitor.show', exam.id)" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
            <span class="flex-1 font-medium">{{ t('report.title', 'محضر الامتحان') }}</span>
            <span class="hidden text-xs text-gray-500 sm:inline">{{ t('report.pdf_hint', 'للحفظ PDF: اختر «حفظ كـ PDF» في نافذة الطباعة.') }}</span>
            <button @click="printPage" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-white"><PrinterIcon class="h-4 w-4" /> {{ t('report.print', 'طباعة / PDF') }}</button>
        </div>
        <article class="mx-auto my-4 max-w-[210mm] bg-white p-[12mm] text-[10.5pt] leading-relaxed text-black shadow sm:my-6 print:m-0 print:max-w-none print:p-0 print:shadow-none">
            <header class="mb-4 border-b-2 border-black pb-3 text-center">
                <p class="text-sm font-semibold">{{ site }}</p>
                <h1 class="mt-1 text-xl font-bold">{{ t('report.title', 'محضر الامتحان') }}</h1>
                <p class="text-base font-semibold">{{ exam.title }}</p>
            </header>

            <table class="mb-4 w-full border-collapse text-sm">
                <tbody>
                    <tr><th :class="cell" class="w-1/4 bg-gray-100 text-start">{{ t('report.subject', 'المادة') }}</th><td :class="cell">{{ exam.subject || '—' }}</td><th :class="cell" class="w-1/4 bg-gray-100 text-start">{{ t('report.stage', 'المرحلة / القسم') }}</th><td :class="cell">{{ [exam.stage, exam.department].filter(Boolean).join(' · ') || '—' }}</td></tr>
                    <tr><th :class="cell" class="bg-gray-100 text-start">{{ t('report.date', 'التاريخ') }}</th><td :class="cell">{{ day(first_start || exam.opens_at || printed_at) }}</td><th :class="cell" class="bg-gray-100 text-start">{{ t('report.hall', 'القاعة') }}</th><td :class="cell">{{ exam.hall || '—' }}</td></tr>
                    <tr><th :class="cell" class="bg-gray-100 text-start">{{ t('report.groups', 'المجموعات') }}</th><td :class="cell">{{ exam.groups.join('، ') || '—' }}</td><th :class="cell" class="bg-gray-100 text-start">{{ t('report.duration', 'المدة') }}</th><td :class="cell">{{ exam.duration ? t('today.minutes', ':n دقيقة', { n: exam.duration }) : '—' }}</td></tr>
                    <tr><th :class="cell" class="bg-gray-100 text-start">{{ t('report.first_start', 'بدء أول طالب') }}</th><td :class="cell" dir="ltr" class="text-end">{{ hm(first_start) }}</td><th :class="cell" class="bg-gray-100 text-start">{{ t('report.last_submit', 'آخر تسليم') }}</th><td :class="cell" dir="ltr" class="text-end">{{ hm(last_submit) }}</td></tr>
                    <tr><th :class="cell" class="bg-gray-100 text-start">{{ t('report.teacher', 'أستاذ المادة') }}</th><td :class="cell" colspan="3">{{ exam.owner || '—' }}</td></tr>
                </tbody>
            </table>

            <h2 class="mb-1 font-bold">{{ t('report.numbers', 'الأعداد') }}</h2>
            <table class="mb-4 w-full border-collapse text-center text-sm">
                <thead><tr class="bg-gray-100">
                    <th :class="cell">{{ t('report.expected', 'المشمولون') }}</th><th :class="cell">{{ t('report.present', 'الحاضرون') }}</th><th :class="cell">{{ t('report.absent', 'الغائبون') }}</th>
                    <th :class="cell">{{ t('report.submitted', 'سلّموا') }}</th><th :class="cell">{{ t('report.not_submitted', 'لم يسلّموا') }}</th><th :class="cell">{{ t('report.incidents', 'حالات مسجّلة') }}</th>
                </tr></thead>
                <tbody><tr class="text-base font-bold">
                    <td :class="cell">{{ counts.expected }}</td><td :class="cell">{{ counts.present }}</td><td :class="cell">{{ counts.absent }}</td>
                    <td :class="cell">{{ counts.submitted }}</td><td :class="cell">{{ counts.not_submitted }}</td><td :class="cell">{{ counts.incidents }}</td>
                </tr></tbody>
            </table>

            <h2 class="mb-1 font-bold">{{ t('report.absent_list', 'الغائبون') }} ({{ absent.length }})</h2>
            <p v-if="!absent.length" class="mb-4 text-sm">{{ t('report.none', 'لا يوجد.') }}</p>
            <table v-else class="mb-4 w-full border-collapse text-sm">
                <thead><tr class="bg-gray-100"><th :class="cell" class="w-10">#</th><th :class="cell" class="text-start">{{ t('exam.student_name', 'اسم الطالب') }}</th><th :class="cell">{{ t('report.number', 'الرقم الجامعي') }}</th></tr></thead>
                <tbody><tr v-for="(a, i) in absent" :key="i" class="break-inside-avoid"><td :class="cell" class="text-center">{{ i + 1 }}</td><td :class="cell">{{ a.name }}</td><td :class="cell" class="text-center" dir="ltr">{{ a.number || '' }}</td></tr></tbody>
            </table>

            <h2 class="mb-1 font-bold">{{ t('report.incidents_list', 'الحالات المسجّلة أثناء الامتحان') }} ({{ incidents.length }})</h2>
            <p class="mb-1 text-xs">{{ t('report.incidents_hint', 'ما أبلغ عنه المتصفح تلقائياً (مغادرة الصفحة، النسخ، اللصق…) والمحاولات التي قُفلت. للّجنة أن تقرّر فيها.') }}</p>
            <p v-if="!incidents.length" class="mb-4 text-sm">{{ t('report.none', 'لا يوجد.') }}</p>
            <table v-else class="mb-4 w-full border-collapse text-sm">
                <thead><tr class="bg-gray-100">
                    <th :class="cell" class="w-10">#</th><th :class="cell" class="text-start">{{ t('exam.student_name', 'اسم الطالب') }}</th><th :class="cell">{{ t('report.computer', 'الحاسبة') }}</th>
                    <th :class="cell" class="text-start">{{ t('report.what', 'ما سُجّل') }}</th><th :class="cell">{{ t('report.time', 'الوقت') }}</th><th :class="cell" class="w-32">{{ t('report.decision', 'قرار اللجنة') }}</th>
                </tr></thead>
                <tbody><tr v-for="(x, i) in incidents" :key="i" class="break-inside-avoid">
                    <td :class="cell" class="text-center">{{ i + 1 }}</td>
                    <td :class="cell">{{ x.name }}<span v-if="x.number" class="block text-xs" dir="ltr">{{ x.number }}</span></td>
                    <td :class="cell" class="text-center">{{ x.computer || '—' }}</td>
                    <td :class="cell">{{ kinds(x.kinds) }}<span v-if="x.locked" class="block font-semibold">{{ t('report.locked', 'قُفلت المحاولة') }}<template v-if="x.locked.reason"> — {{ x.locked.reason }}</template></span></td>
                    <td :class="cell" class="text-center" dir="ltr">{{ hm(x.first || x.locked?.at) }}</td>
                    <td :class="cell" />
                </tr></tbody>
            </table>

            <template v-if="announcements.length">
                <h2 class="mb-1 font-bold">{{ t('report.announcements', 'التبليغات أثناء الامتحان') }}</h2>
                <ul class="mb-4 list-disc ps-6 text-sm"><li v-for="(a, i) in announcements" :key="i"><span dir="ltr">{{ hm(a.created_at) }}</span> — {{ a.body }}</li></ul>
            </template>

            <h2 class="mb-1 font-bold">{{ t('report.notes', 'ملاحظات اللجنة') }}</h2>
            <div class="mb-6 h-24 border border-black" />

            <div class="grid grid-cols-3 gap-6 text-center text-sm break-inside-avoid">
                <div v-for="who in [t('report.head', 'رئيس اللجنة الامتحانية'), t('report.invigilator1', 'المراقب الأول'), t('report.invigilator2', 'المراقب الثاني')]" :key="who">
                    <p class="font-semibold">{{ who }}</p>
                    <p class="mt-10 border-t border-dotted border-black pt-1 text-xs">{{ t('report.name_sign', 'الاسم والتوقيع') }}</p>
                </div>
            </div>
            <p class="mt-6 text-center text-[9pt] text-gray-600">{{ t('report.printed', 'طُبع :t', { t: new Date(printed_at).toLocaleString(lang) }) }}</p>
        </article>
    </div>
</template>
