<script setup>
import RichText from '@/Components/Exams/RichText.vue';
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useTrans } from '@/Composables/useTrans';
import { PrinterIcon, ArrowRightIcon, KeyIcon, DocumentTextIcon } from '@heroicons/vue/24/outline';

// The exam as a paper: questions with room to answer, or the answer key. Printing from the
// browser (or «Save as PDF» in its print window) gives the paper or the PDF.
const props = defineProps({ exam: Object, answerKey: Boolean, site: String, questions: Array });
const printPage = () => window.print();
const { t } = useTrans();

const AR_LETTERS = ['أ', 'ب', 'ج', 'د', 'هـ', 'و', 'ز', 'ح', 'ط', 'ي'];
// The version letter as the computers and the monitor show it (A, B, C).
const letterOf = (v) => String.fromCharCode(64 + v);
const letter = (i) => (props.exam.dir === 'rtl' ? AR_LETTERS[i] ?? i + 1 : String.fromCharCode(65 + i));
const graded = computed(() => props.questions.filter((q) => q.type !== 'description'));
const number = (q) => graded.value.indexOf(q) + 1;
const lines = (q) => ({ essay: 10, short: 1, numeric: 1, upload: 0 }[q.type] ?? 0);
</script>

<template>
    <Head :title="exam.title + (answerKey ? ' — ' + t('exam.answer_key', 'مفتاح الإجابة') : '')" />
    <div class="min-h-screen bg-gray-100 print:bg-white" :dir="exam.dir" :lang="exam.lang">
        <div class="sticky top-0 z-10 flex flex-wrap items-center gap-2 border-b border-gray-200 bg-white px-4 py-2 text-sm text-gray-700 print:hidden">
            <Link :href="route('exams.manage.edit', exam.id)" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
            <span class="flex-1 font-medium">{{ t('exam.print', 'طباعة') }}</span>
            <span v-if="exam.variants > 1" class="flex overflow-hidden rounded-lg border border-gray-300">
                <Link v-for="v in exam.variants" :key="v" :href="route('exams.manage.print', { exam: exam.id, variant: v, key: answerKey ? 1 : undefined })"
                    class="px-3 py-1.5" :class="exam.variant === v ? 'bg-[var(--accent-600)] text-white' : ''">{{ t('exam.variant_short', 'نسخة') }} {{ letterOf(v) }}</Link>
            </span>
            <Link v-if="answerKey" :href="route('exams.manage.print', { exam: exam.id, variant: exam.variant ?? undefined })" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5"><DocumentTextIcon class="h-4 w-4" /> {{ t('exam.paper', 'ورقة الأسئلة') }}</Link>
            <Link v-else :href="route('exams.manage.print', { exam: exam.id, key: 1, variant: exam.variant ?? undefined })" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5"><KeyIcon class="h-4 w-4" /> {{ t('exam.answer_key', 'مفتاح الإجابة') }}</Link>
            <button @click="printPage" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-white"><PrinterIcon class="h-4 w-4" /> {{ t('exam.print_pdf', 'طباعة / PDF') }}</button>
        </div>

        <article class="paper mx-auto my-6 max-w-[210mm] bg-white p-[15mm] text-[11pt] leading-relaxed text-black shadow print:my-0 print:max-w-none print:p-0 print:shadow-none">
            <header class="mb-6 border-b-2 border-black pb-3 text-center">
                <p class="text-xs">{{ site }}</p>
                <h1 class="mt-1 text-xl font-bold">{{ exam.title }}<span v-if="exam.variant"> — {{ t('exam.variant_short', 'نسخة') }} {{ letterOf(exam.variant) }}</span><span v-if="answerKey"> — {{ t('exam.answer_key', 'مفتاح الإجابة') }}</span></h1>
                <p class="mt-1 text-sm">
                    <span v-if="exam.subject">{{ exam.subject }}</span><span v-if="exam.stage"> · {{ exam.stage }}</span><span v-if="exam.department"> · {{ exam.department }}</span>
                    <span v-if="exam.duration"> · {{ t('exam.duration', 'المدة') }} {{ exam.duration }} {{ t('exam.minutes', 'دقيقة') }}</span>
                    · {{ t('exam.total_points', 'مجموع الدرجات') }} {{ exam.max_score }}
                </p>
                <div v-if="!answerKey" class="mt-4 grid grid-cols-2 gap-6 text-start text-sm">
                    <p>{{ t('exam.student_name', 'اسم الطالب') }}: <span class="inline-block w-48 border-b border-dotted border-black" /></p>
                    <p>{{ t('exam.student_number', 'الرقم') }}: <span class="inline-block w-32 border-b border-dotted border-black" /></p>
                </div>
            </header>
            <p v-if="exam.description" class="mb-5 whitespace-pre-line text-sm">{{ exam.description }}</p>

            <section v-for="q in questions" :key="q.id" class="question mb-5">
                <RichText v-if="q.type === 'description'" :text="q.body" :html="q.html" class="rounded border border-gray-400 p-3 text-sm" />
                <template v-else>
                    <p class="flex gap-2">
                        <span class="font-bold">{{ number(q) }}.</span>
                        <span class="flex-1 whitespace-pre-line" dir="auto">
                            <template v-if="q.type === 'random'">{{ t('exam.print_random', ':n أسئلة عشوائية من «:c» (تُسحب لكل طالب عند الامتحان الإلكتروني).', { n: q.count, c: q.category }) }}</template>
                            <RichText v-else :text="q.body" :html="q.html" tag="span" />
                        </span>
                        <span class="text-xs whitespace-nowrap">({{ q.points }} {{ t('exam.points', 'درجة') }})</span>
                    </p>
                    <img v-if="q.picture" :src="q.picture" alt="" class="mt-2 block max-h-[70mm] max-w-full" />

                    <ol v-if="q.choices.length" class="mt-2 space-y-1 ps-6">
                        <li v-for="(c, i) in q.choices" :key="i" class="flex gap-2" :class="c.is_correct ? 'font-bold' : ''">
                            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-black text-xs" :class="c.is_correct ? 'bg-black text-white' : ''">{{ q.type === 'ordering' ? '' : letter(i) }}</span>
                            <span class="flex flex-wrap items-center gap-2"><img v-if="c.picture" :src="c.picture" alt="" class="max-h-[25mm] max-w-[45mm]" /><RichText :text="c.body" tag="span" /></span>
                        </li>
                    </ol>

                    <div v-if="q.items" class="mt-2 grid grid-cols-2 gap-x-8 gap-y-1 ps-6 text-sm">
                        <ol class="space-y-1"><li v-for="(item, i) in q.items" :key="i" dir="auto">{{ i + 1 }}. <img v-if="q.item_pictures?.[i]" :src="q.item_pictures[i]" alt="" class="inline-block max-h-[18mm] max-w-[30mm] align-middle" /> {{ item }} <span class="inline-block w-10 border-b border-dotted border-black" /></li></ol>
                        <ol class="space-y-1"><li v-for="(target, i) in q.targets" :key="i" dir="auto">{{ letter(i) }}. {{ target }}</li></ol>
                    </div>

                    <div v-if="q.image" class="relative mt-2 inline-block max-w-full">
                        <img :src="q.image" alt="" class="block max-h-[90mm] max-w-full" />
                        <span v-for="(z, k) in q.zones" :key="k" class="absolute border-2 border-black" :style="{ left: z.x + '%', top: z.y + '%', width: z.w + '%', height: z.h + '%' }" />
                    </div>

                    <div v-if="!answerKey && lines(q)" class="mt-2 space-y-6 ps-6">
                        <div v-for="n in lines(q)" :key="n" class="border-b border-dotted border-gray-500" />
                    </div>
                    <p v-if="!answerKey && q.type === 'upload'" class="mt-2 ps-6 text-sm italic">{{ t('exam.print_upload', 'يُسلَّم مرفقاً.') }}</p>

                    <p v-if="answerKey && q.answer && q.answer.length" class="mt-2 ps-6 text-sm"><b>{{ t('exam.correct_answer', 'الإجابة الصحيحة') }}:</b> <span dir="auto">{{ q.answer.join(' · ') }}</span></p>
                </template>
            </section>
        </article>
    </div>
</template>

<style scoped>
@media print {
    @page { size: A4; margin: 15mm; }
    .question { break-inside: avoid; }
}
</style>
