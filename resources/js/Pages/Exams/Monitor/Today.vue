<script setup>
// «اليوم» (2026-11-09, by request): the day on one page for the invigilator — every exam today
// with its time, hall and groups; who came, started and handed in; what needs looking at now;
// and the checklist before the exam, which ticks itself. Refreshed every 20 seconds.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    CalendarDaysIcon, CheckCircleIcon, XCircleIcon, ExclamationTriangleIcon, EyeIcon, MapIcon, DocumentTextIcon, ClockIcon,
    ComputerDesktopIcon, HandRaisedIcon, LockClosedIcon, ShieldExclamationIcon, ChevronDownIcon, ArrowPathIcon, UserGroupIcon, BuildingOffice2Icon,
} from '@heroicons/vue/24/outline';

const props = defineProps({ date: String, exams: Array, halls: Array });
const { t } = useTrans();
const data = ref({ date: props.date, exams: props.exams, halls: props.halls });
const refreshing = ref(false);
const now = ref(Date.now());
let poll = null;
let tick = null;
async function refresh() {
    refreshing.value = true;
    try { data.value = (await window.axios.get(route('exams.monitor.today'), { headers: { Accept: 'application/json' } })).data; } catch {}
    refreshing.value = false;
}
onMounted(() => { poll = setInterval(refresh, 20000); tick = setInterval(() => (now.value = Date.now()), 30000); });
onBeforeUnmount(() => { clearInterval(poll); clearInterval(tick); });

const lang = () => document.documentElement.lang || 'ar';
const dayName = computed(() => new Date(data.value.date).toLocaleDateString(lang(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
const hm = (iso) => (iso ? new Date(iso).toLocaleTimeString(lang(), { hour: '2-digit', minute: '2-digit' }) : '');
function when(e) {
    if (e.state === 'running') return { text: t('today.running', 'جارٍ الآن'), cls: 'bg-blue-600 text-white' };
    if (e.starts) {
        const m = Math.round((new Date(e.starts).getTime() - now.value) / 60000);
        if (m > 0) return { text: m < 60 ? t('today.in_min', 'يبدأ بعد :n دقيقة', { n: m }) : t('today.at', 'يبدأ :t', { t: hm(e.starts) }), cls: m <= 30 ? 'bg-amber-500 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' };
    }
    return e.state === 'done' ? { text: t('today.done', 'انتهى'), cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' } : { text: t('today.open', 'مفتوح'), cls: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' };
}
const pct = (n, of) => (of ? Math.min(100, Math.round((n / of) * 100)) : 0);
const open = ref({});
const isOpen = (e) => open.value[e.id] ?? !e.ready;
const totals = computed(() => data.value.exams.reduce((a, e) => ({ running: a.running + e.running, alerts: a.alerts + e.locked + e.help + e.suspects, notReady: a.notReady + (e.ready ? 0 : 1) }), { running: 0, alerts: 0, notReady: 0 }));
</script>

<template>
    <Head :title="t('today.title', 'اليوم')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <CalendarDaysIcon class="h-5 w-5 text-gray-500" />
                <h2 class="min-w-0 flex-1 truncate text-lg font-semibold">{{ t('today.title', 'اليوم') }} <span class="text-sm font-normal text-gray-500">· {{ dayName }}</span></h2>
                <button @click="refresh" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" :aria-label="t('today.refresh', 'تحديث')"><ArrowPathIcon class="h-5 w-5" :class="refreshing ? 'animate-spin' : ''" /></button>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-4 p-3 sm:p-6">
            <!-- The day at a glance -->
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 sm:gap-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"><p class="text-xs text-gray-500">{{ t('today.exams', 'امتحانات اليوم') }}</p><p class="text-2xl font-bold tabular-nums">{{ data.exams.length }}</p></div>
                <div class="rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"><p class="text-xs text-gray-500">{{ t('today.working_now', 'طلاب يؤدّون الآن') }}</p><p class="text-2xl font-bold tabular-nums text-blue-600">{{ totals.running }}</p></div>
                <div class="rounded-2xl border p-3" :class="totals.alerts ? 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/20' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'"><p class="text-xs text-gray-500">{{ t('today.alerts', 'تحتاج انتباه') }}</p><p class="text-2xl font-bold tabular-nums" :class="totals.alerts ? 'text-red-600' : ''">{{ totals.alerts }}</p></div>
                <div class="rounded-2xl border p-3" :class="totals.notReady ? 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'"><p class="text-xs text-gray-500">{{ t('today.not_ready', 'غير جاهزة') }}</p><p class="text-2xl font-bold tabular-nums" :class="totals.notReady ? 'text-amber-600' : 'text-emerald-600'">{{ totals.notReady }}</p></div>
            </div>

            <!-- Halls -->
            <div v-if="data.halls.length" class="flex gap-2 overflow-x-auto pb-1">
                <Link v-for="h in data.halls" :key="h.room" :href="route('exams.monitor.halls')" class="flex shrink-0 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <BuildingOffice2Icon class="h-4 w-4 text-gray-400" /><span class="font-medium">{{ h.room }}</span>
                    <span class="inline-flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"><span class="h-2 w-2 rounded-full bg-emerald-500" /> {{ h.on }}/{{ h.total }}</span>
                    <span v-if="h.problems" class="rounded-full bg-red-100 px-1.5 text-xs font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ h.problems }}</span>
                </Link>
            </div>

            <div v-if="!data.exams.length" class="rounded-2xl border border-dashed border-gray-300 p-10 text-center dark:border-gray-600">
                <CalendarDaysIcon class="mx-auto h-12 w-12 text-gray-300" />
                <p class="mt-2 font-medium">{{ t('today.none', 'لا امتحانات اليوم') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ t('today.none_hint', 'تظهر هنا امتحانات تفتح اليوم، أو لها جلسة اليوم، أو يؤدّيها طلاب الآن.') }}</p>
                <Link :href="route('exams.monitor.index')" class="mt-3 inline-block text-sm text-[var(--accent-600)] underline">{{ t('exam.monitor', 'المراقبة') }}</Link>
            </div>

            <article v-for="e in data.exams" :key="e.id" class="overflow-hidden rounded-2xl border bg-white dark:bg-gray-800" :class="e.ready ? 'border-gray-200 dark:border-gray-700' : 'border-amber-300 dark:border-amber-700'">
                <div class="p-4">
                    <div class="flex flex-wrap items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate text-base font-bold">{{ e.title }}</h3>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                                <span v-if="e.starts" class="inline-flex items-center gap-1"><ClockIcon class="h-3.5 w-3.5" /> {{ hm(e.starts) }}<template v-if="e.closes"> – {{ hm(e.closes) }}</template><template v-if="e.duration"> · {{ t('today.minutes', ':n دقيقة', { n: e.duration }) }}</template></span>
                                <span v-if="e.hall" class="inline-flex items-center gap-1"><BuildingOffice2Icon class="h-3.5 w-3.5" /> {{ e.hall }}</span>
                                <span v-if="e.groups.length" class="inline-flex items-center gap-1"><UserGroupIcon class="h-3.5 w-3.5" /> {{ e.groups.join('، ') }}</span>
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="when(e).cls">{{ when(e).text }}</span>
                    </div>

                    <!-- Who came, started, handed in -->
                    <div class="mt-3 grid grid-cols-4 gap-2 text-center text-xs">
                        <div class="rounded-xl bg-gray-50 p-2 dark:bg-gray-900/40"><p class="text-gray-500">{{ t('today.expected', 'المتوقّع') }}</p><p class="text-lg font-bold tabular-nums">{{ e.expected || '—' }}</p></div>
                        <div class="rounded-xl bg-gray-50 p-2 dark:bg-gray-900/40"><p class="text-gray-500">{{ t('today.present', 'حاضر') }}</p><p class="text-lg font-bold tabular-nums">{{ e.present }}</p></div>
                        <div class="rounded-xl bg-blue-50 p-2 dark:bg-blue-900/20"><p class="text-blue-700 dark:text-blue-300">{{ t('today.working', 'يؤدّي') }}</p><p class="text-lg font-bold tabular-nums text-blue-700 dark:text-blue-300">{{ e.running }}</p></div>
                        <div class="rounded-xl bg-emerald-50 p-2 dark:bg-emerald-900/20"><p class="text-emerald-700 dark:text-emerald-300">{{ t('today.submitted', 'سلّم') }}</p><p class="text-lg font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ e.submitted }}</p></div>
                    </div>
                    <div v-if="e.expected" class="mt-2 flex h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" :title="t('today.progress', 'التسليم من المتوقّع')">
                        <span class="bg-emerald-500" :style="{ width: pct(e.submitted, e.expected) + '%' }" />
                        <span class="bg-blue-500" :style="{ width: pct(e.running, e.expected) + '%' }" />
                    </div>

                    <!-- What needs looking at now -->
                    <div v-if="e.locked || e.help || e.suspects" class="mt-3 flex flex-wrap gap-1.5">
                        <Link v-if="e.suspects" :href="route('exams.monitor.halls', { exam: e.id })" class="inline-flex items-center gap-1 rounded-full bg-purple-600 px-3 py-1 text-xs font-semibold text-white"><ShieldExclamationIcon class="h-4 w-4" /> {{ t('today.suspects', ':n اشتباه غش', { n: e.suspects }) }}</Link>
                        <Link v-if="e.locked" :href="route('exams.monitor.show', e.id)" class="inline-flex items-center gap-1 rounded-full bg-red-600 px-3 py-1 text-xs font-semibold text-white"><LockClosedIcon class="h-4 w-4" /> {{ t('today.locked', ':n مقفلة', { n: e.locked }) }}</Link>
                        <Link v-if="e.help" :href="route('exams.monitor.show', e.id)" class="inline-flex items-center gap-1 rounded-full bg-amber-500 px-3 py-1 text-xs font-semibold text-white"><HandRaisedIcon class="h-4 w-4" /> {{ t('today.help', ':n يطلب مساعدة', { n: e.help }) }}</Link>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <Link :href="route('exams.monitor.show', e.id)" class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white"><EyeIcon class="h-4 w-4" /> {{ t('exam.monitor', 'المراقبة') }}</Link>
                        <Link :href="route('exams.monitor.halls', { exam: e.id })" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><MapIcon class="h-4 w-4" /> {{ t('hallmap.title', 'خريطة القاعات') }}</Link>
                        <a v-if="route().has('exams.monitor.report')" :href="route('exams.monitor.report', e.id)" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><DocumentTextIcon class="h-4 w-4" /> {{ t('report.title', 'محضر الامتحان') }}</a>
                    </div>
                </div>

                <!-- The checklist before the exam -->
                <div class="border-t border-gray-100 dark:border-gray-700">
                    <button @click="open[e.id] = !isOpen(e)" class="flex w-full items-center gap-2 px-4 py-2.5 text-sm font-semibold" :class="e.ready ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300'">
                        <CheckCircleIcon v-if="e.ready" class="h-5 w-5" /><ExclamationTriangleIcon v-else class="h-5 w-5" />
                        <span class="flex-1 text-start">{{ e.ready ? t('today.ready', 'جاهز للامتحان') : t('today.check_items', 'قائمة الفحص: :n بند يحتاج انتباه', { n: e.check.filter((c) => c.ok !== true).length }) }}</span>
                        <ChevronDownIcon class="h-4 w-4 transition" :class="isOpen(e) ? 'rotate-180' : ''" />
                    </button>
                    <ul v-if="isOpen(e)" class="space-y-1 px-4 pb-3">
                        <li v-for="c in e.check" :key="c.key" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm" :class="c.ok === false ? 'bg-red-50 dark:bg-red-900/20' : c.ok === null ? 'bg-amber-50 dark:bg-amber-900/20' : ''">
                            <CheckCircleIcon v-if="c.ok === true" class="h-5 w-5 shrink-0 text-emerald-500" />
                            <XCircleIcon v-else-if="c.ok === false" class="h-5 w-5 shrink-0 text-red-500" />
                            <ExclamationTriangleIcon v-else class="h-5 w-5 shrink-0 text-amber-500" />
                            <span class="min-w-0 flex-1"><span class="font-medium">{{ c.label }}</span><span v-if="c.detail" class="text-gray-500"> · {{ c.detail }}</span></span>
                            <Link v-if="c.url && c.ok !== true" :href="c.url" class="shrink-0 text-xs text-[var(--accent-600)] underline">{{ t('today.fix', 'معالجة') }}</Link>
                        </li>
                    </ul>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
