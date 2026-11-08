<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, ArrowDownTrayIcon, ArrowPathIcon, PencilSquareIcon, TrashIcon, EyeIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ exam: Object, summary: Object, questions: Array, attempts: Array, overrides: Array });
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
                <button v-for="k in ['attempts', 'questions', 'overrides']" :key="k" @click="tab = k"
                    class="-mb-px border-b-2 px-4 py-2 text-sm" :class="tab === k ? 'border-[var(--accent-600)] font-medium text-[var(--accent-700)] dark:text-white' : 'border-transparent text-gray-500'">
                    {{ { attempts: t('exam.tab_attempts', 'المحاولات'), questions: t('exam.tab_questions', 'تحليل الأسئلة'), overrides: t('exam.tab_overrides', 'الاستثناءات') }[k] }}
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
                            <p class="whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-700" dir="auto">{{ essay.text || t('exam.no_answer', 'بلا إجابة') }}</p>
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

            <!-- Question analysis -->
            <div v-else-if="tab === 'questions'" class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-700/50">
                        <tr><th class="px-4 py-2 text-start">#</th><th class="px-2 py-2 text-start">{{ t('exam.question', 'السؤال') }}</th><th class="px-2 py-2">{{ t('exam.points', 'درجة') }}</th><th class="px-4 py-2">{{ t('exam.facility', 'السهولة %') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="q in questions" :key="q.id" class="border-t border-gray-100 dark:border-gray-700">
                            <td class="px-4 py-2">{{ q.number }}</td>
                            <td class="max-w-xl truncate px-2 py-2" dir="auto">{{ q.body }}</td>
                            <td class="px-2 py-2 text-center">{{ q.points }}</td>
                            <td class="px-4 py-2 text-center font-medium" :class="facilityClass(q.facility)">{{ q.facility ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-gray-700">{{ t('exam.facility_hint', 'السهولة = متوسط ما حصّله الطلاب من درجة السؤال. أقل من 30% قد يعني سؤالاً صعباً جداً أو مفتاحاً خاطئاً.') }}</p>
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
