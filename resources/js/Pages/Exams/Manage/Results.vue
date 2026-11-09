<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, ArrowDownTrayIcon, ArrowPathIcon, PencilSquareIcon, TrashIcon, EyeIcon, SparklesIcon, ArrowsRightLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ ai_enabled: Boolean, by_tag: { type: Array, default: () => [] }, appeals: { type: Array, default: () => [] }, exam: Object, can_manage: Boolean, markers: { type: Array, default: () => [] }, summary: Object, stats: Object, questions: Array, attempts: Array, overrides: Array });
const { t } = useTrans();
const fmt = (iso) => (iso ? new Date(iso).toLocaleString(document.documentElement.lang || 'ar', { dateStyle: 'short', timeStyle: 'short' }) : '—');
const open = ref({});
const tab = ref('attempts');

// Marking an essay
const marks = ref({});
const startMark = (essay) => ({
    ...(props.exam.double_marking ? { points: essay.my_mark?.points ?? '', comment: essay.my_mark?.comment ?? '' } : { points: essay.points ?? '', comment: essay.comment ?? '' }),
    rubric: essay.rubric?.length ? essay.rubric.map((c, k) => essay.rubric_scores?.[k] ?? '') : null,
});
function mark(essay, final = false) {
    const m = marks.value[essay.id] ?? startMark(essay);
    const body = { comment: m.comment, final };
    if (m.rubric) body.rubric = m.rubric.map((v) => Number(v || 0)); else body.points = m.points;
    router.put(route('exams.manage.answers.grade', essay.id), body, { preserveScroll: true });
}
// A suggestion from the AI (hosted site): fills the form; nothing is saved until «Save».
const suggestions = ref({});
async function suggest(essay) {
    suggestions.value[essay.id] = { loading: true };
    try {
        const s = (await window.axios.post(route('exams.manage.answers.suggest', essay.id))).data;
        const m = markFor(essay);
        if (s.rubric && m.rubric) m.rubric = [...s.rubric]; else m.points = s.points;
        suggestions.value[essay.id] = s;
    } catch (e) {
        suggestions.value[essay.id] = { error: e.response?.data?.message ?? t('exam.suggest_failed', 'تعذّر الاقتراح.') };
    }
}
const rubricTotal = (essay) => (markFor(essay).rubric ?? []).reduce((s, v) => s + Number(v || 0), 0);

// Similar answers (looked for on request).
const similar = ref(null);
const similarLoading = ref(false);
async function findSimilar() {
    similarLoading.value = true;
    try { similar.value = (await window.axios.get(route('exams.manage.similarity', { exam: props.exam.id, names: props.exam.names_shown && props.exam.anonymous ? 1 : undefined }))).data; } finally { similarLoading.value = false; }
}
// Appeals
const replies = ref({});
const replyTo = (ap, status) => router.put(route('exams.manage.appeals.reply', ap.id), { status, reply: replies.value[ap.id] ?? '' }, { preserveScroll: true });
const markFor = (essay) => (marks.value[essay.id] ??= startMark(essay));
const STATUS = { unmarked: ['لم يُصحَّح', 'bg-gray-100 text-gray-600'], second_needed: ['ينتظر المصحّح الثاني', 'bg-amber-100 text-amber-700'],
    disputed: ['خلاف بين المصحّحَين — يحسمه صاحب الامتحان', 'bg-red-100 text-red-700'], agreed: ['متّفق (المتوسط)', 'bg-emerald-100 text-emerald-700'], settled: ['حُسم', 'bg-emerald-100 text-emerald-700'] };

// Markers
const markerForm = useForm({ email: '' });
const addMarker = () => markerForm.post(route('exams.manage.markers.store', props.exam.id), { preserveScroll: true, onSuccess: () => markerForm.reset() });
const removeMarker = (m) => router.delete(route('exams.manage.markers.destroy', [props.exam.id, m.id]), { preserveScroll: true });

const importResults = (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (file) router.post(route('exams.manage.results-bundle.import', props.exam.id), { file }, { forceFormData: true, preserveScroll: true });
};
const regrade = () => router.post(route('exams.manage.regrade', props.exam.id), {}, { preserveScroll: true });

// Overrides
const overrideForm = useForm({ email: '', extra_minutes: '', closes_at: '', max_attempts: '', reason: '' });
const saveOverride = () => overrideForm.post(route('exams.manage.overrides.store', props.exam.id), { preserveScroll: true, onSuccess: () => overrideForm.reset() });
const removeOverride = (o) => router.delete(route('exams.manage.overrides.destroy', o.id), { preserveScroll: true });

const EVENT_LABELS = { hidden: 'غادر الصفحة', blur: 'ترك النافذة', paste: 'لصق', copy: 'محاولة نسخ', fullscreen_exit: 'خرج من ملء الشاشة', ip_changed: 'تغيّر العنوان' };
const discClass = (d) => (d === null ? 'text-gray-400' : d < 0 ? 'font-semibold text-red-600' : d < 0.2 ? 'text-red-600' : d < 0.3 ? 'text-amber-600' : 'text-emerald-600');
const alphaLabel = (a) => (a === null ? '' : a >= 0.8 ? t('exam.alpha_good', 'جيد') : a >= 0.7 ? t('exam.alpha_ok', 'مقبول') : t('exam.alpha_low', 'منخفض'));
const maxBin = () => Math.max(1, ...(props.stats?.distribution ?? [0]));
const facilityClass = (f) => (f === null ? 'text-gray-400' : f < 30 ? 'text-red-600' : f > 85 ? 'text-amber-600' : 'text-emerald-600');
</script>

<template>
    <Head :title="t('exam.results', 'النتائج')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('exams.manage.edit', exam.id)" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="min-w-0 flex-1 truncate text-lg font-semibold" :dir="exam.dir">{{ t('exam.results', 'النتائج') }} · {{ exam.title }}</h2>
                <Link v-if="exam.anonymous && can_manage" :href="route('exams.manage.results', { exam: exam.id, names: exam.names_shown ? undefined : 1 })" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">{{ exam.names_shown ? t('exam.hide_names', 'إخفاء الأسماء') : t('exam.show_names', 'إظهار الأسماء') }}</Link>
                <button v-if="can_manage" @click="regrade" class="hidden items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm sm:flex dark:border-gray-600"><ArrowPathIcon class="h-4 w-4" /> {{ t('exam.regrade', 'إعادة التصحيح') }}</button>
                <a v-if="can_manage" :href="route('exams.manage.results-bundle', exam.id)" class="hidden rounded-lg border border-gray-300 px-3 py-1.5 text-sm md:block dark:border-gray-600" :title="t('exam.results_bundle_hint', 'نتائج هذا السيرفر في ملف، تُدخل في السيرفر المركزي')">{{ t('exam.results_bundle', 'ملف النتائج') }}</a>
                <label v-if="can_manage" class="hidden cursor-pointer rounded-lg border border-gray-300 px-3 py-1.5 text-sm md:block dark:border-gray-600" :title="t('exam.results_import_hint', 'ملف نتائج من سيرفر قاعة لهذا الامتحان نفسه')">
                    <input type="file" accept=".json" class="sr-only" @change="importResults" />{{ t('exam.results_import', 'إدخال نتائج') }}</label>
                <span v-if="$page.props.errors.file" class="text-xs text-red-600">{{ $page.props.errors.file }}</span>
                <a v-if="can_manage" :href="route('exams.manage.export', exam.id)" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600"><ArrowDownTrayIcon class="h-4 w-4" /> Excel</a>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"><p class="text-xs text-gray-500">{{ t('exam.students', 'الطلاب') }}</p><p class="text-xl font-semibold">{{ summary.students }}</p></div>
                <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"><p class="text-xs text-gray-500">{{ t('exam.attempts', 'المحاولات') }}</p><p class="text-xl font-semibold">{{ summary.attempts }}</p></div>
                <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"><p class="text-xs text-gray-500">{{ t('exam.submitted', 'مُسلّمة') }}</p><p class="text-xl font-semibold">{{ summary.submitted }}</p></div>
                <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"><p class="text-xs text-gray-500">{{ t('exam.average', 'المعدّل') }}</p><p class="text-xl font-semibold">{{ summary.average ?? '—' }}<span v-if="summary.average !== null" class="text-sm">%</span></p></div>
                <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"><p class="text-xs text-gray-500">{{ t('exam.pending_grading', 'بانتظار التصحيح') }}</p><p class="text-xl font-semibold" :class="summary.pending ? 'text-amber-600' : ''">{{ summary.pending }}</p></div>
            </div>

            <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700">
                <button v-for="k in ['attempts', 'stats', 'questions', 'similar', ...(appeals.length ? ['appeals'] : []), ...(can_manage ? ['overrides'] : []), ...(can_manage && exam.double_marking ? ['markers'] : [])]" :key="k" @click="tab = k"
                    class="-mb-px border-b-2 px-4 py-2 text-sm" :class="tab === k ? 'border-[var(--accent-600)] font-medium text-[var(--accent-700)] dark:text-white' : 'border-transparent text-gray-500'">
                    {{ { attempts: t('exam.tab_attempts', 'المحاولات'), stats: t('exam.tab_stats', 'الإحصاءات'), questions: t('exam.tab_questions', 'تحليل الأسئلة'), overrides: t('exam.tab_overrides', 'الاستثناءات'), markers: t('exam.tab_markers', 'المصحّحون'), similar: t('exam.tab_similar', 'التشابه'), appeals: t('exam.tab_appeals', 'الاعتراضات') + ' (' + appeals.filter((a) => a.status === 'open').length + ')' }[k] }}
                </button>
            </div>

            <!-- Attempts -->
            <div v-if="tab === 'attempts'" class="space-y-2">
                <p v-if="!attempts.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-600">{{ t('exam.no_attempts', 'لم يبدأ أي طالب بعد.') }}</p>
                <div v-for="a in attempts" :key="a.id" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ a.user.name }} <span class="text-xs text-gray-400">#{{ a.number }}</span></p>
                            <p class="text-xs text-gray-500">{{ a.user.email }} · {{ fmt(a.started_at) }} – {{ a.submitted_at ? fmt(a.submitted_at) : t('exam.in_progress', 'جارٍ') }}<template v-if="a.variant"> · {{ t('exam.variant_short', 'نسخة') }} {{ a.variant }}</template><template v-if="a.receipt"> · <span class="font-mono" dir="ltr" :title="t('exam.receipt_code', 'رمز الإيصال')">{{ a.receipt }}</span></template></p>
                        </div>
                        <span v-for="(n, type) in a.events" :key="type" class="rounded-full bg-red-50 px-2 py-0.5 text-xs text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ t('exam.event_' + type, EVENT_LABELS[type]) }} × {{ n }}</span>
                        <span v-if="a.needs_grading" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">{{ t('exam.pending_grading', 'بانتظار التصحيح') }}</span>
                        <span class="w-24 text-end font-semibold">{{ a.score ?? '—' }} / {{ a.max_score }}<span v-if="a.percent !== null" class="block text-xs font-normal text-gray-500">{{ a.percent }}%</span></span>
                        <Link v-if="a.submitted_at" :href="route('exams.attempts.result', a.id)" class="rounded p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('exam.view', 'عرض')"><EyeIcon class="h-4 w-4" /></Link>
                        <button v-if="a.essays.length" @click="open[a.id] = !open[a.id]" class="flex items-center gap-1 rounded-lg border border-gray-300 px-2.5 py-1 text-xs dark:border-gray-600"><PencilSquareIcon class="h-4 w-4" /> {{ t('exam.mark', 'تصحيح') }}</button>
                    </div>
                    <div v-if="open[a.id]" class="space-y-3 border-t border-gray-100 p-4 dark:border-gray-700">
                        <div v-for="essay in a.essays" :key="essay.id" class="space-y-2" :dir="exam.dir">
                            <p class="text-sm font-medium" dir="auto">{{ essay.question }}</p>
                            <a v-if="essay.file" :href="essay.file.url" class="flex items-center gap-2 rounded-lg bg-gray-50 p-3 text-sm text-[var(--accent-700)] hover:underline dark:bg-gray-700 dark:text-white" dir="auto">
                                <ArrowDownTrayIcon class="h-4 w-4" /> {{ essay.file.name }}
                            </a>
                            <p v-else class="whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-700" dir="auto">{{ essay.text || t('exam.no_answer', 'بلا إجابة') }}</p>
                            <div v-if="essay.status" class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="rounded-full px-2 py-0.5" :class="STATUS[essay.status][1]">{{ t('exam.mark_' + essay.status, STATUS[essay.status][0]) }}</span>
                                <span v-for="(m, k) in essay.marks" :key="k" class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-gray-700 dark:text-gray-300" :title="m.comment">{{ m.mine ? t('exam.my_mark', 'درجتي') : m.name }}: {{ m.points }}</span>
                                <span v-if="essay.points !== null" class="font-medium">{{ t('exam.final_mark', 'المعتمدة') }}: {{ essay.points }}</span>
                            </div>
                            <div class="flex flex-wrap items-end gap-2">
                                <div v-if="essay.rubric?.length" class="w-full space-y-1">
                                    <label v-for="(c, k) in essay.rubric" :key="k" class="flex items-center gap-2 text-sm">
                                        <span class="flex-1" dir="auto">{{ c.name }}</span>
                                        <input v-model="markFor(essay).rubric[k]" type="number" min="0" :max="c.max" step="0.25" class="w-20 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" />
                                        <span class="w-12 text-xs text-gray-500">/ {{ c.max }}</span>
                                    </label>
                                    <p class="text-xs text-gray-500">{{ t('exam.total', 'المجموع') }}: {{ rubricTotal(essay) }}</p>
                                </div>
                                <label v-else class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ exam.double_marking ? t('exam.my_mark', 'درجتي') : t('exam.points', 'درجة') }} / {{ essay.max }}</span>
                                    <input v-model="markFor(essay).points" type="number" min="0" :max="essay.max" step="0.25" class="w-24 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                                <label class="min-w-[12rem] flex-1 text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.comment', 'ملاحظة للطالب') }}</span>
                                    <input v-model="markFor(essay).comment" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                                <button v-if="ai_enabled && essay.text" type="button" @click="suggest(essay)" :disabled="suggestions[essay.id]?.loading" class="rounded-lg border border-violet-300 px-3 py-2 text-sm text-violet-700 disabled:opacity-50 dark:border-violet-700 dark:text-violet-300"><SparklesIcon class="inline h-4 w-4 align-text-bottom" /> {{ suggestions[essay.id]?.loading ? '…' : t('exam.suggest', 'اقتراح درجة') }}</button>
                                <button @click="mark(essay)" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white">{{ t('action.save', 'حفظ') }}</button>
                                <p v-if="suggestions[essay.id]?.reason" class="w-full rounded-lg bg-violet-50 p-2 text-xs text-violet-900 dark:bg-violet-900/30 dark:text-violet-100" dir="auto"><SparklesIcon class="inline h-4 w-4 align-text-bottom" /> {{ suggestions[essay.id].points }} — {{ suggestions[essay.id].reason }}</p>
                                <p v-if="suggestions[essay.id]?.error" class="w-full text-xs text-red-600">{{ suggestions[essay.id].error }}</p>
                                <button v-if="exam.double_marking && can_manage && essay.status === 'disputed'" @click="mark(essay, true)" class="rounded-lg border border-red-300 px-3 py-2 text-sm text-red-700 dark:border-red-700 dark:text-red-300">{{ t('exam.settle', 'اعتمادها نهائية') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics -->
            <div v-else-if="tab === 'stats'" class="space-y-4">
                <p v-if="!stats.students" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-600">{{ t('exam.no_stats', 'تظهر الإحصاءات بعد أن يسلّم الطلاب.') }}</p>
                <template v-else>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-6">
                        <div v-for="(v, k) in { mean: stats.mean, median: stats.median, sd: stats.sd, min: stats.min, max: stats.max }" :key="k" class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                            <p class="text-xs text-gray-500">{{ { mean: t('exam.mean', 'المتوسط'), median: t('exam.median', 'الوسيط'), sd: t('exam.sd', 'الانحراف المعياري'), min: t('exam.lowest', 'الأدنى'), max: t('exam.highest', 'الأعلى') }[k] }}</p>
                            <p class="text-xl font-semibold">{{ v ?? '—' }}<span v-if="v !== null && k !== 'sd'" class="text-sm">%</span></p>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800" :title="t('exam.alpha_hint', 'ألفا كرونباخ: مدى اتساق أسئلة الامتحان في قياس الشيء نفسه. 0.7 فأكثر مقبول.')">
                            <p class="text-xs text-gray-500">{{ t('exam.alpha', 'الثبات (ألفا)') }}</p>
                            <p class="text-xl font-semibold">{{ stats.alpha ?? '—' }} <span class="text-xs font-normal text-gray-500">{{ alphaLabel(stats.alpha) }}</span></p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <p class="mb-3 text-sm font-medium">{{ t('exam.distribution', 'توزيع الدرجات') }} <span class="text-xs font-normal text-gray-500">· {{ stats.students }} {{ t('exam.students', 'الطلاب') }}</span></p>
                        <div class="flex h-40 items-end gap-1.5" dir="ltr" role="img" :aria-label="t('exam.distribution', 'توزيع الدرجات')">
                            <div v-for="(n, b) in stats.distribution" :key="b" class="flex flex-1 flex-col items-center justify-end gap-1">
                                <span class="text-xs text-gray-500">{{ n || '' }}</span>
                                <div class="w-full rounded-t" :class="exam.pass_mark !== null && b * 10 < exam.pass_mark ? 'bg-red-300 dark:bg-red-800' : 'bg-[var(--accent-500)]'"
                                    :style="{ height: (n / maxBin()) * 100 + '%', minHeight: n ? '4px' : '0' }" />
                            </div>
                        </div>
                        <div class="mt-1 flex gap-1.5 text-center text-[10px] text-gray-400" dir="ltr">
                            <span v-for="b in 10" :key="b" class="flex-1">{{ (b - 1) * 10 }}</span>
                        </div>
                    </div>
                    <div v-if="by_tag.length" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <p class="mb-2 text-sm font-medium">{{ t('exam.by_tag', 'حسب الموضوع (الوسوم)') }}</p>
                        <div v-for="r in by_tag" :key="r.tag" class="flex items-center gap-3 py-1 text-sm">
                            <span class="w-40 truncate" dir="auto">{{ r.tag }}</span>
                            <div class="h-2 flex-1 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-2 rounded-full" :class="r.facility < 50 ? 'bg-red-400' : r.facility < 70 ? 'bg-amber-400' : 'bg-emerald-500'" :style="{ width: r.facility + '%' }" /></div>
                            <span class="w-20 text-end text-xs text-gray-500">{{ r.facility }}% · {{ r.questions }} {{ t('exam.q_short', 'س') }}</span>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">{{ t('exam.stats_hint', 'تُحسب من أول محاولة مُسلّمة لكل طالب. الأسئلة العشوائية من البنك لا تدخل في تحليل الأسئلة لأن الطلاب لم يأخذوها جميعاً.') }}</p>
                </template>
            </div>

            <!-- Question analysis -->
            <div v-else-if="tab === 'questions'" class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-700/50">
                        <tr><th class="px-4 py-2 text-start">#</th><th class="px-2 py-2 text-start">{{ t('exam.question', 'السؤال') }}</th><th class="px-2 py-2">{{ t('exam.points', 'درجة') }}</th><th class="px-4 py-2">{{ t('exam.facility', 'السهولة %') }}</th><th class="px-4 py-2">{{ t('exam.discrimination', 'التمييز') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="q in questions" :key="q.id" class="border-t border-gray-100 dark:border-gray-700">
                            <td class="px-4 py-2">{{ q.number }}</td>
                            <td class="max-w-xl truncate px-2 py-2" dir="auto">{{ q.body }}</td>
                            <td class="px-2 py-2 text-center">{{ q.points }}</td>
                            <td class="px-4 py-2 text-center font-medium" :class="facilityClass(q.facility)">{{ q.facility ?? '—' }}</td>
                            <td class="px-4 py-2 text-center font-medium" :class="discClass(q.discrimination)">{{ q.discrimination ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-gray-700">{{ t('exam.facility_hint', 'السهولة = متوسط ما حصّله الطلاب من درجة السؤال. أقل من 30% قد يعني سؤالاً صعباً جداً أو مفتاحاً خاطئاً.') }}
                    {{ t('exam.discrimination_hint', 'التمييز = هل يجيب عنه المتفوقون أكثر من غيرهم: فوق 0.3 جيد، تحت 0.2 ضعيف، والسالب يستحق مراجعة المفتاح.') }}</p>
            </div>

            <!-- Similar answers -->
            <div v-else-if="tab === 'similar'" class="space-y-3">
                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <p class="flex-1 text-gray-600 dark:text-gray-300">{{ t('exam.similar_hint', 'يبحث عن مقالات تتشارك عبارات طويلة، وأوراق تتطابق فيها الإجابات الخاطئة نفسها. للتنبيه فقط، لا دليل غش بذاته.') }}</p>
                    <button @click="findSimilar" :disabled="similarLoading" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-white disabled:opacity-50">{{ similarLoading ? '…' : t('exam.find_similar', 'ابحث') }}</button>
                </div>
                <p v-if="similar && !similar.length" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-600">{{ t('exam.no_similar', 'لا تشابه لافت.') }}</p>
                <div v-for="(p, k) in similar ?? []" :key="k" class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <span class="rounded-full px-2 py-0.5 text-xs" :class="p.kind === 'essay' ? 'bg-violet-100 text-violet-700' : 'bg-amber-100 text-amber-700'">{{ p.kind === 'essay' ? t('exam.similar_essay', 'مقالي') : t('exam.similar_choices', 'أخطاء متطابقة') }}</span>
                    <span class="flex-1">{{ p.a_name }} <ArrowsRightLeftIcon class="inline h-4 w-4 align-text-bottom" /> {{ p.b_name }}</span>
                    <span class="font-semibold">{{ p.kind === 'essay' ? p.score + '%' : p.score + ' ' + t('exam.same_wrong', 'إجابة خاطئة متطابقة') }}</span>
                    <Link :href="route('exams.attempts.result', p.a)" class="text-xs text-[var(--accent-600)] underline">A</Link>
                    <Link :href="route('exams.attempts.result', p.b)" class="text-xs text-[var(--accent-600)] underline">B</Link>
                </div>
            </div>

            <!-- Appeals -->
            <div v-else-if="tab === 'appeals'" class="space-y-2">
                <div v-for="ap in appeals" :key="ap.id" class="space-y-2 rounded-xl border bg-white p-4 text-sm dark:bg-gray-800" :class="ap.status === 'open' ? 'border-amber-300 dark:border-amber-700' : 'border-gray-200 dark:border-gray-700'">
                    <p class="flex flex-wrap items-center gap-2"><span class="font-medium">{{ ap.student }}</span>
                        <span v-if="ap.question" class="text-xs text-gray-500" dir="auto">· {{ ap.question }}</span>
                        <span class="flex-1" /><Link :href="route('exams.attempts.result', ap.attempt)" class="text-xs text-[var(--accent-600)] underline">{{ t('exam.view', 'عرض') }}</Link></p>
                    <p class="whitespace-pre-line rounded-lg bg-gray-50 p-2 dark:bg-gray-700" dir="auto">{{ ap.body }}</p>
                    <template v-if="ap.status === 'open'">
                        <textarea v-model="replies[ap.id]" rows="2" :placeholder="t('exam.reply_ph', 'الرد على الطالب (عدّل الدرجة من «تصحيح» إن لزم)')" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <div class="flex gap-2"><button @click="replyTo(ap, 'accepted')" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-white">{{ t('exam.accept', 'قبول') }}</button>
                            <button @click="replyTo(ap, 'rejected')" class="rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-600">{{ t('exam.reject', 'رفض') }}</button></div>
                    </template>
                    <p v-else class="text-xs" :class="ap.status === 'accepted' ? 'text-emerald-700' : 'text-gray-500'">{{ ap.status === 'accepted' ? t('exam.accepted', 'قُبل') : t('exam.rejected', 'رُفض') }}<span v-if="ap.reply">: {{ ap.reply }}</span></p>
                </div>
            </div>

            <!-- Markers (double marking) -->
            <div v-else-if="tab === 'markers'" class="space-y-3">
                <form @submit.prevent="addMarker" class="flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <label class="min-w-[16rem] flex-1 text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.marker_email', 'بريد الأستاذ المصحّح') }}</span>
                        <input v-model="markerForm.email" type="email" dir="ltr" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <span v-if="markerForm.errors.email" class="text-xs text-red-600">{{ markerForm.errors.email }}</span></label>
                    <button :disabled="markerForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('exam.add_marker', 'إضافة') }}</button>
                    <p class="w-full text-xs text-gray-500">{{ t('exam.markers_hint', 'يرى المصحّح صفحة النتائج ويصحّح، ولا يعدّل الامتحان. لا يرى درجة زميله قبل أن يضع درجته. أنت أيضاً مصحّح.') }}</p>
                </form>
                <div v-for="m in markers" :key="m.id" class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="min-w-0 flex-1"><p class="font-medium">{{ m.name }}</p><p class="text-xs text-gray-500">{{ m.email }}</p></div>
                    <button @click="removeMarker(m)" class="rounded p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30"><TrashIcon class="h-4 w-4" /></button>
                </div>
            </div>

            <!-- Overrides -->
            <div v-else class="space-y-3">
                <form @submit.prevent="saveOverride" class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-6 dark:border-gray-700 dark:bg-gray-800">
                    <label class="text-sm sm:col-span-2"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.student_email', 'بريد الطالب') }}</span><input v-model="overrideForm.email" type="email" dir="ltr" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <span v-if="overrideForm.errors.email" class="text-xs text-red-600">{{ overrideForm.errors.email }}</span></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.extra_minutes', 'دقائق إضافية') }}</span><input v-model="overrideForm.extra_minutes" type="number" min="0" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.max_attempts', 'عدد المحاولات') }}</span><input v-model="overrideForm.max_attempts" type="number" min="1" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm sm:col-span-2"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.closes', 'يغلق') }}</span><input v-model="overrideForm.closes_at" type="datetime-local" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm sm:col-span-5"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.reason', 'السبب') }}</span><input v-model="overrideForm.reason" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <button :disabled="overrideForm.processing" class="self-end rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('action.save', 'حفظ') }}</button>
                </form>
                <div v-for="o in overrides" :key="o.id" class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="min-w-0 flex-1"><p class="font-medium">{{ o.user.name }}</p><p class="text-xs text-gray-500">{{ o.user.email }}<span v-if="o.reason"> · {{ o.reason }}</span></p></div>
                    <span v-if="o.extra_minutes">+{{ o.extra_minutes }} {{ t('exam.minutes', 'دقيقة') }}</span>
                    <span v-if="o.max_attempts">{{ o.max_attempts }} {{ t('exam.attempts', 'المحاولات') }}</span>
                    <span v-if="o.closes_at">{{ t('exam.closes', 'يغلق') }} {{ o.closes_at.replace('T', ' ') }}</span>
                    <button @click="removeOverride(o)" class="rounded p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30"><TrashIcon class="h-4 w-4" /></button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
