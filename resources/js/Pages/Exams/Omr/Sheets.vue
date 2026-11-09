<script setup>
import { onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { useTrans } from '@/Composables/useTrans';
import { PrinterIcon, ArrowRightIcon, CameraIcon, ArrowDownTrayIcon, ArrowUpTrayIcon } from '@heroicons/vue/24/outline';
import { PAGE, MARK, MARKS, slot, LETTERS, BUBBLE_R, capacity } from '@/Components/Exams/omrLayout';

// One bubble sheet per student, ready to print: the QR says whose sheet it is.
const props = defineProps({ exam: Object, questions: Array, students: Array, skipped: Number, site: String });
const { t } = useTrans();
const qr = reactive({});
const only = ref('');
onMounted(() => props.students.forEach((s) => QRCode.toDataURL(s.code, { margin: 0, width: 220, errorCorrectionLevel: 'M' }).then((u) => (qr[s.id] = u))));
const printPage = () => window.print();
// Results from Auto Multiple Choice.
function amcImport(event) {
    const file = event.target.files[0];
    event.target.value = '';
    if (file) router.post(route('exams.manage.amc.import', props.exam.id), { file }, { forceFormData: true, preserveScroll: true });
}
const letter = (q, i) => (q.type === 'true_false' ? ['T', 'F'][i] : LETTERS[i]);
const list = () => (only.value ? props.students.filter((s) => String(s.id) === only.value) : props.students);
</script>

<template>
    <Head :title="t('exam.omr', 'ورق الفقاعات') + ' — ' + exam.title" />
    <div class="min-h-screen bg-gray-100 print:bg-white">
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-white px-4 py-2 text-sm print:hidden">
            <Link :href="route('exams.manage.edit', exam.id)" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
            <span class="flex-1 font-medium">{{ t('exam.omr', 'ورق الفقاعات') }} — {{ exam.title }} ({{ students.length }})</span>
            <select v-model="only" class="rounded-lg border-gray-300 py-1 text-sm"><option value="">{{ t('exam.all_students', 'كل الطلاب') }}</option><option v-for="s in students" :key="s.id" :value="String(s.id)">{{ s.name }}</option></select>
            <Link :href="route('exams.manage.omr.scan', exam.id)" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5"><CameraIcon class="h-4 w-4" /> {{ t('exam.omr_scan', 'قراءة الأوراق الممسوحة') }}</Link>
            <button @click="printPage" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-white"><PrinterIcon class="h-4 w-4" /> {{ t('exam.print', 'طباعة') }}</button>
            <span class="flex w-full flex-wrap items-center gap-2 border-t border-gray-100 pt-2">
                <span class="text-xs font-medium text-gray-600">Auto Multiple Choice:</span>
                <a :href="route('exams.manage.amc', exam.id)" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1 text-xs"><ArrowDownTrayIcon class="h-4 w-4" /> {{ t('exam.amc_export', 'مشروع AMC (الأسئلة وقائمة الطلاب)') }}</a>
                <label class="flex cursor-pointer items-center gap-1 rounded-lg border border-gray-300 px-3 py-1 text-xs"><input type="file" accept=".csv,.txt" class="sr-only" @change="amcImport" /><ArrowUpTrayIcon class="h-4 w-4" /> {{ t('exam.amc_import', 'رفع نتائج AMC (CSV)') }}</label>
                <span class="text-xs text-gray-500">{{ t('exam.amc_hint', 'برنامج مجاني لطباعة أوراق الفقاعات وقراءتها؛ صدّر النتائج CSV مع «الخانات المؤشّرة» بالحروف.') }}</span>
                <span v-if="$page.props.flash?.success" class="text-xs text-emerald-700">{{ $page.props.flash.success }}</span>
                <span v-if="$page.props.errors?.file" class="text-xs text-red-600">{{ $page.props.errors.file }}</span>
            </span>
            <p v-if="skipped" class="w-full text-xs text-amber-700">{{ t('exam.omr_skipped', ':n سؤالاً ليس اختياراً من متعدد أو صح/خطأ لا يظهر في الورقة؛ يُصحَّح يدوياً.', { n: skipped }) }}</p>
            <p v-if="questions.length > capacity" class="w-full text-xs text-red-600">{{ t('exam.omr_too_many', 'الورقة تتسع لـ :n سؤالاً فقط.', { n: capacity }) }}</p>
        </div>
        <div v-for="s in list()" :key="s.id" class="omr-page mx-auto my-6 bg-white shadow print:m-0 print:shadow-none" style="width: 210mm; height: 297mm">
            <svg :viewBox="`0 0 ${PAGE.w} ${PAGE.h}`" width="210mm" height="297mm" xmlns="http://www.w3.org/2000/svg" font-family="Arial, sans-serif">
                <rect v-for="(m, i) in MARKS" :key="i" :x="m[0] - MARK / 2" :y="m[1] - MARK / 2" :width="MARK" :height="MARK" fill="#000" />
                <text x="105" y="17" text-anchor="middle" font-size="5" font-weight="bold">{{ exam.title }}</text>
                <text x="105" y="23" text-anchor="middle" font-size="3.2" fill="#444">{{ site }}{{ exam.subject ? ' · ' + exam.subject : '' }}</text>
                <text x="30" y="34" font-size="4">{{ t('exam.gs_name', 'اسم الطالب') }}: {{ s.name }}</text>
                <text x="30" y="41" font-size="4">{{ t('exam.card_number', 'الرقم الجامعي') }}: {{ s.number || '' }}</text>
                <text x="30" y="49" font-size="2.8" fill="#555">{{ t('exam.omr_how', 'املأ الدائرة كاملةً بقلم أسود أو أزرق غامق. لا تكتب قرب المربعات السوداء ولا على الرمز.') }}</text>
                <image v-if="qr[s.id]" :href="qr[s.id]" x="146" y="24" width="30" height="30" />
                <g v-for="(q, k) in questions.slice(0, capacity)" :key="q.id">
                    <text :x="slot(k, q.options).number[0]" :y="slot(k, q.options).number[1] + 1.2" font-size="3.4" font-weight="bold">{{ k + 1 }}</text>
                    <g v-for="(b, i) in slot(k, q.options).bubbles" :key="i">
                        <circle :cx="b[0]" :cy="b[1]" :r="BUBBLE_R" fill="none" stroke="#000" stroke-width="0.3" />
                        <text :x="b[0]" :y="b[1] + 1" text-anchor="middle" font-size="2.6" fill="#666">{{ letter(q, i) }}</text>
                    </g>
                </g>
            </svg>
        </div>
    </div>
</template>

<style>
@media print {
    @page { size: A4; margin: 0; }
    .omr-page { page-break-after: always; break-after: page; }
}
</style>
