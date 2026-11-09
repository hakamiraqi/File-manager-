<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { useTrans } from '@/Composables/useTrans';
import { PrinterIcon, ArrowRightIcon, CameraIcon, ArrowDownTrayIcon, ArrowUpTrayIcon, ChevronDownIcon, QuestionMarkCircleIcon, UsersIcon, ExclamationTriangleIcon, ComputerDesktopIcon, DocumentTextIcon, CheckCircleIcon } from '@heroicons/vue/24/outline';
import AmcServerPanel from '@/Components/Exams/AmcServerPanel.vue';
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

// Redesigned 2026-11-09 (by request): the page fits a phone. The sheets are shown smaller on the
// screen to fit its width; printed, they are exactly A4 as before (nothing on the sheet changed).
const A4 = 793.7; // 210 mm in CSS pixels
const area = ref(null);
const scale = ref(1);
let ro = null;
onMounted(() => {
    const fit = () => { const w = area.value?.clientWidth ?? A4; scale.value = Math.min(1, (w - 2) / A4); };
    fit();
    ro = new ResizeObserver(fit);
    if (area.value) ro.observe(area.value);
});
onBeforeUnmount(() => ro?.disconnect());
const shownSheets = computed(() => list());
const amcOpen = ref(false);

// The name, number and how to fill the sheet (2026-11-09, by request): Arabic runs from the right,
// so these lines start beside the QR code and run towards the left margin instead of off the page;
// the instructions go on two lines. The bubbles, marks and QR code are where they were.
const rtl = typeof document !== 'undefined' && document.documentElement.dir === 'rtl';
const tx = rtl ? 140 : 30;
function wrap(text, max) {
    const lines = [''];
    for (const w of String(text).split(' ')) {
        if ((lines.at(-1) + ' ' + w).trim().length > max && lines.at(-1)) lines.push(w);
        else lines[lines.length - 1] = (lines.at(-1) + ' ' + w).trim();
    }
    return lines;
}
const howLines = computed(() => wrap(t('exam.omr_how', 'املأ الدائرة كاملةً بقلم أسود أو أزرق غامق. لا تكتب قرب المربعات السوداء ولا على الرمز.'), 62));
</script>

<template>
    <Head :title="t('exam.omr', 'ورق الفقاعات') + ' — ' + exam.title" />
    <div class="min-h-screen bg-gray-100 print:bg-white dark:bg-gray-900">
        <!-- The top bar -->
        <header class="sticky top-0 z-20 border-b border-gray-200 bg-white/95 backdrop-blur print:hidden dark:border-gray-700 dark:bg-gray-800/95">
            <div class="mx-auto flex max-w-5xl items-center gap-2 px-3 py-2.5 sm:px-6">
                <Link :href="route('exams.manage.edit', exam.id)" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" :aria-label="t('common.back', 'رجوع')"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs text-gray-500">{{ t('exam.omr', 'ورق الفقاعات') }}</p>
                    <h1 class="truncate text-base font-bold">{{ exam.title }}</h1>
                </div>
                <button @click="printPage" class="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-[var(--accent-600)] px-4 py-2 text-sm font-semibold text-white shadow-sm hover:brightness-110"><PrinterIcon class="h-5 w-5" /> <span>{{ t('exam.print', 'طباعة') }}</span></button>
            </div>
        </header>

        <main class="mx-auto max-w-5xl space-y-4 px-3 py-4 print:hidden sm:px-6">
            <!-- At a glance -->
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3">
                <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700 dark:text-gray-200"><QuestionMarkCircleIcon class="h-5 w-5" /></span>
                    <div><p class="text-xs text-gray-500">{{ t('exam.omr_questions', 'أسئلة على الورقة') }}</p><p class="text-xl font-bold tabular-nums">{{ Math.min(questions.length, capacity) }}</p></div>
                </div>
                <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700 dark:text-gray-200"><UsersIcon class="h-5 w-5" /></span>
                    <div><p class="text-xs text-gray-500">{{ t('exam.omr_sheets', 'أوراق (طالب لكل ورقة)') }}</p><p class="text-xl font-bold tabular-nums">{{ students.length }}</p></div>
                </div>
                <div v-if="skipped || questions.length > capacity" class="col-span-2 flex items-start gap-2 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 sm:col-span-1 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-100">
                    <ExclamationTriangleIcon class="h-5 w-5 shrink-0" />
                    <span>
                        <span v-if="skipped" class="block">{{ t('exam.omr_skipped', ':n سؤالاً ليس اختياراً من متعدد أو صح/خطأ لا يظهر في الورقة؛ يُصحَّح يدوياً.', { n: skipped }) }}</span>
                        <span v-if="questions.length > capacity" class="block font-semibold text-red-700 dark:text-red-300">{{ t('exam.omr_too_many', 'الورقة تتسع لـ :n سؤالاً فقط.', { n: capacity }) }}</span>
                    </span>
                </div>
            </div>

            <p v-if="$page.props.flash?.success" class="flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200"><CheckCircleIcon class="h-5 w-5 shrink-0" /> {{ $page.props.flash.success }}</p>
            <p v-if="$page.props.errors?.file" class="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $page.props.errors.file }}</p>

            <!-- The sheets of this page: print, then read the scans -->
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <h2 class="flex items-center gap-2 font-semibold"><DocumentTextIcon class="h-5 w-5 text-[var(--accent-600)]" /> {{ t('exam.omr_here', 'أوراق هذه الصفحة') }}</h2>
                <p class="mt-1 text-xs text-gray-500">{{ t('exam.omr_here_hint', 'اطبعها من هنا (ورقة باسم كل طالب)، وبعد الامتحان اقرأ الأوراق الممسوحة بالكاميرا أو من صور.') }}</p>
                <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_auto_auto]">
                    <select v-model="only" class="w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                        <option value="">{{ t('exam.all_students', 'كل الطلاب') }} ({{ students.length }})</option>
                        <option v-for="s in students" :key="s.id" :value="String(s.id)">{{ s.name }}<template v-if="s.number"> — {{ s.number }}</template></option>
                    </select>
                    <button @click="printPage" class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-[var(--accent-600)] px-4 py-2 text-sm font-semibold text-white"><PrinterIcon class="h-4 w-4" /> {{ only ? t('exam.print_one', 'طباعة ورقته') : t('exam.print_all', 'طباعة الكل') }}</button>
                    <Link :href="route('exams.manage.omr.scan', exam.id)" class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600"><CameraIcon class="h-4 w-4" /> {{ t('exam.omr_scan', 'قراءة الأوراق الممسوحة') }}</Link>
                </div>
            </section>

            <!-- Auto Multiple Choice on the server -->
            <AmcServerPanel :exam="exam" />

            <!-- Auto Multiple Choice on your own computer -->
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <button type="button" @click="amcOpen = !amcOpen" class="flex w-full items-center gap-2 px-4 py-3 text-start">
                    <ComputerDesktopIcon class="h-5 w-5 text-gray-500" />
                    <span class="min-w-0 flex-1"><span class="block font-semibold">{{ t('exam.amc_desktop', 'AMC على حاسبتك') }}</span><span class="block truncate text-xs text-gray-500">{{ t('exam.amc_hint', 'برنامج مجاني لطباعة أوراق الفقاعات وقراءتها؛ صدّر النتائج CSV مع «الخانات المؤشّرة» بالحروف.') }}</span></span>
                    <ChevronDownIcon class="h-5 w-5 shrink-0 text-gray-400 transition" :class="amcOpen ? 'rotate-180' : ''" />
                </button>
                <div v-if="amcOpen" class="grid gap-2 border-t border-gray-100 p-4 sm:grid-cols-2 dark:border-gray-700">
                    <a :href="route('exams.manage.amc', exam.id)" class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-300 px-4 py-2.5 text-sm dark:border-gray-600"><ArrowDownTrayIcon class="h-4 w-4" /> {{ t('exam.amc_export', 'مشروع AMC (الأسئلة وقائمة الطلاب)') }}</a>
                    <label class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-xl border border-gray-300 px-4 py-2.5 text-sm dark:border-gray-600"><input type="file" accept=".csv,.txt" class="sr-only" @change="amcImport" /><ArrowUpTrayIcon class="h-4 w-4" /> {{ t('exam.amc_import', 'رفع نتائج AMC (CSV)') }}</label>
                </div>
            </section>

            <h2 class="flex items-center gap-2 pt-2 text-sm font-semibold text-gray-600 dark:text-gray-300">{{ t('exam.omr_preview', 'معاينة الأوراق') }} <span class="rounded-full bg-gray-200 px-2 text-xs dark:bg-gray-700">{{ shownSheets.length }}</span>
                <span class="text-xs font-normal text-gray-400">{{ t('exam.omr_preview_hint', 'مصغّرة على الشاشة؛ تُطبع بحجم A4 كاملاً.') }}</span></h2>
        </main>

        <!-- The sheets: unchanged; only shown smaller on a narrow screen -->
        <div ref="area" class="mx-auto max-w-5xl px-3 pb-8 sm:px-6 print:max-w-none print:p-0">
            <div v-for="s in shownSheets" :key="s.id" class="omr-wrap" :style="{ '--s': scale }">
                <div class="omr-page bg-white shadow print:m-0 print:shadow-none" style="width: 210mm; height: 297mm">
                    <svg :viewBox="`0 0 ${PAGE.w} ${PAGE.h}`" width="210mm" height="297mm" xmlns="http://www.w3.org/2000/svg" font-family="Arial, sans-serif">
                        <rect v-for="(m, i) in MARKS" :key="i" :x="m[0] - MARK / 2" :y="m[1] - MARK / 2" :width="MARK" :height="MARK" fill="#000" />
                        <text x="105" y="17" text-anchor="middle" font-size="5" font-weight="bold">{{ exam.title }}</text>
                        <text x="105" y="23" text-anchor="middle" font-size="3.2" fill="#444">{{ site }}{{ exam.subject ? ' · ' + exam.subject : '' }}</text>
                        <text :x="tx" y="33" font-size="4">{{ t('exam.gs_name', 'اسم الطالب') }}: <tspan font-weight="bold">{{ s.name }}</tspan></text>
                        <text :x="tx" y="40" font-size="4">{{ t('exam.card_number', 'الرقم الجامعي') }}: <tspan font-weight="bold" direction="ltr" unicode-bidi="embed">{{ s.number || '' }}</tspan></text>
                        <text v-for="(line, li) in howLines" :key="li" :x="tx" :y="47 + li * 3.6" font-size="2.8" fill="#555">{{ line }}</text>
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
        </div>
    </div>
</template>

<style>
@media screen {
    /* On the screen a sheet keeps its A4 drawing and is only scaled to fit the width. */
    .omr-wrap { position: relative; width: calc(210mm * var(--s, 1)); height: calc(297mm * var(--s, 1)); margin: 0 auto 1.25rem; }
    .omr-wrap .omr-page { position: absolute; top: 0; left: 0; transform: scale(var(--s, 1)); transform-origin: top left; }
}
@media print {
    @page { size: A4; margin: 0; }
    .omr-page { page-break-after: always; break-after: page; }
}
</style>
