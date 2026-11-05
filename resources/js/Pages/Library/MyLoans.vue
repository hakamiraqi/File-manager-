<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import BookCover from '@/Components/Library/BookCover.vue';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
defineProps({ member: Object, active: Array, history: Array, finesDue: Number });
const errors = computed(() => usePage().props.errors);
const money = (n) => `${Number(n || 0).toLocaleString('en-US')} ${t('library.currency', 'د.ع')}`;
const renew = (l) => router.post(route('library.loans.renew', l.id), {}, { preserveScroll: true });
const daysLeft = (l) => Math.ceil((new Date(l.due_at) - new Date()) / 86400000);
</script>

<template>
    <Head :title="t('library.tab.my_loans', 'استعاراتي')" />
    <AppLayout>
        <template #header><h2 class="text-lg font-semibold">{{ t('library.tab.my_loans', 'استعاراتي') }}</h2></template>

        <div class="space-y-5 p-4 sm:p-6">
            <LibraryTabs />
            <p v-if="member" class="text-sm text-gray-600 dark:text-gray-300">{{ t('library.your_card', 'رقم بطاقتك') }}: <span class="font-mono" dir="ltr">{{ member.card_number }}</span></p>
            <p v-if="errors.code" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ errors.code }}</p>
            <p v-if="finesDue" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ t('library.fines_due', 'غرامات مستحقة') }}: {{ money(finesDue) }} — {{ t('library.pay_at_desk', 'تُدفع لأمين المكتبة') }}</p>

            <section>
                <h3 class="mb-2 font-semibold">{{ t('library.current_loans_mine', 'الكتب التي عندي') }} ({{ active.length }})</h3>
                <p v-if="!active.length" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-600">
                    {{ t('library.no_active_loans', 'لا توجد كتب مُعارة لك حالياً.') }} <Link :href="route('library.index')" class="text-[var(--accent-600)] hover:underline">{{ t('library.browse', 'تصفح الفهرس') }}</Link>
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div v-for="l in active" :key="l.id" class="flex gap-3 rounded-xl border bg-white p-3 dark:bg-gray-800" :class="l.is_overdue ? 'border-red-300' : 'border-gray-200 dark:border-gray-700'">
                        <BookCover :book="l.copy?.book" size="sm" class="w-14 shrink-0" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium" dir="auto">{{ l.copy?.book?.title }}</p>
                            <p class="text-sm" :class="l.is_overdue ? 'font-medium text-red-600' : daysLeft(l) <= 3 ? 'text-amber-700' : 'text-gray-600 dark:text-gray-300'">
                                {{ t('library.due', 'يُرجع') }} {{ l.due_at.slice(0, 10) }} ·
                                <template v-if="l.is_overdue">{{ t('library.late_days', 'متأخر :n يوم', { n: l.days_overdue }) }}<template v-if="l.fine_now"> · {{ money(l.fine_now) }}</template></template>
                                <template v-else>{{ t('library.days_left', 'باقي :n يوم', { n: daysLeft(l) }) }}</template>
                            </p>
                            <button v-if="!l.is_overdue" @click="renew(l)" class="mt-2 rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">{{ t('library.renew', 'تجديد') }}</button>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="history.length">
                <h3 class="mb-2 font-semibold">{{ t('library.history', 'السجل') }}</h3>
                <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white text-sm dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
                    <div v-for="l in history" :key="l.id" class="flex justify-between gap-3 px-3 py-2">
                        <span class="truncate" dir="auto">{{ l.copy?.book?.title }}</span>
                        <span class="shrink-0 text-xs text-gray-500">{{ l.issued_at.slice(0, 10) }} → {{ l.returned_at.slice(0, 10) }}<template v-if="l.fine_now"> · {{ money(l.fine_now) }}</template></span>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
