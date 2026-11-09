<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { PresentationChartBarIcon, ChevronDownIcon } from '@heroicons/vue/24/outline';

// The university's view: departments and subjects side by side, and how the bank's questions behave.
const props = defineProps({ totals: Object, departments: Array, flags: Object });
const { t } = useTrans();
const open = ref({});
const bar = (p) => (p === null ? 'bg-gray-300' : p >= 75 ? 'bg-emerald-500' : p >= 50 ? 'bg-amber-500' : 'bg-red-500');
const FLAGS = { exposed: 'مكشوف', too_easy: 'سهل جداً', too_hard: 'صعب جداً', weak: 'تمييز ضعيف', check_key: 'راجع المفتاح' };
const tiles = [['exams', 'الامتحانات'], ['students', 'الطلاب'], ['attempts', 'المحاولات المسلّمة'], ['average', 'متوسط الدرجات %'], ['pass_rate', 'نسبة النجاح %'], ['questions', 'أسئلة البنك']];
</script>

<template>
    <Head :title="t('exam.dashboard', 'لوحة الجامعة')" />
    <AppLayout>
        <template #header><h2 class="flex items-center gap-2 text-lg font-semibold"><PresentationChartBarIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.dashboard', 'لوحة الجامعة') }}</h2></template>
        <div class="space-y-4 p-4 sm:p-6">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div v-for="[k, l] in tiles" :key="k" class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs text-gray-500">{{ t('exam.dash_' + k, l) }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ totals[k] ?? '—' }}</p>
                </div>
            </div>

            <section class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-700/50"><tr>
                        <th class="px-4 py-2 text-start">{{ t('exam.department', 'القسم') }} / {{ t('exam.subject', 'المادة') }}</th>
                        <th class="px-2 py-2">{{ t('exam.dash_exams', 'الامتحانات') }}</th><th class="px-2 py-2">{{ t('exam.dash_students', 'الطلاب') }}</th>
                        <th class="w-48 px-4 py-2">{{ t('exam.dash_average', 'متوسط الدرجات %') }}</th><th class="px-2 py-2">{{ t('exam.dash_pass_rate', 'نسبة النجاح %') }}</th></tr></thead>
                    <tbody>
                        <template v-for="d in departments" :key="d.name">
                            <tr class="cursor-pointer border-t border-gray-100 font-medium dark:border-gray-700" @click="open[d.name] = !open[d.name]">
                                <td class="px-4 py-2"><span class="flex items-center gap-1"><ChevronDownIcon class="h-4 w-4 transition" :class="open[d.name] ? '' : '-rotate-90 rtl:rotate-90'" /> {{ d.name }}</span></td>
                                <td class="px-2 py-2 text-center">{{ d.exams }}</td><td class="px-2 py-2 text-center">{{ d.students }}</td>
                                <td class="px-4 py-2"><div class="flex items-center gap-2"><div class="h-2 flex-1 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-2 rounded-full" :class="bar(d.average)" :style="{ width: (d.average ?? 0) + '%' }" /></div><span class="w-10 text-end text-xs">{{ d.average ?? '—' }}</span></div></td>
                                <td class="px-2 py-2 text-center">{{ d.pass_rate ?? '—' }}</td>
                            </tr>
                            <tr v-for="s in open[d.name] ? d.subjects : []" :key="d.name + s.name" class="border-t border-gray-50 text-gray-600 dark:border-gray-700/50 dark:text-gray-300">
                                <td class="px-4 py-1.5 ps-10">{{ s.name }}</td>
                                <td class="px-2 py-1.5 text-center">{{ s.exams }}</td><td class="px-2 py-1.5 text-center">{{ s.students }}</td>
                                <td class="px-4 py-1.5"><div class="flex items-center gap-2"><div class="h-1.5 flex-1 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-1.5 rounded-full" :class="bar(s.average)" :style="{ width: (s.average ?? 0) + '%' }" /></div><span class="w-10 text-end text-xs">{{ s.average ?? '—' }}</span></div></td>
                                <td class="px-2 py-1.5 text-center">{{ s.pass_rate ?? '—' }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="font-semibold">{{ t('exam.dash_bank', 'جودة بنك الأسئلة') }}</p>
                <p class="text-xs text-gray-500">{{ t('exam.dash_bank_hint', ':u من :n سؤالاً استُعمل في امتحانات فعلية.', { u: totals.used, n: totals.questions }) }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span v-for="(n, f) in flags" :key="f" class="rounded-full px-3 py-1 text-xs" :class="f === 'check_key' || f === 'exposed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">{{ t('exam.bank_flag_' + f, FLAGS[f]) }}: {{ n }}</span>
                    <span v-if="!Object.keys(flags).length" class="text-xs text-gray-500">{{ t('exam.dash_no_flags', 'لا تنبيهات.') }}</span>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
