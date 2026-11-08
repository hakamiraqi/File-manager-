<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { CheckCircleIcon, XCircleIcon, MinusCircleIcon, ArrowRightIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ exam: Object, attempt: Object, visible: Boolean, review: Array });
const { t } = useTrans();
const passed = computed(() => props.exam.pass_mark !== null && props.attempt.percent !== null ? props.attempt.percent >= props.exam.pass_mark : null);
const state = (r) => (r.earned === null ? 'pending' : r.earned >= r.points ? 'right' : r.earned > 0 ? 'partial' : 'wrong');
</script>

<template>
    <Head :title="exam.title" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('exams.show', exam.id)" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="truncate text-lg font-semibold" :dir="exam.dir">{{ exam.title }} · {{ t('exam.attempt', 'المحاولة') }} {{ attempt.number }}</h2>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-4 p-4 sm:p-6" :dir="exam.dir">
            <section class="rounded-xl border border-gray-200 bg-white p-6 text-center dark:border-gray-700 dark:bg-gray-800">
                <template v-if="visible && attempt.score !== null">
                    <p class="text-4xl font-bold">{{ attempt.score }} <span class="text-xl text-gray-400">/ {{ attempt.max_score }}</span></p>
                    <p class="mt-1 text-sm text-gray-500">{{ attempt.percent }}%</p>
                    <p v-if="passed !== null" class="mt-3 inline-block rounded-full px-3 py-1 text-sm font-medium" :class="passed ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'">
                        {{ passed ? t('exam.passed', 'ناجح') : t('exam.failed', 'لم ينجح') }}
                    </p>
                    <p v-if="attempt.needs_grading" class="mt-3 text-sm text-amber-600">{{ t('exam.pending_note', 'بعض الإجابات المقالية لم تُصحَّح بعد، وقد تتغيّر الدرجة.') }}</p>
                </template>
                <p v-else class="text-sm text-gray-600 dark:text-gray-300">{{ t('exam.submitted_hidden', 'تم تسليم إجاباتك. ستُعلن النتيجة لاحقاً.') }}</p>
            </section>

            <article v-for="(r, i) in review" :key="r.id" class="rounded-xl border bg-white p-4 dark:bg-gray-800"
                :class="{ right: 'border-emerald-200 dark:border-emerald-800', partial: 'border-amber-200 dark:border-amber-800', wrong: 'border-red-200 dark:border-red-800', pending: 'border-gray-200 dark:border-gray-700' }[state(r)]">
                <div class="flex items-start gap-3">
                    <CheckCircleIcon v-if="state(r) === 'right'" class="h-6 w-6 shrink-0 text-emerald-500" />
                    <MinusCircleIcon v-else-if="state(r) === 'partial' || state(r) === 'pending'" class="h-6 w-6 shrink-0 text-amber-500" />
                    <XCircleIcon v-else class="h-6 w-6 shrink-0 text-red-500" />
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-gray-500">{{ t('exam.question', 'السؤال') }} {{ i + 1 }} · {{ r.earned ?? '—' }} / {{ r.points }}</p>
                        <p class="mt-1 whitespace-pre-line" dir="auto">{{ r.body }}</p>
                        <p class="mt-2 text-sm"><span class="text-gray-500">{{ t('exam.your_answer', 'إجابتك') }}:</span> <span dir="auto" class="whitespace-pre-line">{{ r.given ?? t('exam.no_answer', 'بلا إجابة') }}</span></p>
                        <p v-if="r.correct && r.correct.length && state(r) !== 'right'" class="mt-1 text-sm text-emerald-700 dark:text-emerald-400">
                            {{ t('exam.correct_answer', 'الإجابة الصحيحة') }}: <span dir="auto">{{ r.correct.join(' · ') }}</span>
                        </p>
                        <p v-if="r.feedback" class="mt-2 rounded-lg bg-gray-50 p-2 text-sm text-gray-600 dark:bg-gray-700 dark:text-gray-300" dir="auto">{{ r.feedback }}</p>
                        <p v-if="r.comment" class="mt-2 rounded-lg bg-sky-50 p-2 text-sm text-sky-800 dark:bg-sky-900/30 dark:text-sky-200" dir="auto">{{ t('exam.teacher_comment', 'ملاحظة المصحّح') }}: {{ r.comment }}</p>
                    </div>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
