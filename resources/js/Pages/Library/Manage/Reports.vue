<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowDownTrayIcon, PrinterIcon } from '@heroicons/vue/24/outline';
import Chart from 'chart.js/auto';

const props = defineProps({
    filters: Object,
    totals: Object,
    monthly: Array,
    topBooks: Array,
    topReaders: Array,
    bySection: Array,
    byType: Object,
    lateReturns: Number,
});

const { t } = useTrans();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const money = (n) => `${Number(n || 0).toLocaleString('en-US')} ${t('library.currency', 'د.ع')}`;
const num = (n) => Number(n || 0).toLocaleString('en-US');

const MONTHS = ['كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'];
const monthLabel = (ym) => { const [y, m] = ym.split('-'); return `${MONTHS[Number(m) - 1]} ${y}`; };

function apply(f = from.value, tt = to.value) {
    router.get(route('library.manage.reports'), { from: f, to: tt }, { preserveState: true, preserveScroll: true });
}
const iso = (d) => d.toISOString().slice(0, 10);
function quick(kind) {
    const now = new Date();
    const start = {
        month: new Date(now.getFullYear(), now.getMonth(), 1),
        quarter: new Date(now.getFullYear(), now.getMonth() - 2, 1),
        year: new Date(now.getFullYear(), 0, 1),
        twelve: new Date(now.getFullYear(), now.getMonth() - 11, 1),
    }[kind];
    from.value = iso(new Date(start.getTime() - start.getTimezoneOffset() * 60000));
    to.value = iso(new Date(now.getTime() - now.getTimezoneOffset() * 60000));
    apply();
}
const exportUrl = computed(() => route('library.manage.reports.export', { from: props.filters.from, to: props.filters.to }));
const printPage = () => window.print();

const cards = computed(() => [
    { label: t('library.rep.loans', 'إعارات الفترة'), value: num(props.totals.loans) },
    { label: t('library.rep.returns', 'إرجاعات الفترة'), value: num(props.totals.returns) },
    { label: t('library.rep.readers', 'قرّاء استعاروا'), value: num(props.totals.readers) },
    { label: t('library.rep.titles', 'عناوين استُعيرت'), value: num(props.totals.titles) },
    { label: t('library.rep.late_returns', 'أُرجعت متأخرة'), value: num(props.lateReturns), tone: 'text-amber-600' },
    { label: t('library.rep.active_now', 'مُعارة الآن'), value: num(props.totals.active_now) },
    { label: t('library.rep.overdue_now', 'متأخرة الآن'), value: num(props.totals.overdue_now), tone: 'text-red-600', href: route('library.manage.loans', { filter: 'overdue' }) },
    { label: t('library.rep.fines_collected', 'غرامات مستوفاة'), value: money(props.totals.fines_collected), tone: 'text-emerald-600' },
    { label: t('library.rep.fines_due', 'غرامات غير مدفوعة'), value: money(props.totals.fines_due), tone: 'text-red-600', href: route('library.manage.loans', { filter: 'fines' }) },
    { label: t('library.rep.fines_waived', 'غرامات معفاة'), value: money(props.totals.fines_waived) },
    { label: t('library.rep.lost', 'نسخ مفقودة'), value: num(props.totals.lost), tone: 'text-red-600' },
    { label: t('library.rep.damaged', 'نسخ تالفة'), value: num(props.totals.damaged), tone: 'text-amber-600' },
]);

const typeLabel = (type) => ({ student: t('library.type.student', 'طالب'), teacher: t('library.type.teacher', 'تدريسي'), staff: t('library.type.staff', 'موظف') })[type] ?? type;
const typeRows = computed(() => Object.entries(props.byType ?? {}).map(([k, v]) => ({ name: typeLabel(k), count: v })).sort((a, b) => b.count - a.count));
const maxOf = (rows) => Math.max(1, ...rows.map((r) => r.count));

const canvas = ref(null);
let chart = null;
function renderChart() {
    chart?.destroy();
    if (!canvas.value) return;
    const dark = document.documentElement.classList.contains('dark');
    const grid = dark ? '#374151' : '#e5e7eb';
    const text = dark ? '#d1d5db' : '#4b5563';
    chart = new Chart(canvas.value, {
        type: 'bar',
        data: {
            labels: props.monthly.map((m) => monthLabel(m.month)),
            datasets: [
                { label: t('library.rep.loans_series', 'إعارات'), data: props.monthly.map((m) => m.loans), backgroundColor: '#4f46e5', borderRadius: 4 },
                { label: t('library.rep.returns_series', 'إرجاعات'), data: props.monthly.map((m) => m.returns), backgroundColor: '#10b981', borderRadius: 4 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { color: text } } },
            scales: {
                x: { ticks: { color: text }, grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0, color: text }, grid: { color: grid } },
            },
        },
    });
}
onMounted(renderChart);
onBeforeUnmount(() => chart?.destroy());
watch(() => props.monthly, renderChart);
</script>

<template>
    <Head :title="t('library.tab.reports', 'التقارير')" />

    <AppLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('library.tab.reports', 'تقارير المكتبة') }}</h2>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <LibraryTabs class="print:hidden" />

            <div class="flex flex-wrap items-end gap-2 print:hidden">
                <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('library.rep.from', 'من') }}</span>
                    <input type="date" v-model="from" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('library.rep.to', 'إلى') }}</span>
                    <input type="date" v-model="to" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                <button @click="apply()" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white">{{ t('action.apply', 'عرض') }}</button>
                <div class="flex flex-wrap gap-1">
                    <button v-for="q in [['month', t('library.rep.q_month', 'هذا الشهر')], ['quarter', t('library.rep.q_quarter', 'آخر 3 أشهر')], ['year', t('library.rep.q_year', 'هذه السنة')], ['twelve', t('library.rep.q_twelve', 'آخر 12 شهراً')]]" :key="q[0]" @click="quick(q[0])"
                        class="rounded-full border border-gray-300 px-3 py-1.5 text-xs hover:bg-gray-100 dark:border-gray-600 dark:hover:bg-gray-700">{{ t('library.rep.q_' + q[0], q[1]) }}</button>
                </div>
                <div class="ms-auto flex gap-2">
                    <a :href="exportUrl" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><ArrowDownTrayIcon class="h-4 w-4" /> Excel (CSV)</a>
                    <button @click="printPage" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><PrinterIcon class="h-4 w-4" /> {{ t('action.print', 'طباعة') }}</button>
                </div>
            </div>

            <h3 class="hidden text-lg font-bold print:block">{{ t('library.rep.title', 'تقرير المكتبة') }}: {{ t('library.rep.from', 'من') }} {{ filters.from }} {{ t('library.rep.to', 'إلى') }} {{ filters.to }}</h3>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <component :is="c.href ? Link : 'div'" v-for="c in cards" :key="c.label" :href="c.href"
                    class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800" :class="c.href ? 'hover:border-[var(--accent-400)]' : ''">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ c.label }}</p>
                    <p class="mt-1 text-xl font-bold" :class="c.tone ?? 'text-gray-800 dark:text-gray-100'">{{ c.value }}</p>
                </component>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <h4 class="mb-3 text-sm font-semibold">{{ t('library.rep.monthly', 'الإعارات والإرجاعات شهرياً') }}</h4>
                <div class="h-64"><canvas ref="canvas" /></div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <h4 class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold dark:border-gray-700">{{ t('library.rep.top_books', 'الأكثر استعارة') }}</h4>
                    <p v-if="!topBooks.length" class="p-4 text-sm text-gray-400">{{ t('library.rep.none', 'لا بيانات في هذه الفترة.') }}</p>
                    <Link v-for="(b, i) in topBooks" :key="b.id" :href="route('library.books.show', b.id)" class="flex items-center gap-3 border-b border-gray-50 px-4 py-2 text-sm last:border-0 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700">
                        <span class="w-5 text-center text-xs text-gray-400">{{ i + 1 }}</span>
                        <div class="min-w-0 flex-1"><p class="truncate">{{ b.title }}</p><p v-if="b.authors" class="truncate text-xs text-gray-500">{{ b.authors }}</p></div>
                        <span class="font-semibold">{{ b.count }}</span>
                    </Link>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <h4 class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold dark:border-gray-700">{{ t('library.rep.top_readers', 'أكثر القرّاء استعارة') }}</h4>
                    <p v-if="!topReaders.length" class="p-4 text-sm text-gray-400">{{ t('library.rep.none', 'لا بيانات في هذه الفترة.') }}</p>
                    <div v-for="(r, i) in topReaders" :key="r.card" class="flex items-center gap-3 border-b border-gray-50 px-4 py-2 text-sm last:border-0 dark:border-gray-700">
                        <span class="w-5 text-center text-xs text-gray-400">{{ i + 1 }}</span>
                        <div class="min-w-0 flex-1"><p class="truncate">{{ r.name }}</p><p class="text-xs text-gray-500" dir="ltr">{{ r.card }}</p></div>
                        <span class="font-semibold">{{ r.count }}</span>
                    </div>
                </div>

                <div v-for="block in [{ title: t('library.rep.by_section', 'الإعارات حسب القسم'), rows: bySection }, { title: t('library.rep.by_type', 'الإعارات حسب نوع القارئ'), rows: typeRows }]" :key="block.title"
                    class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <h4 class="mb-3 text-sm font-semibold">{{ block.title }}</h4>
                    <p v-if="!block.rows.length" class="text-sm text-gray-400">{{ t('library.rep.none', 'لا بيانات في هذه الفترة.') }}</p>
                    <div v-for="row in block.rows" :key="row.name" class="mb-2 text-sm">
                        <div class="flex justify-between"><span>{{ row.name }}</span><span class="font-semibold">{{ row.count }}</span></div>
                        <div class="mt-1 h-2 rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-2 rounded-full bg-[var(--accent-500)]" :style="{ width: (row.count / maxOf(block.rows)) * 100 + '%' }" />
                        </div>
                    </div>
                </div>
            </div>

            <p class="text-xs text-gray-400">{{ t('library.rep.collection', 'المجموعة: :b عنوان، :c نسخة، :m قارئ مسجّل.', { b: num(totals.books), c: num(totals.copies), m: num(totals.members) }) }}</p>
        </div>
    </AppLayout>
</template>
