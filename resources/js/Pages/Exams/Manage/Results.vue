<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, ArrowDownTrayIcon, ArrowPathIcon, PencilSquareIcon, TrashIcon, EyeIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ exam: Object, summary: Object, stats: Object, questions: Array, attempts: Array, overrides: Array });
const { t } = useTrans();
const fmt = (iso) => (iso ? new Date(iso).toLocaleString(document.documentElement.lang || 'ar', { dateStyle: 'short', timeStyle: 'short' }) : '—');
const open = ref({});
const tab = ref('attempts');

// Marking an essay
const marks = ref({});
function mark(essay) {
    const m = marks.value[essay.id] ?? { points: essay.points ?? '', comment: essay.comment ?? '' };
    router.put(route('exams.manage.answers.grade', essay.id), m, { preserveScroll: true });
}
const markFor = (essay) => (marks.value[essay.id] ??= { points: essay.points ?? '', comment: essay.comment ?? '' });

const regrade = () => router.post(route('exams.manage.regrade', props.exam.id), {}, { preserveScroll: true });

// Overrides
const overrideForm = useForm({ email: '', extra_minutes: '', closes_at: '', max_attempts: '', reason: '' });
const saveOverride = () => overrideForm.post(route('exams.manage.overrides.store', props.exam.id), { preserveScroll: true, onSuccess: () => overrideForm.reset() });
const removeOverride = (o) => router.delete(route('exams.manage.overrides.destroy', o.id), { preserveScroll: true });

const EVENT_LABELS = { hidden: 'غادر الصفحة', blur: 'ترك النافذة', paste: 'لصق', fullscreen_exit: 'خرج من ملء الشاشة', ip_changed: 'تغيّر العنوان' };
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
                <button @click="regrade" class="hidden items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm sm:flex dark:border-gray-600"><ArrowPathIcon class="h-4 w-4" /> {{ t('exam.regrade', 'إعادة التصحيح') }}</button>
                <a :href="route('exams.manage.export', exam.id)" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600"><ArrowDownTrayIcon class="h-4 w-4" /> Excel</a>
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
                <button v-for="k in ['attempts', 'stats', 'questions', 'overrides']" :key="k" @click="tab = k"
                    class="-mb-px border-b-2 px-4 py-2 text-sm" :class="tab === k ? 'border-[var(--accent-600)] font-medium text-[var(--accent-700)] dark:text-white' : 'border-transparent text-gray-500'">
                    {{ { attempts: t('exam.tab_attempts', 'المحاولات'), stats: t('exam.tab_stats', 'الإحصاءات'), questions: t('exam.tab_questions', 'تحليل الأسئلة'), overrides: t('exam.tab_overrides', 'الاستثناءات') }[k] }}
                </button>
            </div>

            <!-- Attempts -->
            <div v-if="tab === 'attempts'" class="space-y-2">
                <p v-if="!attempts.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-600">{{ t('exam.no_attempts', 'لم يبدأ أي طالب بعد.') }}</p>
                <div v-for="a in attempts" :key="a.id" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ a.user.name }} <span class="text-xs text-gray-400">#{{ a.number }}</span></p>
                            <p class="text-xs text-gray-500">{{ a.user.email }} · {{ fmt(a.started_at) }} → {{ a.submitted_at ? fmt(a.submitted_at) : t('exam.in_progress', 'جارٍ') }}</p>
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
                            <div class="flex flex-wrap items-end gap-2">
                                <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.points', 'درجة') }} / {{ essay.max }}</span>
                                    <input v-model="markFor(essay).points" type="number" min="0" :max="essay.max" step="0.25" class="w-24 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                                <label class="min-w-[12rem] flex-1 text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.comment', 'ملاحظة للطالب') }}</span>
                                    <input v-model="markFor(essay).comment" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                                <button @click="mark(essay)" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white">{{ t('action.save', 'حفظ') }}</button>
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
