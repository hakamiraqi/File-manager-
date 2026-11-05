<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
const props = defineProps({ rules: Object });
const form = useForm(JSON.parse(JSON.stringify(props.rules)));
const typeLabel = (type) => ({ student: t('library.type.student', 'طالب'), teacher: t('library.type.teacher', 'تدريسي'), staff: t('library.type.staff', 'موظف') })[type] ?? type;
const rows = [
    ['loan_days', () => t('library.rule.loan_days', 'مدة الاستعارة (يوم)')],
    ['max_loans', () => t('library.rule.max_loans', 'أقصى عدد كتب بنفس الوقت')],
    ['renewals', () => t('library.rule.renewals', 'مرات التجديد')],
    ['fine_per_day', () => t('library.rule.fine', 'الغرامة اليومية (د.ع)')],
];
const general = [
    ['hold_hours', () => t('library.rule.hold_hours', 'مدة الاحتفاظ بالكتاب المحجوز (ساعة)')],
    ['remind_days_before', () => t('library.rule.remind_before', 'التذكير قبل الموعد بـ (يوم)')],
    ['remind_every_days', () => t('library.rule.remind_every', 'تكرار تذكير المتأخر كل (يوم)')],
    ['lost_after_days', () => t('library.rule.lost_after', 'اعتبار الكتاب مفقوداً بعد تأخر (يوم)')],
];
const save = () => form.post(route('library.manage.settings.update'), { preserveScroll: true });
</script>

<template>
    <Head :title="t('library.tab.settings', 'قواعد الإعارة')" />
    <AppLayout>
        <template #header><h2 class="text-lg font-semibold">{{ t('library.tab.settings', 'قواعد الإعارة') }}</h2></template>
        <form @submit.prevent="save" class="space-y-5 p-4 sm:p-6">
            <LibraryTabs />
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-900/40">
                        <tr><th class="px-3 py-2 text-start">{{ t('library.rule.rule', 'القاعدة') }}</th><th v-for="(v, type) in form.types" :key="type" class="px-3 py-2 text-start">{{ typeLabel(type) }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="[key, label] in rows" :key="key">
                            <td class="px-3 py-2">{{ label() }}</td>
                            <td v-for="(v, type) in form.types" :key="type" class="px-3 py-2"><input v-model.number="form.types[type][key]" type="number" min="0" class="w-24 rounded-md border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-2 dark:border-gray-700 dark:bg-gray-800">
                <label v-for="[key, label] in general" :key="key" class="flex items-center justify-between gap-3 text-sm">
                    {{ label() }} <input v-model.number="form.general[key]" type="number" min="0" class="w-24 rounded-md border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" />
                </label>
            </div>
            <p class="text-xs text-gray-500">{{ t('library.rule.friday', 'أيام الجمعة لا تُحسب في الغرامة ولا يقع فيها موعد الإرجاع.') }}</p>
            <button :disabled="form.processing" class="rounded-lg bg-[var(--accent-600)] px-5 py-2 text-sm font-medium text-white">{{ t('action.save', 'حفظ') }}</button>
        </form>
    </AppLayout>
</template>
