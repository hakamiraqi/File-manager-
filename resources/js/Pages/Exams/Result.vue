<script setup>
import RichText from '@/Components/Exams/RichText.vue';
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { CheckCircleIcon, XCircleIcon, MinusCircleIcon, ArrowRightIcon, DocumentCheckIcon, PrinterIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ exam: Object, attempt: Object, visible: Boolean, review: Array, appeals_open: Boolean, appeals: { type: Array, default: () => [] } });
const appealFor = ref(null);
const appealForm = useForm({ question_id: null, body: '' });
function sendAppeal() {
    appealForm.question_id = appealFor.value;
    appealForm.post(route('exams.attempts.appeals.store', props.attempt.id), { preserveScroll: true, onSuccess: () => { appealFor.value = null; appealForm.reset(); } });
}
const appealsOf = (qid) => props.appeals.filter((a) => a.question_id === qid);
const { t } = useTrans();
const passed = computed(() => props.exam.pass_mark !== null && props.attempt.percent !== null ? props.attempt.percent >= props.exam.pass_mark : null);
// Prints the receipt alone (the rest of the page is hidden while printing).
function printReceipt() {
    document.body.classList.add('printing-receipt');
    window.print();
    setTimeout(() => document.body.classList.remove('printing-receipt'), 500);
}
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

            <!-- Submission receipt: the code the student keeps; the invigilator and teacher see the same one -->
            <section v-if="attempt.receipt" class="exam-receipt rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <DocumentCheckIcon class="hidden h-8 w-8 shrink-0 text-emerald-600 sm:block" />
                    <div class="min-w-0 flex-1 text-sm">
                        <p class="flex items-center gap-1 font-semibold"><DocumentCheckIcon class="h-5 w-5 text-emerald-600 sm:hidden" /> {{ t('exam.receipt', 'إيصال التسليم') }}</p>
                        <p class="text-gray-600 dark:text-gray-300">{{ attempt.student }}<template v-if="attempt.student_number"> · {{ attempt.student_number }}</template></p>
                        <p class="text-gray-600 dark:text-gray-300">{{ exam.title }} · {{ t('exam.attempt', 'المحاولة') }} {{ attempt.number }}</p>
                        <p class="text-gray-600 dark:text-gray-300">{{ t('exam.receipt_time', 'وقت التسليم') }}: <span dir="ltr">{{ new Date(attempt.submitted_at).toLocaleString() }}</span>
                            · {{ t('exam.receipt_answered', ':a من :n سؤالاً مُجاباً', { a: attempt.answered, n: attempt.questions }) }}</p>
                    </div>
                    <div class="flex items-center gap-3 sm:contents">
                    <div class="flex-1 rounded-lg bg-gray-50 py-2 text-center sm:flex-none sm:bg-transparent sm:py-0 dark:bg-gray-700/50 sm:dark:bg-transparent">
                        <p class="text-xs text-gray-500">{{ t('exam.receipt_code', 'رمز الإيصال') }}</p>
                        <p class="font-mono text-xl font-bold tracking-widest" dir="ltr">{{ attempt.receipt }}</p>
                    </div>
                    <button type="button" @click="printReceipt" class="rounded-lg border border-gray-300 p-2 print:hidden dark:border-gray-600" :title="t('exam.receipt_print', 'طباعة الإيصال')"><PrinterIcon class="h-5 w-5" /></button>
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-500">{{ t('exam.receipt_hint', 'احتفظ بهذا الرمز أو صوّره: يثبت أن إجاباتك سُلِّمت وسُجِّلت.') }}</p>
            </section>

            <article v-for="(r, i) in review" :key="r.id" class="rounded-xl border bg-white p-4 dark:bg-gray-800"
                :class="{ right: 'border-emerald-200 dark:border-emerald-800', partial: 'border-amber-200 dark:border-amber-800', wrong: 'border-red-200 dark:border-red-800', pending: 'border-gray-200 dark:border-gray-700' }[state(r)]">
                <div class="flex items-start gap-3">
                    <CheckCircleIcon v-if="state(r) === 'right'" class="h-6 w-6 shrink-0 text-emerald-500" />
                    <MinusCircleIcon v-else-if="state(r) === 'partial' || state(r) === 'pending'" class="h-6 w-6 shrink-0 text-amber-500" />
                    <XCircleIcon v-else class="h-6 w-6 shrink-0 text-red-500" />
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-gray-500">{{ t('exam.question', 'السؤال') }} {{ i + 1 }} · {{ r.earned ?? '—' }} / {{ r.points }}</p>
                        <RichText :text="r.body" :html="r.html" class="mt-1" />
                        <img v-if="r.picture" :src="r.picture" alt="" class="mt-2 block max-h-72 max-w-full rounded-lg" />
                        <div v-if="r.choice_pictures" class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div v-for="(c, k) in r.choice_pictures" :key="k" class="rounded-lg border-2 p-1.5 text-center text-xs"
                                :class="c.right ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : c.chosen ? 'border-[var(--accent-600)]' : 'border-gray-200 dark:border-gray-600'">
                                <img v-if="c.picture" :src="c.picture" alt="" class="mx-auto block max-h-24 max-w-full rounded" />
                                <span dir="auto" class="mt-1 block">{{ c.body }}</span>
                                <span v-if="c.chosen" class="mt-0.5 block font-semibold text-[var(--accent-700)]">{{ t('exam.your_choice', 'اختيارك') }}</span>
                            </div>
                        </div>
                        <div v-if="r.marks" class="relative mt-2 inline-block max-w-full">
                            <img :src="r.image" alt="" class="block max-h-80 max-w-full rounded-lg" />
                            <template v-for="(m, k) in r.marks" :key="k">
                                <span v-if="m.zone" class="absolute border-2 border-emerald-500 bg-emerald-400/20 text-[10px] text-emerald-800" :style="{ left: m.zone.x + '%', top: m.zone.y + '%', width: m.zone.w + '%', height: m.zone.h + '%' }">{{ m.label }}</span>
                                <span v-if="m.at" class="absolute -translate-x-1/2 -translate-y-1/2 rounded bg-[var(--accent-600)] px-1 text-[10px] text-white" :style="{ left: m.at.x + '%', top: m.at.y + '%' }">{{ m.label }}</span>
                            </template>
                        </div>
                        <div v-else-if="r.image" class="relative mt-2 inline-block max-w-full">
                            <img :src="r.image" alt="" class="block max-h-80 max-w-full rounded-lg" />
                            <span v-for="(z, k) in r.zones" :key="k" class="absolute border-2 border-emerald-500 bg-emerald-400/20"
                                :style="{ left: z.x + '%', top: z.y + '%', width: z.w + '%', height: z.h + '%' }" />
                            <span v-if="r.point" class="absolute h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-[var(--accent-600)] shadow"
                                :style="{ left: r.point.x + '%', top: r.point.y + '%' }" />
                        </div>
                        <p v-if="r.type !== 'hotspot'" class="mt-2 text-sm"><span class="text-gray-500">{{ t('exam.your_answer', 'إجابتك') }}:</span> <span dir="auto" class="whitespace-pre-line">{{ r.given ?? t('exam.no_answer', 'بلا إجابة') }}</span></p>
                        <p v-if="r.correct && r.correct.length && state(r) !== 'right'" class="mt-1 text-sm text-emerald-700 dark:text-emerald-400">
                            {{ t('exam.correct_answer', 'الإجابة الصحيحة') }}: <span dir="auto">{{ r.correct.join(' · ') }}</span>
                        </p>
                        <p v-if="r.feedback" class="mt-2 rounded-lg bg-gray-50 p-2 text-sm text-gray-600 dark:bg-gray-700 dark:text-gray-300" dir="auto">{{ r.feedback }}</p>
                        <div v-for="ap in appealsOf(r.id)" :key="ap.id" class="mt-2 rounded-lg border border-gray-200 p-2 text-xs dark:border-gray-600">
                            <p class="font-medium">{{ t('exam.my_appeal', 'اعتراضي') }}: <span :class="{ open: 'text-amber-600', accepted: 'text-emerald-600', rejected: 'text-gray-500' }[ap.status]">{{ { open: t('exam.appeal_open', 'قيد المراجعة'), accepted: t('exam.accepted', 'قُبل'), rejected: t('exam.rejected', 'رُفض') }[ap.status] }}</span></p>
                            <p v-if="ap.reply" class="mt-1" dir="auto">{{ ap.reply }}</p>
                        </div>
                        <template v-if="appeals_open && !appealsOf(r.id).some((a) => a.status === 'open')">
                            <button v-if="appealFor !== r.id" @click="appealFor = r.id" class="mt-2 text-xs text-[var(--accent-600)] underline">{{ t('exam.appeal', 'اعتراض على درجة هذا السؤال') }}</button>
                            <form v-else @submit.prevent="sendAppeal" class="mt-2 space-y-2">
                                <textarea v-model="appealForm.body" rows="3" dir="auto" :placeholder="t('exam.appeal_ph', 'اشرح لماذا تطلب مراجعة الدرجة')" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                                <p v-if="appealForm.errors.body || appealForm.errors.appeal" class="text-xs text-red-600">{{ appealForm.errors.body || appealForm.errors.appeal }}</p>
                                <div class="flex gap-2"><button :disabled="appealForm.processing" class="rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-xs text-white">{{ t('exam.send', 'إرسال') }}</button>
                                    <button type="button" @click="appealFor = null" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button></div>
                            </form>
                        </template>
                        <p v-if="r.comment" class="mt-2 rounded-lg bg-sky-50 p-2 text-sm text-sky-800 dark:bg-sky-900/30 dark:text-sky-200" dir="auto">{{ t('exam.teacher_comment', 'ملاحظة المصحّح') }}: {{ r.comment }}</p>
                    </div>
                </div>
            </article>
        </div>
    </AppLayout>
</template>

<style>
@media print {
    body.printing-receipt * { visibility: hidden; }
    body.printing-receipt .exam-receipt, body.printing-receipt .exam-receipt * { visibility: visible; }
    body.printing-receipt .exam-receipt { position: absolute; inset: 0 0 auto 0; border-color: #000; }
}
</style>
