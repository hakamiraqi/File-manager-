<script setup>
// «التعرف على النصوص» (2026-11-06, by request): scanned images and PDFs are read one by one in
// the hours the admin picks, and the work pauses whenever the computer is busy.
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsSection from '@/Components/SettingsSection.vue';
import SettingToggle from '@/Components/SettingToggle.vue';
import NumberStepper from '@/Components/NumberStepper.vue';
import StickySaveBar from '@/Components/StickySaveBar.vue';
import Pagination from '@/Components/Pagination.vue';
import FileIcon from '@/Components/FileIcon.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    DocumentMagnifyingGlassIcon, ClockIcon, CpuChipIcon, ServerStackIcon, UsersIcon, CircleStackIcon,
    PlayIcon, PauseIcon, MoonIcon, ExclamationTriangleIcon, CheckCircleIcon, ArrowPathIcon, ForwardIcon,
    NoSymbolIcon, QueueListIcon, MagnifyingGlassIcon, BoltIcon, ChartBarIcon, AdjustmentsHorizontalIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({
    settings: Object, engine: Object, counts: Object, state: Object, chart: Array, week: Array, files: Object, filters: Object,
});

const DAYS = [
    [6, 'السبت'], [0, 'الأحد'], [1, 'الاثنين'], [2, 'الثلاثاء'], [3, 'الأربعاء'], [4, 'الخميس'], [5, 'الجمعة'],
];
const list = (v) => (v || '').split(',').filter((x) => x !== '').map(Number);

const form = useForm({
    ocr_enabled: props.settings.ocr_enabled === '1',
    ocr_report: props.settings.ocr_report === '1',
    ocr_days: list(props.settings.ocr_days),
    ocr_all_day_days: list(props.settings.ocr_all_day_days),
    ocr_start: props.settings.ocr_start,
    ocr_end: props.settings.ocr_end,
    ocr_batch: Number(props.settings.ocr_batch),
    ocr_languages: props.settings.ocr_languages,
    ocr_max_cpu: Number(props.settings.ocr_max_cpu),
    ocr_min_ram_mb: Number(props.settings.ocr_min_ram_mb),
    ocr_min_disk_gb: Number(props.settings.ocr_min_disk_gb),
    ocr_max_users: Number(props.settings.ocr_max_users),
    ocr_timeout_minutes: Number(props.settings.ocr_timeout_minutes),
    ocr_max_pages: Number(props.settings.ocr_max_pages),
    ocr_max_mb: Number(props.settings.ocr_max_mb),
});

function toggleDay(field, d) {
    const i = form[field].indexOf(d);
    i === -1 ? form[field].push(d) : form[field].splice(i, 1);
}

function submit() {
    form.post(route('admin.ocr.update'), { preserveScroll: true, onSuccess: () => form.defaults() });
}

function post(name, params = {}) {
    router.post(route(name, params), {}, { preserveScroll: true });
}

const STATE = {
    running: { label: 'يعمل الآن', icon: PlayIcon, cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:ring-emerald-800' },
    idle: { label: 'لا توجد ملفات بالانتظار', icon: CheckCircleIcon, cls: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-900/30 dark:text-sky-300 dark:ring-sky-800' },
    outside_hours: { label: 'خارج وقت العمل، ينتظر', icon: MoonIcon, cls: 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-300 dark:ring-indigo-800' },
    busy: { label: 'متوقف مؤقتاً لأن الحاسوب مشغول', icon: PauseIcon, cls: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-800' },
    disabled: { label: 'متوقف (غير مفعّل)', icon: NoSymbolIcon, cls: 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600' },
    no_engine: { label: 'برنامج التعرف غير مثبت', icon: ExclamationTriangleIcon, cls: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-800' },
};
const last = computed(() => props.state.last);
const stateInfo = computed(() => STATE[last.value?.state] || { label: 'لم يبدأ بعد', icon: ClockIcon, cls: STATE.disabled.cls });

const fmt = (iso) => (iso ? new Date(iso).toLocaleString('ar-IQ', { timeZone: 'Asia/Baghdad', weekday: 'long', hour: '2-digit', minute: '2-digit' }) : '');
const ago = (iso) => {
    if (!iso) return '';
    const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    return m < 1 ? 'الآن' : m < 60 ? `قبل ${m} د` : `قبل ${Math.round(m / 60)} س`;
};

const total = computed(() => props.counts.pending + props.counts.processing + props.counts.done + props.counts.failed + props.counts.skipped);
const progress = computed(() => (total.value ? Math.round((props.counts.done / total.value) * 100) : 0));
const eta = computed(() => {
    const spp = props.state.seconds_per_page;
    if (!spp || !props.counts.pending) return null;
    const hours = (spp * props.counts.pending * 3) / 3600; // ~3 pages per file on average
    return hours < 1 ? 'أقل من ساعة' : `قرابة ${Math.ceil(hours)} ساعة عمل`;
});

const load = computed(() => props.state.load || {});
const meters = computed(() => [
    { icon: CpuChipIcon, label: 'المعالج', value: load.value.cpu == null ? '—' : `${load.value.cpu}%`, limit: `الحد ${form.ocr_max_cpu}%`, bad: load.value.cpu != null && form.ocr_max_cpu > 0 && load.value.cpu > form.ocr_max_cpu, pct: load.value.cpu },
    { icon: ServerStackIcon, label: 'الذاكرة الحرة', value: load.value.ram_free_mb == null ? '—' : `${(load.value.ram_free_mb / 1024).toFixed(1)} غيغا`, limit: `الحد الأدنى ${(form.ocr_min_ram_mb / 1024).toFixed(1)} غيغا`, bad: load.value.ram_free_mb != null && load.value.ram_free_mb < form.ocr_min_ram_mb },
    { icon: CircleStackIcon, label: 'مساحة القرص الحرة', value: load.value.disk_free_gb == null ? '—' : `${load.value.disk_free_gb} غيغا`, limit: `الحد الأدنى ${form.ocr_min_disk_gb} غيغا`, bad: load.value.disk_free_gb != null && load.value.disk_free_gb < form.ocr_min_disk_gb },
    { icon: UsersIcon, label: 'المستخدمون الآن', value: load.value.active_users ?? 0, limit: `الحد ${form.ocr_max_users}`, bad: form.ocr_max_users > 0 && load.value.active_users > form.ocr_max_users },
]);

// Load chart: processor line, users bars, shaded columns where files were processed.
const W = 720, H = 160;
const chartPts = computed(() => props.chart || []);
const x = (i) => (chartPts.value.length <= 1 ? 0 : (i / (chartPts.value.length - 1)) * W);
const cpuPath = computed(() => chartPts.value.map((p, i) => (p.cpu == null ? null : `${x(i)},${H - (p.cpu / 100) * H}`)).filter(Boolean).join(' '));
const maxUsers = computed(() => Math.max(5, ...chartPts.value.map((p) => p.users)));
const limitY = computed(() => H - (form.ocr_max_cpu / 100) * H);
const hover = ref(null);

const weekMax = computed(() => Math.max(1, ...props.week.map((d) => d.done)));
const dayName = (iso) => new Date(iso + 'T12:00:00').toLocaleDateString('ar-IQ', { weekday: 'short' });

const TABS = computed(() => [
    ['pending', 'بالانتظار', props.counts.pending],
    ['processing', 'قيد المعالجة', props.counts.processing],
    ['done', 'أُنجزت', props.counts.done],
    ['failed', 'تعذرت', props.counts.failed],
    ['skipped', 'مستبعدة', props.counts.skipped],
    ['all', 'الكل', total.value],
]);
const search = ref(props.filters.q);
function filter(status) {
    router.get(route('admin.ocr.index'), { status, q: search.value || undefined }, { preserveScroll: true, preserveState: true });
}
const STATUS_PILL = {
    pending: 'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300',
    processing: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300',
    done: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    failed: 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    skipped: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
};
const STATUS_LABEL = { pending: 'بالانتظار', processing: 'قيد المعالجة', done: 'أُنجز', failed: 'تعذر', skipped: 'مستبعد' };
</script>

<template>
    <Head :title="t('admin.nav.ocr', 'التعرف على النصوص')" />

    <AdminLayout>
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-gray-100">
                <DocumentMagnifyingGlassIcon class="h-5 w-5 text-gray-500" />
                {{ t('ocr.title', 'التعرف على النصوص من الصور وملفات PDF الممسوحة') }}
                <Link v-if="$page.props.auth.user?.is_master_admin" :href="route('admin.qari.index')" class="ms-auto rounded-lg bg-indigo-50 px-3 py-1 text-sm font-medium text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/40 dark:text-indigo-200">
                    تجربة Qari للعربي
                </Link>
            </h2>
        </template>

        <div class="mx-auto max-w-5xl space-y-6 px-4 pt-4 sm:px-6 sm:pt-6">
            <!-- Status -->
            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 space-y-2">
                        <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold ring-1" :class="stateInfo.cls">
                            <component :is="stateInfo.icon" class="h-4 w-4" />
                            {{ stateInfo.label }}
                        </span>
                        <p v-if="last?.reason" class="text-sm text-amber-700 dark:text-amber-300">{{ last.reason }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            <template v-if="state.in_window">داخل وقت العمل الآن.</template>
                            <template v-else-if="state.next_start">يبدأ وقت العمل القادم {{ fmt(state.next_start) }}.</template>
                            <template v-if="last"> آخر فحص {{ ago(last.at) }}<template v-if="last.processed">، عالج {{ last.processed }} ملف</template>.</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="post('admin.ocr.run')" :disabled="!engine.available || !counts.pending" class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--accent-600)] px-3 py-2 text-sm font-semibold text-white hover:bg-[var(--accent-700)] disabled:opacity-40">
                            <BoltIcon class="h-4 w-4" /> شغّل دفعة الآن
                        </button>
                        <button type="button" @click="post('admin.ocr.queue')" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <QueueListIcon class="h-4 w-4" /> جرد الملفات الآن
                            <span v-if="counts.new" class="rounded-full bg-amber-100 px-1.5 text-xs text-amber-700">{{ counts.new }}</span>
                        </button>
                    </div>
                </div>

                <div class="mt-5">
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-200">أُنجز {{ counts.done }} من {{ total }} ملف</span>
                        <span class="text-gray-500">{{ progress }}%<template v-if="eta"> · المتبقي {{ eta }}</template></span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: progress + '%' }" />
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div v-for="m in meters" :key="m.label" class="rounded-xl border p-3" :class="m.bad ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20' : 'border-gray-100 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/40'">
                        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"><component :is="m.icon" class="h-4 w-4" />{{ m.label }}</div>
                        <div class="mt-1 text-lg font-bold" :class="m.bad ? 'text-amber-700 dark:text-amber-300' : 'text-gray-900 dark:text-white'">{{ m.value }}</div>
                        <div class="text-[11px] text-gray-400">{{ m.limit }}</div>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5" :class="engine.available ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-red-50 text-red-700'">
                        <CheckCircleIcon v-if="engine.available" class="h-3.5 w-3.5" /><ExclamationTriangleIcon v-else class="h-3.5 w-3.5" />
                        Tesseract {{ engine.version || (engine.available ? '' : 'غير مثبت') }}
                    </span>
                    <span v-if="engine.languages.length" class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-gray-700 dark:text-gray-300" dir="ltr">{{ engine.languages.join(' · ') }}</span>
                    <span class="rounded-full px-2 py-0.5" :class="engine.pdf ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' : 'bg-amber-50 text-amber-700'">
                        {{ engine.pdf ? 'يقرأ ملفات PDF الممسوحة' : 'قراءة PDF غير متاحة (pdftoppm غير مثبت)' }}
                    </span>
                    <span v-if="state.seconds_per_page" class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-gray-700 dark:text-gray-300">قرابة {{ state.seconds_per_page }} ثانية للصفحة</span>
                </div>
            </section>

            <!-- Load chart -->
            <SettingsSection :icon="ChartBarIcon" title="حمل الحاسوب خلال آخر 24 ساعة" description="الخط: استخدام المعالج. الأعمدة: عدد المستخدمين. الخلفية الخضراء: أوقات المعالجة. الخط المتقطع: حد المعالج." tone="sky">
                <div v-if="chartPts.length < 2" class="py-8 text-center text-sm text-gray-500">تظهر البيانات بعد أول ساعة من التشغيل.</div>
                <div v-else class="relative">
                    <svg :viewBox="`0 0 ${W} ${H + 18}`" class="h-48 w-full" preserveAspectRatio="none" @mouseleave="hover = null">
                        <g v-for="(p, i) in chartPts" :key="'b' + i">
                            <rect v-if="p.running" :x="x(i) - W / chartPts.length / 2" y="0" :width="W / chartPts.length" :height="H" class="fill-emerald-100 dark:fill-emerald-900/40" />
                            <rect :x="x(i) - 1.5" :y="H - (p.users / maxUsers) * H * 0.5" width="3" :height="(p.users / maxUsers) * H * 0.5" class="fill-indigo-300/70 dark:fill-indigo-500/60" />
                            <rect :x="x(i) - W / chartPts.length / 2" y="0" :width="W / chartPts.length" :height="H" fill="transparent" @mouseenter="hover = i" />
                        </g>
                        <line x1="0" :x2="W" :y1="limitY" :y2="limitY" stroke-dasharray="6 4" class="stroke-amber-500" stroke-width="1" />
                        <polyline :points="cpuPath" fill="none" class="stroke-[var(--accent-600)]" stroke-width="2" vector-effect="non-scaling-stroke" />
                        <line v-if="hover !== null" :x1="x(hover)" :x2="x(hover)" y1="0" :y2="H" class="stroke-gray-400" stroke-width="1" />
                        <text v-for="(p, i) in chartPts" v-show="i % 18 === 0" :key="'t' + i" :x="x(i)" :y="H + 14" text-anchor="middle" class="fill-gray-400 text-[10px]">{{ p.t }}</text>
                    </svg>
                    <div v-if="hover !== null" class="pointer-events-none absolute top-0 rounded-lg bg-gray-900/90 px-2.5 py-1.5 text-xs text-white" :style="{ insetInlineStart: '8px' }">
                        {{ chartPts[hover].t }} · المعالج {{ chartPts[hover].cpu ?? '—' }}% · المستخدمون {{ chartPts[hover].users }} · عولج {{ chartPts[hover].processed }}
                    </div>
                </div>
                <div class="mt-4 flex items-end gap-2">
                    <div v-for="d in week" :key="d.day" class="flex flex-1 flex-col items-center gap-1">
                        <span class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ d.done }}</span>
                        <div class="w-full rounded-t-md bg-emerald-400/80" :style="{ height: Math.max(3, (d.done / weekMax) * 64) + 'px' }" />
                        <span class="text-[11px] text-gray-500">{{ dayName(d.day) }}</span>
                    </div>
                </div>
            </SettingsSection>

            <!-- Files -->
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 p-3 dark:border-gray-700">
                    <button v-for="tab in TABS" :key="tab[0]" type="button" @click="filter(tab[0])"
                        class="rounded-full px-3 py-1.5 text-sm font-medium"
                        :class="filters.status === tab[0] ? 'bg-[var(--accent-600)] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200'">
                        {{ tab[1] }} <span class="opacity-75">{{ tab[2] }}</span>
                    </button>
                    <form @submit.prevent="filter(filters.status)" class="ms-auto flex items-center gap-1 rounded-full bg-gray-100 px-3 dark:bg-gray-700">
                        <MagnifyingGlassIcon class="h-4 w-4 text-gray-400" />
                        <input v-model="search" type="search" placeholder="اسم الملف" class="w-40 border-0 bg-transparent py-1.5 text-sm focus:ring-0 dark:text-white" />
                    </form>
                    <button v-if="counts.failed" type="button" @click="post('admin.ocr.retry-failed')" class="inline-flex items-center gap-1 rounded-full border border-red-200 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300">
                        <ArrowPathIcon class="h-4 w-4" /> أعد كل المتعذرة
                    </button>
                </div>
                <div v-if="!files.data.length" class="p-10 text-center text-sm text-gray-500">لا توجد ملفات هنا.</div>
                <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="f in files.data" :key="f.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <FileIcon :extension="f.extension" class="h-8 w-8 shrink-0" />
                        <div class="min-w-0 flex-1">
                            <Link :href="route('dashboard', f.folder_id ? { folder: f.folder_id } : {})" class="block truncate font-medium text-gray-900 hover:underline dark:text-white">{{ f.name }}</Link>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ f.folder || 'المجلد الرئيسي' }} · {{ f.size }}
                                <template v-if="f.pages"> · {{ f.pages }} صفحة</template>
                                <template v-if="f.seconds"> · {{ f.seconds }} ث</template>
                                <template v-if="f.chars"> · {{ f.chars.toLocaleString('ar-IQ') }} حرف</template>
                                <template v-if="f.attempts && f.status !== 'done'"> · المحاولة {{ f.attempts }}</template>
                            </p>
                            <p v-if="f.error" class="truncate text-xs" :class="f.status === 'done' ? 'text-gray-400' : 'text-red-600 dark:text-red-400'">{{ f.error }}</p>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="STATUS_PILL[f.status]">{{ STATUS_LABEL[f.status] }}</span>
                        <div class="flex items-center gap-1">
                            <button v-if="f.status === 'pending'" type="button" title="عالجه أولاً" @click="post('admin.ocr.file', { file: f.id, action: 'prioritize' })" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700"><ForwardIcon class="h-4 w-4" /></button>
                            <button v-if="['failed', 'skipped', 'done'].includes(f.status)" type="button" title="أعد المعالجة" @click="post('admin.ocr.file', { file: f.id, action: 'retry' })" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700"><ArrowPathIcon class="h-4 w-4" /></button>
                            <button v-if="['pending', 'failed'].includes(f.status)" type="button" title="استبعد" @click="post('admin.ocr.file', { file: f.id, action: 'skip' })" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700"><NoSymbolIcon class="h-4 w-4" /></button>
                        </div>
                    </li>
                </ul>
                <div class="border-t border-gray-100 p-3 dark:border-gray-700"><Pagination :links="files.links" /></div>
            </section>

            <!-- Settings -->
            <form @submit.prevent="submit" class="space-y-6">
                <SettingsSection :icon="ClockIcon" title="وقت العمل" description="يعمل التعرف ملفاً بعد ملف خلال هذه الأوقات فقط (بتوقيت بغداد)، ويتوقف وحده عند نهايتها." tone="accent">
                    <SettingToggle v-model="form.ocr_enabled" description="عند الإيقاف لا يُعالج أي ملف، ويبقى الجرد محفوظاً.">تفعيل التعرف على النصوص</SettingToggle>
                    <div class="mt-4 grid grid-cols-2 gap-4 sm:max-w-sm">
                        <label class="text-sm text-gray-700 dark:text-gray-200">من الساعة
                            <input v-model="form.ocr_start" type="time" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </label>
                        <label class="text-sm text-gray-700 dark:text-gray-200">إلى الساعة
                            <input v-model="form.ocr_end" type="time" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">إذا كانت النهاية قبل البداية فالوقت يمتد لصباح اليوم التالي. نفس الساعة للبداية والنهاية تعني اليوم كله.</p>
                    <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-200">الأيام</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button v-for="d in DAYS" :key="'d' + d[0]" type="button" @click="toggleDay('ocr_days', d[0])" class="rounded-full px-3 py-1.5 text-sm" :class="form.ocr_days.includes(d[0]) ? 'bg-[var(--accent-600)] text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">{{ d[1] }}</button>
                    </div>
                    <p class="mt-4 text-sm font-medium text-gray-700 dark:text-gray-200">أيام العطل (يعمل طوال اليوم)</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button v-for="d in DAYS" :key="'a' + d[0]" type="button" @click="toggleDay('ocr_all_day_days', d[0])" class="rounded-full px-3 py-1.5 text-sm" :class="form.ocr_all_day_days.includes(d[0]) ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">{{ d[1] }}</button>
                    </div>
                    <p v-if="form.errors.ocr_start || form.errors.ocr_end" class="mt-2 text-sm text-red-600">{{ form.errors.ocr_start || form.errors.ocr_end }}</p>
                </SettingsSection>

                <SettingsSection :icon="CpuChipIcon" title="حماية الحاسوب" description="قبل كل ملف يُفحص الحمل، وإذا تجاوز أي حد يتوقف التعرف مؤقتاً ويعود لوحده عندما يهدأ الحاسوب. صفر يعني بلا حد." tone="amber">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">أقصى استخدام للمعالج</p><NumberStepper v-model="form.ocr_max_cpu" :min="0" :max="100" :step="5" unit="%" /></div>
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">أقل ذاكرة حرة</p><NumberStepper v-model="form.ocr_min_ram_mb" :min="0" :max="65536" :step="256" unit="ميغا" /></div>
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">أقل مساحة حرة على القرص</p><NumberStepper v-model="form.ocr_min_disk_gb" :min="0" :max="10000" unit="غيغا" /></div>
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">أقصى عدد مستخدمين متصلين</p><NumberStepper v-model="form.ocr_max_users" :min="0" :max="10000" unit="مستخدم" /></div>
                    </div>
                </SettingsSection>

                <SettingsSection :icon="AdjustmentsHorizontalIcon" title="طريقة المعالجة" tone="green">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">ملفات في كل دفعة</p><NumberStepper v-model="form.ocr_batch" :min="1" :max="500" unit="ملف" /></div>
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">أقصى وقت للملف الواحد</p><NumberStepper v-model="form.ocr_timeout_minutes" :min="1" :max="120" unit="دقيقة" /></div>
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">أقصى عدد صفحات من كل PDF</p><NumberStepper v-model="form.ocr_max_pages" :min="1" :max="5000" :step="50" unit="صفحة" /></div>
                        <div><p class="mb-1 text-sm text-gray-700 dark:text-gray-200">أقصى حجم للملف</p><NumberStepper v-model="form.ocr_max_mb" :min="1" :max="5000" :step="10" unit="ميغا" /></div>
                        <label class="text-sm text-gray-700 dark:text-gray-200">لغة النصوص
                            <select v-model="form.ocr_languages" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option value="ara+eng">العربية والإنكليزية</option>
                                <option value="ara">العربية فقط</option>
                                <option value="eng">الإنكليزية فقط</option>
                            </select>
                        </label>
                    </div>
                    <div class="mt-4"><SettingToggle v-model="form.ocr_report" description="إشعار للمدراء كل صباح الساعة 7:30 بعدد ما أُنجز وما تبقى.">تقرير الصباح</SettingToggle></div>
                </SettingsSection>

                <StickySaveBar :form="form" label="حفظ إعدادات التعرف" @save="submit" @reset="form.reset()" />
            </form>
        </div>
    </AdminLayout>
</template>
