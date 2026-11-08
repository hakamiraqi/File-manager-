<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useTrans } from '@/Composables/useTrans';
import { PrinterIcon, ArrowRightIcon } from '@heroicons/vue/24/outline';

// The attendance sheet: name, seat, present, and room to sign.
const props = defineProps({ exam: Object, site: String, rows: Array });
const { t } = useTrans();
const printPage = () => window.print();
const date = new Date(props.exam.date).toLocaleDateString(document.documentElement.lang || 'ar', { dateStyle: 'full' });
</script>

<template>
    <Head :title="t('exam.attendance_sheet', 'كشف الحضور') + ' — ' + exam.title" />
    <div class="min-h-screen bg-gray-100 print:bg-white">
        <div class="flex items-center gap-2 border-b border-gray-200 bg-white px-4 py-2 text-sm print:hidden">
            <Link :href="route('exams.monitor.show', exam.id)" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
            <span class="flex-1 font-medium">{{ t('exam.attendance_sheet', 'كشف الحضور') }}</span>
            <button @click="printPage" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-white"><PrinterIcon class="h-4 w-4" /> {{ t('exam.print', 'طباعة') }}</button>
        </div>
        <article class="mx-auto my-6 max-w-[210mm] bg-white p-[12mm] text-[10.5pt] text-black shadow print:m-0 print:max-w-none print:p-0 print:shadow-none">
            <header class="mb-4 text-center">
                <p class="text-xs">{{ site }}</p>
                <h1 class="text-lg font-bold">{{ t('exam.attendance_sheet', 'كشف الحضور') }} — {{ exam.title }}</h1>
                <p class="text-sm">{{ [exam.subject, exam.stage, exam.groups.join('، ')].filter(Boolean).join(' · ') }} · {{ date }}</p>
            </header>
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border border-black px-2 py-1">#</th>
                        <th class="border border-black px-2 py-1 text-start">{{ t('exam.student_name', 'اسم الطالب') }}</th>
                        <th class="border border-black px-2 py-1">{{ t('exam.seat', 'المقعد') }}</th>
                        <th class="border border-black px-2 py-1">{{ t('exam.present', 'حاضر') }}</th>
                        <th class="border border-black px-2 py-1">{{ t('exam.submitted', 'سلّم') }}</th>
                        <th class="w-40 border border-black px-2 py-1">{{ t('exam.signature', 'التوقيع') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="r.user.id" class="break-inside-avoid">
                        <td class="border border-black px-2 py-1.5 text-center">{{ i + 1 }}</td>
                        <td class="border border-black px-2 py-1.5">{{ r.user.name }}</td>
                        <td class="border border-black px-2 py-1.5 text-center">{{ r.seat ?? '' }}</td>
                        <td class="border border-black px-2 py-1.5 text-center">{{ r.present === true ? '✓' : r.present === false ? '✗' : '' }}</td>
                        <td class="border border-black px-2 py-1.5 text-center">{{ r.status === 'submitted' ? '✓' : '' }}</td>
                        <td class="border border-black px-2 py-1.5" />
                    </tr>
                </tbody>
            </table>
            <div class="mt-8 grid grid-cols-2 gap-8 text-sm">
                <p>{{ t('exam.invigilator', 'المراقب') }}: ................................</p>
                <p>{{ t('exam.signature', 'التوقيع') }}: ................................</p>
            </div>
        </article>
    </div>
</template>
