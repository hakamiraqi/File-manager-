<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ClockIcon, PlayIcon, ArrowRightIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ exam: Object, attempts: Array });
const { t } = useTrans();
const page = usePage();
const form = useForm({ password: '' });
const fmt = (iso) => (iso ? new Date(iso).toLocaleString(document.documentElement.lang || 'ar', { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const canStart = computed(() => (!props.exam.seb_required || props.exam.in_seb) && (props.exam.in_progress || (props.exam.window === 'open' && props.exam.attempts_used < props.exam.max_attempts)));
const grading = { highest: 'أعلى درجة', average: 'المعدّل', first: 'المحاولة الأولى', last: 'المحاولة الأخيرة' };

function start() {
    form.post(route('exams.start', props.exam.id));
}
</script>

<template>
    <Head :title="exam.title" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('exams.index')" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="truncate text-lg font-semibold" :dir="exam.dir">{{ exam.title }}</h2>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
            <section class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <p class="text-sm text-gray-500">{{ [exam.subject, exam.stage, exam.department].filter(Boolean).join(' · ') }}</p>
                <p v-if="exam.description" class="mt-3 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300" :dir="exam.dir">{{ exam.description }}</p>

                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs text-gray-500">{{ t('exam.questions', 'الأسئلة') }}</dt><dd class="font-medium">{{ exam.questions }}</dd></div>
                    <div><dt class="text-xs text-gray-500">{{ t('exam.duration', 'المدة') }}</dt><dd class="flex items-center gap-1 font-medium"><ClockIcon class="h-4 w-4" />{{ exam.duration ? exam.duration + ' ' + t('exam.minutes', 'دقيقة') : t('exam.no_limit', 'بلا حد') }}</dd></div>
                    <div><dt class="text-xs text-gray-500">{{ t('exam.attempts', 'المحاولات') }}</dt><dd class="font-medium">{{ exam.attempts_used }} / {{ exam.max_attempts }}</dd></div>
                    <div><dt class="text-xs text-gray-500">{{ t('exam.opens', 'يفتح') }}</dt><dd class="font-medium">{{ fmt(exam.opens_at) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">{{ t('exam.closes', 'يغلق') }}</dt><dd class="font-medium">{{ fmt(exam.closes_at) }}</dd></div>
                    <div v-if="exam.max_attempts > 1"><dt class="text-xs text-gray-500">{{ t('exam.grading', 'الدرجة المعتمدة') }}</dt><dd class="font-medium">{{ t('exam.grading_' + exam.grading_method, grading[exam.grading_method]) }}</dd></div>
                </dl>

                <p v-if="exam.seb_required && !exam.in_seb" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    {{ t('exam.seb_needed', 'هذا الامتحان يُؤدّى في متصفح Safe Exam Browser فقط. افتحه من أجهزة القاعة أو من SEB بالإعدادات التي يعطيها الأستاذ.') }}
                </p>
                <p v-if="exam.is_practice" class="mt-4 rounded-lg bg-sky-50 p-3 text-sm text-sky-800 dark:bg-sky-900/30 dark:text-sky-200">{{ t('exam.practice_note', 'امتحان تدريبي: تحقّق من كل إجابة فور كتابتها.') }}</p>
                <p v-if="page.props.errors.exam" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ page.props.errors.exam }}</p>

                <form v-if="canStart" @submit.prevent="start" class="mt-5 flex flex-wrap items-end gap-3">
                    <label v-if="exam.needs_password && !exam.in_progress" class="block text-sm">
                        <span class="mb-1 block text-xs text-gray-500">{{ t('exam.password', 'كلمة مرور الامتحان') }}</span>
                        <input v-model="form.password" type="password" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <span v-if="form.errors.password" class="mt-1 block text-xs text-red-600">{{ form.errors.password }}</span>
                    </label>
                    <button :disabled="form.processing" class="flex items-center gap-2 rounded-lg bg-[var(--accent-600)] px-5 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50">
                        <PlayIcon class="h-4 w-4" /> {{ exam.in_progress ? t('exam.resume', 'متابعة المحاولة') : t('exam.start_attempt', 'ابدأ المحاولة') }}
                    </button>
                    <p v-if="exam.duration && !exam.in_progress" class="w-full text-xs text-gray-500">{{ t('exam.timer_note', 'يبدأ المؤقت عند الضغط، ويُسلَّم الامتحان تلقائياً عند انتهاء الوقت.') }}</p>
                </form>
            </section>

            <section v-if="attempts.length" class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <h3 class="border-b border-gray-100 px-5 py-3 text-sm font-semibold dark:border-gray-700">{{ t('exam.my_attempts', 'محاولاتي') }}</h3>
                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="a in attempts" :key="a.id" class="border-b border-gray-50 last:border-0 dark:border-gray-700">
                            <td class="px-5 py-2">#{{ a.number }}</td>
                            <td class="px-2 py-2 text-gray-500">{{ fmt(a.started_at) }}</td>
                            <td class="px-2 py-2">
                                <span v-if="!a.submitted_at" class="text-amber-600">{{ t('exam.in_progress', 'جارٍ') }}</span>
                                <span v-else-if="a.score !== null" class="font-medium">{{ a.score }} / {{ a.max_score }}</span>
                                <span v-else class="text-gray-400">{{ t('exam.result_hidden', 'تُعلن لاحقاً') }}</span>
                                <span v-if="a.needs_grading" class="ms-2 text-xs text-amber-600">{{ t('exam.pending_grading', 'بانتظار تصحيح المقالي') }}</span>
                            </td>
                            <td class="px-5 py-2 text-end">
                                <Link v-if="a.submitted_at" :href="route('exams.attempts.result', a.id)" class="text-[var(--accent-600)] hover:underline">{{ t('exam.view', 'عرض') }}</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
