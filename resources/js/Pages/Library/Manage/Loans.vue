<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import { useTrans } from '@/Composables/useTrans';
import { CheckCircleIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ loans: Object, filters: Object, counts: Object, holds: Array });
const cancelHold = (h) => router.delete(route('library.holds.destroy', h.id), { preserveScroll: true });
const q = ref(props.filters.q ?? '');
const money = (n) => `${Number(n || 0).toLocaleString('en-US')} ${t('library.currency', 'د.ع')}`;
const go = (filter) => router.get(route('library.manage.loans'), { filter, q: q.value || undefined }, { preserveState: true });
const post = (name, loan) => router.post(route(name, loan.id), {}, { preserveScroll: true });
const tabs = [
    ['active', () => t('library.loans.active', 'الحالية'), 'active'],
    ['overdue', () => t('library.loans.overdue', 'المتأخرة'), 'overdue'],
    ['fines', () => t('library.loans.fines', 'غرامات غير مدفوعة'), 'fines'],
    ['holds', () => t('library.loans.holds', 'الحجوزات'), 'holds'],
    ['returned', () => t('library.loans.returned', 'المُرجعة'), null],
];
</script>

<template>
    <Head :title="t('library.tab.loans', 'الاستعارات')" />
    <AppLayout>
        <template #header><h2 class="text-lg font-semibold">{{ t('library.tab.loans', 'الاستعارات') }}</h2></template>

        <div class="space-y-4 p-4 sm:p-6">
            <LibraryTabs />
            <div class="flex flex-wrap items-center gap-2">
                <button v-for="[key, label, count] in tabs" :key="key" @click="go(key)" class="rounded-full border px-3 py-1 text-sm"
                    :class="filters.filter === key ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white' : 'border-gray-300 dark:border-gray-600'">
                    {{ label() }}<template v-if="count"> ({{ counts[count] }})</template>
                </button>
                <div class="relative ms-auto w-full max-w-xs">
                    <MagnifyingGlassIcon class="pointer-events-none absolute start-2.5 top-2.5 h-4 w-4 text-gray-400" />
                    <input v-model="q" type="search" @keyup.enter="go(filters.filter)" :placeholder="t('library.loans.search', 'قارئ، كتاب أو باركود')" class="w-full rounded-lg border-gray-300 py-1.5 ps-8 text-sm dark:border-gray-600 dark:bg-gray-700" />
                </div>
            </div>

            <div v-if="filters.filter === 'holds'" class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white text-sm dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
                <p v-if="!holds.length" class="p-6 text-center text-gray-400">{{ t('library.no_holds', 'لا توجد حجوزات.') }}</p>
                <div v-for="h in holds" :key="h.id" class="flex flex-wrap items-center gap-3 px-3 py-2.5">
                    <span class="min-w-0 flex-1"><span class="font-medium" dir="auto">{{ h.book?.title }}</span> — {{ h.member?.name }} <span class="text-xs text-gray-400" dir="ltr">{{ h.member?.card_number }}</span></span>
                    <span v-if="h.status === 'ready'" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">{{ t('library.hold_shelf', 'جاهز: النسخة :code حتى :date', { code: h.copy?.barcode, date: h.expires_at?.slice(0, 16).replace('T', ' ') }) }}</span>
                    <span v-else class="rounded-full bg-gray-100 px-2 py-0.5 text-xs">{{ t('library.hold_waiting_short', 'بالانتظار') }}</span>
                    <button @click="cancelHold(h)" class="text-xs text-red-600 hover:underline">{{ t('library.cancel_hold', 'إلغاء الحجز') }}</button>
                </div>
            </div>

            <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ t('library.book', 'الكتاب') }}</th>
                            <th class="px-3 py-2 text-start">{{ t('library.reader', 'القارئ') }}</th>
                            <th class="px-3 py-2 text-start">{{ t('library.issued_on', 'تاريخ الإعارة') }}</th>
                            <th class="px-3 py-2 text-start">{{ t('library.due', 'يُرجع') }}</th>
                            <th class="px-3 py-2 text-start">{{ t('library.fine', 'غرامة') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-if="!loans.data.length"><td colspan="6" class="p-6 text-center text-gray-400">{{ t('library.loans.none', 'لا توجد استعارات.') }}</td></tr>
                        <tr v-for="l in loans.data" :key="l.id" :class="l.is_overdue ? 'bg-red-50/60 dark:bg-red-900/20' : ''">
                            <td class="px-3 py-2"><p class="font-medium" dir="auto">{{ l.copy?.book?.title }}</p><p class="text-xs text-gray-500" dir="ltr">{{ l.copy?.barcode }}</p></td>
                            <td class="px-3 py-2"><p>{{ l.member?.name }}</p><p class="text-xs text-gray-500" dir="ltr">{{ l.member?.card_number }}</p></td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ l.issued_at?.slice(0, 10) }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <template v-if="l.returned_at">{{ t('library.returned_on', 'أُرجع') }} {{ l.returned_at.slice(0, 10) }}</template>
                                <template v-else>{{ l.due_at?.slice(0, 10) }}<span v-if="l.is_overdue" class="block text-xs font-medium text-red-600">{{ t('library.late_days', 'متأخر :n يوم', { n: l.days_overdue }) }}</span></template>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <template v-if="l.fine_waived">{{ t('library.waived', 'مُعفى') }}</template>
                                <span v-else-if="l.fine_paid_at" class="inline-flex items-center gap-1">{{ money(l.fine_amount) }} <CheckCircleIcon class="h-4 w-4 text-emerald-600" /></span>
                                <template v-else-if="l.fine_now">{{ money(l.fine_now) }}</template>
                                <template v-else>—</template>
                            </td>
                            <td class="px-3 py-2 text-end whitespace-nowrap">
                                <button v-if="!l.returned_at && !l.is_overdue" @click="post('library.loans.renew', l)" class="rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600">{{ t('library.renew', 'تجديد') }}</button>
                                <template v-if="l.returned_at && l.fine_amount && !l.fine_paid_at && !l.fine_waived">
                                    <button @click="post('library.manage.loans.pay', l)" class="rounded bg-emerald-600 px-2 py-1 text-xs text-white">{{ t('library.paid', 'دُفعت') }}</button>
                                    <button @click="post('library.manage.loans.waive', l)" class="ms-1 rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600">{{ t('library.waive', 'إعفاء') }}</button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination v-if="filters.filter !== 'holds'" :links="loans.links" />
        </div>
    </AppLayout>
</template>
