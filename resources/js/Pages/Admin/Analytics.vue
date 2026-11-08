<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    ArrowDownTrayIcon, ArrowTrendingDownIcon, ArrowTrendingUpIcon, ArrowUpTrayIcon, BookOpenIcon, ChartBarIcon,
    CircleStackIcon, ClockIcon, FunnelIcon, PencilSquareIcon, PrinterIcon, ShareIcon, SparklesIcon, TrophyIcon, UsersIcon,
} from '@heroicons/vue/24/outline';
import Chart from 'chart.js/auto';

const { t } = useTrans();
const props = defineProps({
    filters: Object, period: Object, shiftStart: String, report: Object, users: Array, roles: Array,
});

const COLORS = { uploads: '#4f46e5', data_entry: '#059669', circulation: '#d97706', sharing: '#0284c7', downloads: '#7c3aed', deletions: '#e11d48' };
const PIE = ['#4f46e5', '#059669', '#d97706', '#0284c7', '#e11d48', '#7c3aed', '#0d9488', '#db2777', '#65a30d', '#94a3b8'];
const CATEGORY_LABELS = {
    uploads: t('analytics.cat.uploads', 'رفع الملفات'),
    data_entry: t('analytics.cat.data_entry', 'إدخال البيانات'),
    circulation: t('analytics.cat.circulation', 'الإعارة والاسترجاع'),
    sharing: t('analytics.cat.sharing', 'المشاركة'),
    downloads: t('analytics.cat.downloads', 'التحميل'),
    deletions: t('analytics.cat.deletions', 'الحذف'),
};
const ROLE_LABELS = { admin: t('role.admin', 'مدير'), user: t('role.user', 'مستخدم'), editor: t('role.editor', 'محرّر'), contributor: t('role.contributor', 'مساهم') };
const TYPE_LABELS = {
    pdf: 'PDF', word: t('analytics.type.word', 'مستندات Word ونصوص'), excel: t('analytics.type.excel', 'جداول Excel'),
    slides: t('analytics.type.slides', 'عروض تقديمية'), image: t('analytics.type.image', 'صور'), video: t('analytics.type.video', 'فيديو'),
    audio: t('analytics.type.audio', 'صوت'), archive: t('analytics.type.archive', 'أرشيفات مضغوطة'), other: t('analytics.type.other', 'أخرى'),
};
const FIELD_LABELS = {
    'thesis.thesis_title': 'عنوان الرسالة', 'thesis.researcher_name': 'اسم الباحث', 'thesis.degree': 'الدرجة',
    'thesis.supervisor_name': 'المشرف', 'thesis.publication_year': 'سنة النشر', 'thesis.department': 'القسم',
    'thesis.subject': 'الموضوع', 'thesis.keywords': 'الكلمات المفتاحية', 'thesis.abstract': 'المستخلص',
    'thesis.defense_date': 'تاريخ المناقشة', 'thesis.language': 'اللغة', 'thesis.pages': 'عدد الصفحات',
    'book.title': 'عنوان الكتاب', 'book.authors': 'المؤلفون', 'book.isbn': 'ISBN', 'book.publisher': 'الناشر',
    'book.year': 'سنة الكتاب', 'book.language': 'لغة الكتاب', 'book.section_id': 'القسم/الرف', 'book.description': 'وصف الكتاب',
};
const PRESETS = [
    ['today', t('analytics.p.today', 'اليوم')],
    ['shift', t('analytics.p.shift', 'من بداية الدوام')],
    ['yesterday', t('analytics.p.yesterday', 'أمس')],
    ['this_week', t('analytics.p.this_week', 'هذا الأسبوع')],
    ['last_week', t('analytics.p.last_week', 'الأسبوع الماضي')],
    ['this_month', t('analytics.p.this_month', 'هذا الشهر')],
    ['last_month', t('analytics.p.last_month', 'الشهر الماضي')],
    ['last_7', t('analytics.p.last_7', 'آخر 7 أيام')],
    ['last_30', t('analytics.p.last_30', 'آخر 30 يوماً')],
    ['last_90', t('analytics.p.last_90', 'آخر 90 يوماً')],
    ['this_year', t('analytics.p.this_year', 'هذه السنة')],
    ['custom', t('analytics.p.custom', 'فترة مخصصة')],
];
const WEEKDAYS = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];

const f = reactive({ preset: props.filters.preset, from: props.filters.from, to: props.filters.to, user: props.filters.user ?? '', role: props.filters.role ?? '' });
const loading = ref(false);

function query() {
    const q = { preset: f.preset };
    if (f.preset === 'custom') Object.assign(q, { from: f.from, to: f.to });
    if (f.user) q.user = f.user;
    if (f.role) q.role = f.role;
    return q;
}
function apply() {
    router.get(route('admin.analytics.index'), query(), {
        preserveState: true, preserveScroll: true, replace: true,
        onStart: () => (loading.value = true), onFinish: () => (loading.value = false),
    });
}
function pick(preset) {
    f.preset = preset;
    if (preset !== 'custom') apply();
}
const exportUrl = computed(() => route('admin.analytics.export', query()));
const printPage = () => window.print();

const num = (n) => new Intl.NumberFormat('en-US').format(n ?? 0);
function bytes(b) {
    if (!b) return '0 MB';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(u.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
    return `${(b / 1024 ** i).toFixed(i > 1 ? 1 : 0)} ${u[i]}`;
}
function delta(k) {
    const { value, previous } = k;
    if (!previous) return value ? { text: t('analytics.new', 'جديد'), up: true } : null;
    const p = Math.round(((value - previous) / previous) * 100);
    return p === 0 ? { text: '0%', flat: true } : { text: `${p > 0 ? '+' : ''}${p}%`, up: p > 0 };
}
const qualityColor = (s) => (s == null ? 'bg-gray-100 text-gray-500 dark:bg-gray-700' : s >= 85 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : s >= 60 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300');
const rankTone = (r) => ({ 1: 'bg-amber-100 text-amber-700 ring-1 ring-amber-300 dark:bg-amber-900/40 dark:text-amber-300', 2: 'bg-slate-100 text-slate-700 ring-1 ring-slate-300 dark:bg-slate-700 dark:text-slate-200', 3: 'bg-orange-100 text-orange-700 ring-1 ring-orange-300 dark:bg-orange-900/40 dark:text-orange-300' })[r] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';

const k = computed(() => props.report.kpis);
const cards = computed(() => [
    { key: 'total', label: t('analytics.k.total', 'مجموع النشاط'), icon: SparklesIcon, tone: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300', value: num(k.value.total.value), prev: num(k.value.total.previous), d: delta(k.value.total) },
    { key: 'uploads', label: CATEGORY_LABELS.uploads, icon: ArrowUpTrayIcon, tone: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300', value: num(k.value.uploads.value), prev: num(k.value.uploads.previous), d: delta(k.value.uploads) },
    { key: 'bytes', label: t('analytics.k.bytes', 'حجم المرفوع'), icon: CircleStackIcon, tone: 'bg-sky-50 text-sky-600 dark:bg-sky-900/40 dark:text-sky-300', value: bytes(k.value.bytes.value), prev: bytes(k.value.bytes.previous), d: delta(k.value.bytes) },
    { key: 'data_entry', label: CATEGORY_LABELS.data_entry, icon: PencilSquareIcon, tone: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300', value: num(k.value.data_entry.value), prev: num(k.value.data_entry.previous), d: delta(k.value.data_entry) },
    { key: 'circulation', label: CATEGORY_LABELS.circulation, icon: BookOpenIcon, tone: 'bg-amber-50 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300', value: num(k.value.circulation.value), prev: num(k.value.circulation.previous), d: delta(k.value.circulation) },
    { key: 'active_staff', label: t('analytics.k.staff', 'موظفون نشطون'), icon: UsersIcon, tone: 'bg-violet-50 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300', value: num(k.value.active_staff.value), prev: num(k.value.active_staff.previous), d: delta(k.value.active_staff) },
    { key: 'sharing', label: CATEGORY_LABELS.sharing, icon: ShareIcon, tone: 'bg-sky-50 text-sky-600 dark:bg-sky-900/40 dark:text-sky-300', value: num(k.value.sharing.value), prev: num(k.value.sharing.previous), d: delta(k.value.sharing) },
    { key: 'downloads', label: CATEGORY_LABELS.downloads, icon: ArrowDownTrayIcon, tone: 'bg-violet-50 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300', value: num(k.value.downloads.value), prev: num(k.value.downloads.previous), d: delta(k.value.downloads) },
]);
const staff = computed(() => props.report.staff);
const quality = computed(() => props.report.quality);
const topMissing = computed(() => Math.max(1, ...quality.value.missing.map((m) => m.count)));

// Charts.
const el = {};
const charts = {};
const isDark = () => document.documentElement.classList.contains('dark');
function draw(name, config) {
    charts[name]?.destroy();
    if (!el[name]) return;
    charts[name] = new Chart(el[name], config);
}
const legend = { position: 'bottom', labels: { boxWidth: 12, padding: 12, font: { family: 'inherit' } } };
function doughnut(labels, data, colors) {
    return {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: isDark() ? '#1f2937' : '#fff' }] },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: {
                legend,
                tooltip: { callbacks: { label: (c) => { const sum = c.dataset.data.reduce((a, b) => a + b, 0) || 1; return ` ${c.label}: ${num(c.raw)} (${Math.round((c.raw / sum) * 100)}%)`; } } },
            },
        },
    };
}
const hasCategoryData = computed(() => Object.keys(CATEGORY_LABELS).some((c) => k.value[c].value > 0));

function render() {
    Chart.defaults.color = isDark() ? '#9ca3af' : '#6b7280';
    Chart.defaults.borderColor = isDark() ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
    const r = props.report;
    const cats = Object.keys(CATEGORY_LABELS);

    draw('trend', {
        type: 'bar',
        data: {
            labels: r.trend.labels,
            datasets: cats.map((c) => ({ label: CATEGORY_LABELS[c], data: r.trend.series[c], backgroundColor: COLORS[c], borderRadius: 3, stack: 's' })),
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend },
            scales: { x: { stacked: true, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
        },
    });

    draw('categories', doughnut(cats.map((c) => CATEGORY_LABELS[c]), cats.map((c) => k.value[c].value), cats.map((c) => COLORS[c])));

    const top = r.staff.slice(0, 10);
    draw('compare', {
        type: 'bar',
        data: {
            labels: top.map((s) => s.name),
            datasets: cats.map((c) => ({ label: CATEGORY_LABELS[c], data: top.map((s) => s[c]), backgroundColor: COLORS[c], borderRadius: 3, stack: 's' })),
        },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend },
            scales: { x: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }, y: { stacked: true } },
        },
    });

    const uploaders = [...r.staff].filter((s) => s.files > 0).sort((a, b) => b.files - a.files);
    const head = uploaders.slice(0, 7);
    const rest = uploaders.slice(7).reduce((a, s) => a + s.files, 0);
    draw('uploaders', doughnut(
        [...head.map((s) => s.name), ...(rest ? [t('analytics.others', 'آخرون')] : [])],
        [...head.map((s) => s.files), ...(rest ? [rest] : [])],
        PIE,
    ));

    draw('types', doughnut(r.fileTypes.map((x) => TYPE_LABELS[x.type] ?? x.type), r.fileTypes.map((x) => x.files), PIE));

    draw('hours', {
        type: 'bar',
        data: { labels: r.hours.map((_, h) => `${String(h).padStart(2, '0')}:00`), datasets: [{ label: t('analytics.activity', 'النشاط'), data: r.hours, backgroundColor: '#4f46e5', borderRadius: 3 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } }, y: { beginAtZero: true, ticks: { precision: 0 } } } },
    });

    draw('weekdays', {
        type: 'bar',
        data: { labels: WEEKDAYS, datasets: [{ label: t('analytics.activity', 'النشاط'), data: r.weekdays, backgroundColor: '#059669', borderRadius: 3 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
    });

    draw('quality', {
        type: 'doughnut',
        data: { datasets: [{ data: [quality.value.score ?? 0, 100 - (quality.value.score ?? 0)], backgroundColor: [quality.value.score >= 85 ? '#059669' : quality.value.score >= 60 ? '#d97706' : '#e11d48', isDark() ? '#374151' : '#e5e7eb'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, rotation: -90, circumference: 180, cutout: '75%', plugins: { legend: { display: false }, tooltip: { enabled: false } } },
    });
}

onMounted(() => nextTick(render));
onBeforeUnmount(() => Object.values(charts).forEach((c) => c.destroy()));
watch(() => props.report, () => nextTick(render));
watch(() => props.filters, (v) => Object.assign(f, { preset: v.preset, from: v.from, to: v.to, user: v.user ?? '', role: v.role ?? '' }));

const presetLabel = computed(() => PRESETS.find(([p]) => p === props.filters.preset)?.[1] ?? '');
</script>

<template>
    <Head :title="t('admin.nav.analytics', 'التحليلات')" />

    <AdminLayout>
        <template #header>
            <div class="flex w-full items-center justify-between gap-2">
                <h2 class="flex min-w-0 items-center gap-2 text-base font-semibold text-gray-800 sm:text-lg dark:text-gray-100">
                    <ChartBarIcon class="h-5 w-5 shrink-0 text-gray-500" />
                    <span class="truncate sm:hidden">{{ t('analytics.short_title', 'التحليلات') }}</span>
                    <span class="hidden truncate sm:inline">{{ t('analytics.title', 'التحليلات وأداء الموظفين') }}</span>
                </h2>
                <div class="flex shrink-0 gap-1.5 print:hidden sm:gap-2">
                    <a :href="exportUrl" :title="t('analytics.export', 'تصدير Excel')" :aria-label="t('analytics.export', 'تصدير Excel')" class="inline-flex h-9 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-gray-200 px-2.5 text-sm text-gray-700 hover:bg-gray-50 sm:px-3 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        <ArrowDownTrayIcon class="h-4 w-4 shrink-0" /> <span class="hidden sm:inline">{{ t('analytics.export', 'تصدير Excel') }}</span>
                    </a>
                    <button type="button" @click="printPage" :title="t('analytics.print', 'طباعة')" :aria-label="t('analytics.print', 'طباعة')" class="inline-flex h-9 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-gray-200 px-2.5 text-sm text-gray-700 hover:bg-gray-50 sm:px-3 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        <PrinterIcon class="h-4 w-4 shrink-0" /> <span class="hidden sm:inline">{{ t('analytics.print', 'طباعة') }}</span>
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-5 p-4 sm:p-6" :class="{ 'opacity-60 transition-opacity': loading }">
            <!-- Filters -->
            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800 print:hidden">
                <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                    <FunnelIcon class="h-4 w-4" /> {{ t('analytics.period', 'الفترة') }}
                </div>
                <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 sm:flex-wrap">
                    <button
                        v-for="[key, label] in PRESETS" :key="key" type="button" @click="pick(key)"
                        class="shrink-0 rounded-full border px-3.5 py-1.5 text-sm transition"
                        :class="f.preset === key
                            ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white shadow-sm'
                            : 'border-gray-200 bg-gray-50 text-gray-700 hover:border-gray-300 hover:bg-white dark:border-gray-600 dark:bg-gray-700/50 dark:text-gray-200'"
                    >
                        {{ label }}<span v-if="key === 'shift'" class="ms-1 text-xs opacity-75" dir="ltr">{{ shiftStart }}</span>
                    </button>
                </div>

                <div v-if="f.preset === 'custom'" class="mt-3 flex flex-wrap items-end gap-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                    <label class="text-xs text-gray-500">{{ t('analytics.from', 'من') }}
                        <input v-model="f.from" type="date" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    </label>
                    <label class="text-xs text-gray-500">{{ t('analytics.to', 'إلى') }}
                        <input v-model="f.to" type="date" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    </label>
                    <button type="button" @click="apply" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm font-medium text-white">{{ t('analytics.apply', 'عرض') }}</button>
                </div>

                <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:max-w-xl">
                    <select v-model="f.user" @change="apply" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">{{ t('analytics.all_staff', 'كل الموظفين') }}</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select v-model="f.role" @change="apply" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">{{ t('analytics.all_roles', 'كل الأدوار') }}</option>
                        <option v-for="r in roles" :key="r" :value="r">{{ ROLE_LABELS[r] ?? r }}</option>
                    </select>
                </div>

                <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                    <ClockIcon class="h-4 w-4" />
                    <span>{{ presetLabel }}:</span>
                    <span dir="ltr">{{ period.from }} → {{ period.to }}</span>
                    <span class="text-gray-400">·</span>
                    <span>{{ t('analytics.compared', 'مقارنة بـ') }}</span>
                    <span dir="ltr">{{ period.prevFrom }} → {{ period.prevTo }}</span>
                    <span class="text-gray-400">({{ t('analytics.baghdad', 'بتوقيت بغداد') }})</span>
                </p>
            </section>

            <!-- Print header -->
            <div class="hidden print:block">
                <h1 class="text-xl font-bold">{{ t('analytics.title', 'التحليلات وأداء الموظفين') }}</h1>
                <p class="text-sm" dir="ltr">{{ period.from }} → {{ period.to }}</p>
            </div>

            <!-- KPI cards -->
            <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div v-for="c in cards" :key="c.key" class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-start justify-between gap-2">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl" :class="c.tone"><component :is="c.icon" class="h-5 w-5" /></span>
                        <span v-if="c.d" class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium" dir="ltr"
                            :class="c.d.flat ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' : c.d.up ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300'">
                            <ArrowTrendingUpIcon v-if="c.d.up && !c.d.flat" class="h-3.5 w-3.5" />
                            <ArrowTrendingDownIcon v-else-if="!c.d.flat" class="h-3.5 w-3.5" />
                            {{ c.d.text }}
                        </span>
                    </div>
                    <p class="mt-3 text-2xl font-bold tracking-tight text-gray-900 dark:text-white" dir="ltr" style="text-align: start">{{ c.value }}</p>
                    <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ c.label }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ t('analytics.previous', 'الفترة السابقة') }}: <span dir="ltr">{{ c.prev }}</span></p>
                </div>
            </section>

            <!-- Trend + categories -->
            <section class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm lg:col-span-2 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-100">{{ t('analytics.trend', 'النشاط عبر الزمن') }}</h3>
                    <div class="relative h-72"><canvas :ref="(e) => (el.trend = e)" /></div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-100">{{ t('analytics.by_category', 'توزيع النشاط حسب النوع') }}</h3>
                    <div v-show="hasCategoryData" class="relative h-72"><canvas :ref="(e) => (el.categories = e)" /></div>
                    <p v-if="!hasCategoryData" class="flex h-72 items-center justify-center text-sm text-gray-400">{{ t('analytics.empty', 'لا يوجد نشاط في هذه الفترة') }}</p>
                </div>
            </section>

            <!-- Staff comparison -->
            <section class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm lg:col-span-2 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ t('analytics.compare', 'مقارنة الموظفين') }}</h3>
                    <p class="mb-3 text-xs text-gray-500">{{ t('analytics.compare_hint', 'أعلى 10 موظفين نشاطاً، مقسّماً حسب نوع العمل') }}</p>
                    <div v-show="staff.length" class="relative" :style="{ height: Math.max(220, Math.min(staff.length, 10) * 40 + 80) + 'px' }"><canvas :ref="(e) => (el.compare = e)" /></div>
                    <p v-if="!staff.length" class="py-16 text-center text-sm text-gray-400">{{ t('analytics.empty', 'لا يوجد نشاط في هذه الفترة') }}</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ t('analytics.uploaders', 'حصة كل موظف من الرفع') }}</h3>
                    <p class="mb-3 text-xs text-gray-500">{{ t('analytics.uploaders_hint', 'عدد الملفات المرفوعة') }}</p>
                    <div v-show="staff.some((s) => s.files)" class="relative h-72"><canvas :ref="(e) => (el.uploaders = e)" /></div>
                    <p v-if="!staff.some((s) => s.files)" class="flex h-72 items-center justify-center text-sm text-gray-400">{{ t('analytics.no_uploads', 'لا توجد ملفات مرفوعة') }}</p>
                </div>
            </section>

            <!-- Leaderboard -->
            <section class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 p-4 dark:border-gray-700">
                    <h3 class="flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-100"><TrophyIcon class="h-5 w-5 text-amber-500" /> {{ t('analytics.leaderboard', 'تقرير أداء الموظفين') }}</h3>
                    <span class="text-xs text-gray-500">{{ t('analytics.index_hint', 'مؤشر الأداء = نشاط الموظف ÷ نشاط الأعلى × 100') }}</span>
                </div>

                <p v-if="!staff.length" class="p-10 text-center text-sm text-gray-400">{{ t('analytics.empty', 'لا يوجد نشاط في هذه الفترة') }}</p>

                <!-- Phone: cards -->
                <div class="divide-y divide-gray-100 md:hidden dark:divide-gray-700">
                    <div v-for="s in staff" :key="s.id" class="p-4">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-bold" :class="rankTone(s.rank)"><TrophyIcon v-if="s.rank <= 3" class="h-4 w-4" /><template v-else>{{ s.rank }}</template></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-gray-900 dark:text-white">{{ s.name }}</p>
                                <p class="text-xs text-gray-500">{{ ROLE_LABELS[s.role] ?? s.role }}<template v-if="s.librarian"> · {{ t('analytics.librarian', 'أمين مكتبة') }}</template></p>
                            </div>
                            <div class="text-end">
                                <p class="text-lg font-bold text-gray-900 dark:text-white">{{ num(s.total) }}</p>
                                <p class="text-xs" :class="s.total >= s.previous ? 'text-emerald-600' : 'text-rose-600'" dir="ltr">{{ s.total - s.previous >= 0 ? '+' : '' }}{{ num(s.total - s.previous) }}</p>
                            </div>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-[var(--accent-600)]" :style="{ width: s.index + '%' }" /></div>
                        <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                            <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-900/40"><p class="font-semibold text-gray-800 dark:text-gray-100">{{ num(s.files) }}</p><p class="text-gray-500">{{ t('analytics.files', 'ملفات') }} · {{ bytes(s.bytes) }}</p></div>
                            <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-900/40"><p class="font-semibold text-gray-800 dark:text-gray-100">{{ num(s.data_entry) }}</p><p class="text-gray-500">{{ CATEGORY_LABELS.data_entry }}</p></div>
                            <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-900/40"><p class="font-semibold text-gray-800 dark:text-gray-100">{{ num(s.circulation) }}</p><p class="text-gray-500">{{ t('analytics.cat.circulation_short', 'الإعارة') }}</p></div>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span class="rounded-full px-2 py-0.5 font-medium" :class="qualityColor(s.quality)">{{ t('analytics.quality', 'الجودة') }} {{ s.quality == null ? '—' : s.quality + '%' }}</span>
                            <span>{{ t('analytics.share', 'المساهمة') }} {{ s.share }}%</span>
                            <span>· {{ s.active_days }} {{ t('analytics.days', 'يوم نشاط') }}</span>
                        </div>
                    </div>
                </div>

                <!-- Desktop: table -->
                <div v-if="staff.length" class="hidden overflow-x-auto md:block">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-3 text-center">#</th>
                                <th class="px-3 py-3 text-start">{{ t('analytics.employee', 'الموظف') }}</th>
                                <th class="px-3 py-3 text-center">{{ CATEGORY_LABELS.uploads }}</th>
                                <th class="px-3 py-3 text-center">{{ CATEGORY_LABELS.data_entry }}</th>
                                <th class="px-3 py-3 text-center">{{ t('analytics.cat.circulation_short', 'الإعارة') }}</th>
                                <th class="px-3 py-3 text-center">{{ t('analytics.other_ops', 'مشاركة/تحميل/حذف') }}</th>
                                <th class="px-3 py-3 text-center">{{ t('analytics.k.total', 'مجموع النشاط') }}</th>
                                <th class="w-40 px-3 py-3 text-start">{{ t('analytics.index', 'مؤشر الأداء') }}</th>
                                <th class="px-3 py-3 text-center">{{ t('analytics.quality', 'الجودة') }}</th>
                                <th class="px-3 py-3 text-center">{{ t('analytics.days_short', 'أيام النشاط') }}</th>
                                <th class="px-3 py-3 text-start">{{ t('analytics.last', 'آخر نشاط') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="s in staff" :key="s.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-3 py-3 text-center"><span class="mx-auto flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold" :class="rankTone(s.rank)"><TrophyIcon v-if="s.rank <= 3" class="h-4 w-4" /><template v-else>{{ s.rank }}</template></span></td>
                                <td class="px-3 py-3">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ s.name }}</p>
                                    <p class="text-xs text-gray-500">{{ ROLE_LABELS[s.role] ?? s.role }}<template v-if="s.librarian"> · {{ t('analytics.librarian', 'أمين مكتبة') }}</template></p>
                                </td>
                                <td class="px-3 py-3 text-center"><p class="font-semibold">{{ num(s.files) }}</p><p class="text-xs text-gray-500" dir="ltr">{{ bytes(s.bytes) }}</p></td>
                                <td class="px-3 py-3 text-center font-semibold">{{ num(s.data_entry) }}<p v-if="s.records" class="text-xs font-normal text-gray-500">{{ s.records }} {{ t('analytics.records', 'سجل') }}</p></td>
                                <td class="px-3 py-3 text-center font-semibold">{{ num(s.circulation) }}</td>
                                <td class="px-3 py-3 text-center text-gray-600 dark:text-gray-300" dir="ltr">{{ s.sharing }} / {{ s.downloads }} / {{ s.deletions }}</td>
                                <td class="px-3 py-3 text-center">
                                    <p class="text-base font-bold text-gray-900 dark:text-white">{{ num(s.total) }}</p>
                                    <p class="text-xs" :class="s.total >= s.previous ? 'text-emerald-600' : 'text-rose-600'" dir="ltr">{{ s.total - s.previous >= 0 ? '+' : '' }}{{ num(s.total - s.previous) }}</p>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-[var(--accent-600)]" :style="{ width: s.index + '%' }" /></div>
                                        <span class="w-8 text-xs text-gray-600 dark:text-gray-300">{{ s.index }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-400">{{ t('analytics.share', 'المساهمة') }} {{ s.share }}%</p>
                                </td>
                                <td class="px-3 py-3 text-center"><span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="qualityColor(s.quality)">{{ s.quality == null ? '—' : s.quality + '%' }}</span></td>
                                <td class="px-3 py-3 text-center">{{ s.active_days }}</td>
                                <td class="px-3 py-3 text-xs text-gray-500" dir="ltr" style="text-align: start">{{ s.last_at ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Quality -->
            <section class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ t('analytics.quality_title', 'جودة البيانات المُدخلة') }}</h3>
                    <p class="mb-2 text-xs text-gray-500">{{ t('analytics.quality_hint', 'نسبة اكتمال حقول الرسائل الجامعية والكتب التي أُدخلت أو عُدّلت في الفترة') }}</p>
                    <div class="relative mx-auto h-40 max-w-xs">
                        <canvas :ref="(e) => (el.quality = e)" />
                        <div class="absolute inset-x-0 bottom-3 text-center">
                            <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ quality.score == null ? '—' : quality.score + '%' }}</p>
                            <p class="text-xs text-gray-500">{{ num(quality.records) }} {{ t('analytics.records', 'سجل') }}</p>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm lg:col-span-2 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-100">{{ t('analytics.missing', 'أكثر الحقول نقصاً') }}</h3>
                    <p v-if="!quality.missing.length" class="py-10 text-center text-sm text-gray-400">{{ quality.records ? t('analytics.all_complete', 'كل السجلات مكتملة') : t('analytics.no_records', 'لم تُدخل سجلات في هذه الفترة') }}</p>
                    <ul class="space-y-2.5">
                        <li v-for="m in quality.missing" :key="m.field" class="flex items-center gap-3 text-sm">
                            <span class="w-32 shrink-0 truncate text-gray-700 dark:text-gray-300">{{ FIELD_LABELS[m.field] ?? m.field }}</span>
                            <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-rose-500" :style="{ width: (m.count / topMissing) * 100 + '%' }" /></div>
                            <span class="w-20 shrink-0 text-xs text-gray-500">{{ m.count }} {{ t('analytics.empty_records', 'سجل ناقص') }}</span>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Files and working hours -->
            <section class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-100">{{ t('analytics.types', 'أنواع الملفات المرفوعة') }}</h3>
                    <div v-show="report.fileTypes.length" class="relative h-64"><canvas :ref="(e) => (el.types = e)" /></div>
                    <p v-if="!report.fileTypes.length" class="flex h-64 items-center justify-center text-sm text-gray-400">{{ t('analytics.no_uploads', 'لا توجد ملفات مرفوعة') }}</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-100">{{ t('analytics.hours', 'ساعات الذروة') }}</h3>
                    <div class="relative h-64"><canvas :ref="(e) => (el.hours = e)" /></div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-100">{{ t('analytics.weekdays', 'النشاط حسب أيام الأسبوع') }}</h3>
                    <div class="relative h-64"><canvas :ref="(e) => (el.weekdays = e)" /></div>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
