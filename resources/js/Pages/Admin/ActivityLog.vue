<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    FunnelIcon, MagnifyingGlassIcon, ArrowDownTrayIcon, XMarkIcon, ArrowUpTrayIcon, PencilSquareIcon,
    BookOpenIcon, ShareIcon, TrashIcon, Cog6ToothIcon, FilmIcon, EllipsisHorizontalCircleIcon,
    ComputerDesktopIcon, DevicePhoneMobileIcon, ChevronDownIcon, FolderIcon, DocumentIcon, ClockIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    logs: Object,
    filters: Object,
    period: { type: Object, default: null },
    kindCounts: { type: Object, default: () => ({}) },
    labels: { type: Object, default: () => ({}) },
    kinds: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    shiftStart: { type: String, default: '08:00' },
});

const { t } = useTrans();

const PRESETS = [
    ['all', t('activity.p.all', 'كل الوقت')],
    ['today', t('analytics.p.today', 'اليوم')],
    ['shift', t('analytics.p.shift', 'من بداية الدوام')],
    ['yesterday', t('analytics.p.yesterday', 'أمس')],
    ['this_week', t('analytics.p.this_week', 'هذا الأسبوع')],
    ['last_week', t('analytics.p.last_week', 'الأسبوع الماضي')],
    ['this_month', t('analytics.p.this_month', 'هذا الشهر')],
    ['last_month', t('analytics.p.last_month', 'الشهر الماضي')],
    ['last_7', t('analytics.p.last_7', 'آخر 7 أيام')],
    ['last_30', t('analytics.p.last_30', 'آخر 30 يوماً')],
    ['custom', t('analytics.p.custom', 'فترة مخصصة')],
];

const KINDS = {
    all: { label: t('activity.k.all', 'الكل'), icon: FunnelIcon, tone: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' },
    uploads: { label: t('analytics.cat.uploads', 'الرفع'), icon: ArrowUpTrayIcon, tone: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300' },
    data_entry: { label: t('analytics.cat.data_entry', 'إدخال البيانات'), icon: PencilSquareIcon, tone: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300' },
    circulation: { label: t('analytics.cat.circulation', 'الإعارة'), icon: BookOpenIcon, tone: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' },
    sharing: { label: t('analytics.cat.sharing', 'المشاركة'), icon: ShareIcon, tone: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' },
    downloads: { label: t('analytics.cat.downloads', 'التنزيل'), icon: ArrowDownTrayIcon, tone: 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300' },
    deletions: { label: t('analytics.cat.deletions', 'الحذف'), icon: TrashIcon, tone: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' },
    admin: { label: t('activity.k.admin', 'الإدارة'), icon: Cog6ToothIcon, tone: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' },
    media: { label: t('activity.k.media', 'الوسائط والنسخ'), icon: FilmIcon, tone: 'bg-pink-100 text-pink-700 dark:bg-pink-500/15 dark:text-pink-300' },
    other: { label: t('activity.k.other', 'أخرى'), icon: EllipsisHorizontalCircleIcon, tone: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' },
};
const ROLE_LABELS = { admin: t('role.admin', 'مدير'), editor: t('role.editor', 'محرّر'), contributor: t('role.contributor', 'مساهم'), user: t('role.user', 'مستخدم') };

const f = reactive({});
const showMore = ref(false);
function sync(v) {
    Object.assign(f, {
        preset: v.preset ?? 'all', from: v.from ?? '', to: v.to ?? '', user: v.user ?? '', role: v.role ?? '',
        kind: v.kind ?? '', action: v.action ?? '', q: v.q ?? '',
    });
}
sync(props.filters);
watch(() => props.filters, sync);

function query() {
    const q = {};
    if (f.preset !== 'all') q.preset = f.preset;
    if (f.preset === 'custom') Object.assign(q, { from: f.from, to: f.to });
    for (const k of ['user', 'role', 'kind', 'action', 'q']) if (f[k]) q[k] = f[k];
    return q;
}
function apply() {
    router.get(route('admin.activity.index'), query(), { preserveState: true, preserveScroll: true, replace: true });
}
function pick(preset) {
    f.preset = preset;
    if (preset !== 'custom') apply();
}
function pickKind(kind) {
    f.kind = kind === 'all' ? '' : kind;
    f.action = '';
    apply();
}
let timer;
watch(() => f.q, (v, old) => {
    if (v === (props.filters.q ?? '') || v === old) return;
    clearTimeout(timer);
    timer = setTimeout(apply, 400);
});
function reset() {
    sync({});
    apply();
}
const activeCount = computed(() => ['user', 'role', 'kind', 'action', 'q'].filter((k) => f[k]).length + (f.preset !== 'all' ? 1 : 0));
const exportUrl = computed(() => route('admin.activity.export', query()));

// Actions offered in the detail select: those of the chosen kind, or all.
const actionOptions = computed(() => Object.entries(props.labels).sort((a, b) => a[1].localeCompare(b[1], 'ar')));

// Group the page's entries by Baghdad day.
const dayFmt = new Intl.DateTimeFormat('ar-IQ', { timeZone: 'Asia/Baghdad', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const keyFmt = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Baghdad' });
const timeFmt = new Intl.DateTimeFormat('ar-IQ', { timeZone: 'Asia/Baghdad', hour: 'numeric', minute: '2-digit' });
const todayKey = keyFmt.format(new Date());
const yesterdayKey = keyFmt.format(new Date(Date.now() - 86400000));
const groups = computed(() => {
    const out = [];
    for (const log of props.logs.data) {
        const d = new Date(log.created_at);
        const key = keyFmt.format(d);
        let g = out[out.length - 1];
        if (!g || g.key !== key) {
            g = { key, title: key === todayKey ? t('activity.today', 'اليوم') : key === yesterdayKey ? t('activity.yesterday', 'أمس') : dayFmt.format(d), sub: key === todayKey || key === yesterdayKey ? dayFmt.format(d) : '', items: [] };
            out.push(g);
        }
        g.items.push(log);
    }
    return out;
});

const open = ref(null);
function device(agent) {
    if (!agent) return null;
    const mobile = /Mobile|Android|iPhone|iPad/i.test(agent);
    const browser = /Edg\//.test(agent) ? 'Edge' : /OPR\//.test(agent) ? 'Opera' : /Chrome\//.test(agent) ? 'Chrome' : /Firefox\//.test(agent) ? 'Firefox' : /Safari\//.test(agent) ? 'Safari' : /Dart|okhttp|FileNest/i.test(agent) ? t('activity.app', 'التطبيق') : '';
    const os = /Windows/.test(agent) ? 'Windows' : /iPhone|iPad|iOS/.test(agent) ? 'iOS' : /Android/.test(agent) ? 'Android' : /Mac OS/.test(agent) ? 'macOS' : /Linux/.test(agent) ? 'Linux' : '';
    return { mobile, text: [browser, os].filter(Boolean).join(' · ') };
}
function subjectLink(s) {
    if (!s) return null;
    if (s.type === 'folder') return route('dashboard', { folder: s.id });
    if (s.type === 'file') return route('dashboard', s.folder_id ? { folder: s.folder_id } : {});
    return null;
}
const nf = new Intl.NumberFormat('ar-IQ');
</script>

<template>
    <Head :title="t('admin.nav.activity', 'سجل النشاطات')" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('admin.nav.activity', 'سجل النشاطات') }}</h2>
        </template>

        <div class="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
            <!-- Filters -->
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
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

                <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
                    <div class="relative">
                        <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <input v-model="f.q" type="search" :placeholder="t('activity.search', 'ابحث في التفاصيل أو الاسم أو IP')" class="w-full rounded-lg border-gray-300 ps-9 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                    </div>
                    <select v-model="f.user" @change="apply" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">{{ t('analytics.all_staff', 'كل الموظفين') }}</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select v-model="f.role" @change="apply" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">{{ t('analytics.all_roles', 'كل الأدوار') }}</option>
                        <option v-for="r in roles" :key="r" :value="r">{{ ROLE_LABELS[r] ?? r }}</option>
                    </select>
                    <button type="button" class="inline-flex items-center justify-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 dark:border-gray-600 dark:text-gray-300" @click="showMore = !showMore">
                        {{ t('activity.more', 'نشاط محدد') }}<ChevronDownIcon class="h-4 w-4 transition" :class="showMore ? 'rotate-180' : ''" />
                    </button>
                </div>
                <select v-if="showMore || f.action" v-model="f.action" @change="apply" class="mt-2 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 sm:max-w-sm">
                    <option value="">{{ t('activity.any_action', 'أي نشاط') }}</option>
                    <option v-for="[key, label] in actionOptions" :key="key" :value="key">{{ label }}</option>
                </select>

                <!-- Kinds with counts -->
                <div class="-mx-1 mt-3 flex gap-2 overflow-x-auto px-1 pb-1 sm:flex-wrap">
                    <button
                        v-for="k in ['all', ...kinds]" :key="k" type="button" @click="pickKind(k)"
                        v-show="k === 'all' || kindCounts[k] || f.kind === k"
                        class="flex shrink-0 items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm transition"
                        :class="(f.kind || 'all') === k ? 'border-[var(--accent-600)] ring-1 ring-[var(--accent-600)] bg-white dark:bg-gray-800' : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-600 dark:bg-gray-800'"
                    >
                        <span :class="[KINDS[k].tone, 'flex h-6 w-6 items-center justify-center rounded-lg']"><component :is="KINDS[k].icon" class="h-3.5 w-3.5" /></span>
                        <span class="text-gray-700 dark:text-gray-200">{{ KINDS[k].label }}</span>
                        <span class="text-xs font-semibold text-gray-400">{{ nf.format(kindCounts[k] ?? 0) }}</span>
                    </button>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 text-sm dark:border-gray-700">
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ t('activity.results', 'النتائج') }}: <b class="text-gray-800 dark:text-gray-100">{{ nf.format(logs.total ?? 0) }}</b>
                        <template v-if="period"> · {{ period.from }} → {{ period.to }}</template>
                    </span>
                    <button v-if="activeCount" type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" @click="reset">
                        <XMarkIcon class="h-4 w-4" />{{ t('activity.reset', 'مسح الفلاتر') }} ({{ activeCount }})
                    </button>
                    <a :href="exportUrl" class="ms-auto inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        <ArrowDownTrayIcon class="h-4 w-4" />{{ t('activity.export', 'تصدير Excel') }}
                    </a>
                </div>
            </div>

            <!-- Empty -->
            <div v-if="!logs.data.length" class="rounded-2xl border border-dashed border-gray-300 py-16 text-center text-gray-500 dark:border-gray-700">
                <ClockIcon class="mx-auto h-10 w-10 text-gray-300" />
                <p class="mt-3">{{ t('activity.empty', 'لا توجد نشاطات بهذه الفلاتر.') }}</p>
            </div>

            <!-- Timeline grouped by day -->
            <section v-for="g in groups" :key="g.key">
                <h3 class="sticky top-0 z-10 -mx-1 mb-2 flex items-baseline gap-2 bg-gray-100/90 px-1 py-1.5 backdrop-blur dark:bg-gray-900/90">
                    <span class="font-semibold text-gray-800 dark:text-gray-100">{{ g.title }}</span>
                    <span v-if="g.sub" class="text-xs text-gray-400">{{ g.sub }}</span>
                    <span class="ms-auto text-xs text-gray-400">{{ nf.format(g.items.length) }}</span>
                </h3>
                <ol class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <li v-for="log in g.items" :key="log.id" class="border-b border-gray-100 last:border-0 dark:border-gray-700">
                        <button type="button" class="flex w-full items-start gap-3 px-3 py-3 text-start hover:bg-gray-50 dark:hover:bg-gray-700/40 sm:px-4" @click="open = open === log.id ? null : log.id">
                            <div class="relative shrink-0">
                                <UserAvatar :user="log.user ?? { name: '؟' }" size="h-10 w-10 text-sm" />
                                <span :class="[KINDS[log.kind]?.tone ?? KINDS.other.tone, 'absolute -bottom-1 -end-1 flex h-5 w-5 items-center justify-center rounded-full ring-2 ring-white dark:ring-gray-800']">
                                    <component :is="KINDS[log.kind]?.icon ?? KINDS.other.icon" class="h-3 w-3" />
                                </span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-gray-800 dark:text-gray-100">
                                    <b>{{ log.user?.name ?? t('activity.guest', 'زائر / النظام') }}</b>
                                    <span :class="[KINDS[log.kind]?.tone ?? KINDS.other.tone, 'ms-1.5 rounded-md px-1.5 py-0.5 text-xs font-medium']">{{ log.label }}</span>
                                </p>
                                <p v-if="log.description" class="mt-0.5 line-clamp-2 text-sm text-gray-500 dark:text-gray-400" dir="auto">{{ log.description }}</p>
                                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400">
                                    <span>{{ timeFmt.format(new Date(log.created_at)) }}</span>
                                    <span v-if="log.user?.role">{{ ROLE_LABELS[log.user.role] ?? log.user.role }}</span>
                                    <span v-if="device(log.agent)" class="inline-flex items-center gap-1">
                                        <component :is="device(log.agent).mobile ? DevicePhoneMobileIcon : ComputerDesktopIcon" class="h-3.5 w-3.5" />{{ device(log.agent).text }}
                                    </span>
                                </p>
                            </div>
                            <ChevronDownIcon class="mt-1 h-4 w-4 shrink-0 text-gray-300 transition" :class="open === log.id ? 'rotate-180' : ''" />
                        </button>
                        <div v-if="open === log.id" class="grid gap-2 bg-gray-50 px-4 py-3 text-xs text-gray-600 dark:bg-gray-900/40 dark:text-gray-300 sm:grid-cols-2">
                            <div><span class="text-gray-400">{{ t('activity.when', 'الوقت') }}:</span> {{ new Date(log.created_at).toLocaleString('ar-IQ', { timeZone: 'Asia/Baghdad' }) }}</div>
                            <div v-if="log.user"><span class="text-gray-400">{{ t('auth.email', 'البريد') }}:</span> <span dir="ltr">{{ log.user.email }}</span></div>
                            <div v-if="log.ip"><span class="text-gray-400">IP:</span> <span dir="ltr">{{ log.ip }}</span></div>
                            <div><span class="text-gray-400">{{ t('activity.code', 'الرمز') }}:</span> <span dir="ltr">{{ log.action }}</span></div>
                            <div v-if="log.subject" class="sm:col-span-2">
                                <span class="text-gray-400">{{ t('activity.subject', 'العنصر') }}:</span>
                                <component :is="log.subject.type === 'folder' ? FolderIcon : DocumentIcon" class="mx-1 inline h-3.5 w-3.5" />
                                <a v-if="subjectLink(log.subject)" :href="subjectLink(log.subject)" class="text-[var(--accent-600)] hover:underline">{{ log.subject.name }}</a>
                                <span v-else>{{ log.subject.name }}</span>
                            </div>
                            <div v-if="log.agent" class="break-all text-gray-400 sm:col-span-2" dir="ltr">{{ log.agent }}</div>
                            <div class="flex gap-2 sm:col-span-2">
                                <button v-if="log.user" type="button" class="rounded-lg border border-gray-300 px-2.5 py-1 hover:bg-white dark:border-gray-600 dark:hover:bg-gray-800" @click="f.user = log.user.id; apply()">{{ t('activity.only_user', 'نشاطات هذا الشخص فقط') }}</button>
                                <button type="button" class="rounded-lg border border-gray-300 px-2.5 py-1 hover:bg-white dark:border-gray-600 dark:hover:bg-gray-800" @click="f.action = log.action; showMore = true; apply()">{{ t('activity.only_action', 'هذا النوع فقط') }}</button>
                            </div>
                        </div>
                    </li>
                </ol>
            </section>

            <Pagination :links="logs.links" />
        </div>
    </AdminLayout>
</template>
