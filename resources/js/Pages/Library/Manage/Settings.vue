<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import NumberStepper from '@/Components/NumberStepper.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    AcademicCapIcon, ArrowPathIcon, BanknotesIcon, BellAlertIcon, BellIcon, BookmarkIcon, BookOpenIcon, BriefcaseIcon,
    CalendarDaysIcon, CalendarIcon, ClockIcon, ExclamationTriangleIcon, InformationCircleIcon, PlusIcon, Squares2X2Icon,
    UserGroupIcon, XMarkIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ rules: Object, holidays: Array });

const form = useForm(JSON.parse(JSON.stringify(props.rules)));
const save = () => form.post(route('library.manage.settings.update'), { preserveScroll: true, onSuccess: () => form.defaults() });
const undo = () => form.reset();

const holiday = useForm({ from: '', to: '', name: '' });
const addHoliday = () => holiday.post(route('library.manage.holidays.store'), { preserveScroll: true, onSuccess: () => holiday.reset() });
const removeHoliday = (h) => router.delete(route('library.manage.holidays.destroy', h.id), { preserveScroll: true });

const TYPES = {
    student: { label: t('library.type.student', 'طالب'), icon: AcademicCapIcon, tone: 'from-indigo-500 to-indigo-600', soft: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' },
    teacher: { label: t('library.type.teacher', 'تدريسي'), icon: BriefcaseIcon, tone: 'from-emerald-500 to-emerald-600', soft: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' },
    staff: { label: t('library.type.staff', 'موظف'), icon: UserGroupIcon, tone: 'from-amber-500 to-amber-600', soft: 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' },
};
const FIELDS = [
    { key: 'loan_days', icon: CalendarDaysIcon, label: t('library.rule.loan_days_short', 'مدة الاستعارة'), unit: t('library.unit.day', 'يوم'), max: 365 },
    { key: 'max_loans', icon: BookOpenIcon, label: t('library.rule.max_loans_short', 'أقصى عدد كتب معاً'), unit: t('library.unit.book', 'كتاب'), max: 100 },
    { key: 'renewals', icon: ArrowPathIcon, label: t('library.rule.renewals', 'مرات التجديد'), unit: t('library.unit.times', 'مرة'), max: 20 },
    { key: 'fine_per_day', icon: BanknotesIcon, label: t('library.rule.fine_short', 'الغرامة اليومية'), unit: t('library.unit.iqd', 'د.ع'), max: 100000, step: 250 },
];
const GENERAL = [
    { key: 'hold_hours', icon: BookmarkIcon, label: t('library.rule.hold_hours_short', 'الاحتفاظ بالكتاب المحجوز'), hint: t('library.rule.hold_hours_hint', 'بعد إرجاع الكتاب يُحجز للقارئ التالي هذه المدة، ثم ينتقل لمن بعده.'), unit: t('library.unit.hour', 'ساعة'), max: 720, step: 12 },
    { key: 'remind_days_before', icon: BellIcon, label: t('library.rule.remind_before_short', 'تذكير قبل موعد الإرجاع'), hint: t('library.rule.remind_before_hint', 'يصل القارئ بريد تذكير قبل الموعد بهذا العدد من الأيام.'), unit: t('library.unit.day', 'يوم'), max: 30 },
    { key: 'remind_every_days', icon: BellAlertIcon, label: t('library.rule.remind_every_short', 'تكرار تذكير المتأخر'), hint: t('library.rule.remind_every_hint', 'بعد تجاوز الموعد يتكرر التذكير كل هذا العدد من الأيام.'), unit: t('library.unit.day', 'يوم'), max: 30 },
    { key: 'lost_after_days', icon: ExclamationTriangleIcon, label: t('library.rule.lost_after_short', 'اعتبار الكتاب مفقوداً'), hint: t('library.rule.lost_after_hint', 'إذا تأخر الكتاب هذه المدة يُعلَّم مفقوداً ويُخرج من الرفوف المتاحة.'), unit: t('library.unit.day_late', 'يوم تأخير'), max: 365 },
];

function summary(type) {
    const r = form.types[type];
    const fine = r.fine_per_day > 0 ? `وغرامة التأخير ${Number(r.fine_per_day).toLocaleString('en-US')} د.ع لكل يوم` : 'بدون غرامة تأخير';
    const renew = r.renewals > 0 ? `ويمكنه التجديد ${r.renewals} ${r.renewals === 1 ? 'مرة' : 'مرات'}` : 'بدون تجديد';
    return `يستعير حتى ${r.max_loans} ${r.max_loans === 1 ? 'كتاب' : 'كتب'} لمدة ${r.loan_days} يوم، ${renew}، ${fine}.`;
}

const holidaysByMonth = computed(() => {
    const groups = {};
    for (const h of props.holidays) {
        const key = h.date.slice(0, 7);
        (groups[key] ??= []).push(h);
    }
    return groups;
});
const MONTHS = ['كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'];
const monthLabel = (key) => `${MONTHS[Number(key.slice(5, 7)) - 1]} ${key.slice(0, 4)}`;
</script>

<template>
    <Head :title="t('library.tab.settings', 'قواعد الإعارة')" />
    <AppLayout>
        <template #header><h2 class="text-lg font-semibold">{{ t('library.tab.settings', 'قواعد الإعارة') }}</h2></template>

        <div class="space-y-6 p-4 pb-0 sm:p-6 sm:pb-0">
            <LibraryTabs />

            <!-- Rules per reader type -->
            <section>
                <div class="mb-3 flex items-start gap-2">
                    <Squares2X2Icon class="mt-0.5 h-5 w-5 text-[var(--accent-600)]" />
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ t('library.rules.by_type', 'قواعد كل فئة من القرّاء') }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('library.rules.by_type_hint', 'تُطبَّق على كل إعارة جديدة حسب فئة القارئ. استخدم + و − أو اكتب الرقم مباشرة.') }}</p>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-3">
                    <article v-for="(meta, type) in TYPES" :key="type" class="flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <header class="flex items-center gap-3 bg-gradient-to-l p-4 text-white" :class="meta.tone">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/20"><component :is="meta.icon" class="h-6 w-6" /></span>
                            <h4 class="text-lg font-semibold">{{ meta.label }}</h4>
                        </header>
                        <div class="flex-1 divide-y divide-gray-100 dark:divide-gray-700">
                            <div v-for="f in FIELDS" :key="f.key" class="flex items-center justify-between gap-3 px-4 py-3">
                                <span class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                    <component :is="f.icon" class="h-5 w-5 shrink-0 text-gray-400" />
                                    {{ f.label }}
                                </span>
                                <NumberStepper v-model="form.types[type][f.key]" :max="f.max" :step="f.step ?? 1" :unit="f.unit" />
                            </div>
                        </div>
                        <footer class="flex gap-2 p-4 text-sm" :class="meta.soft">
                            <InformationCircleIcon class="mt-0.5 h-5 w-5 shrink-0" />
                            <p>{{ summary(type) }}</p>
                        </footer>
                    </article>
                </div>
            </section>

            <!-- General -->
            <section class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-start gap-2 border-b border-gray-100 p-4 dark:border-gray-700">
                    <ClockIcon class="mt-0.5 h-5 w-5 text-[var(--accent-600)]" />
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ t('library.rules.general', 'الحجز والتذكير والفقدان') }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('library.rules.general_hint', 'إعدادات تخص جميع القرّاء.') }}</p>
                    </div>
                </div>
                <div class="grid divide-y divide-gray-100 md:grid-cols-2 md:divide-y-0 dark:divide-gray-700">
                    <div v-for="(g, i) in GENERAL" :key="g.key" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" :class="{ 'md:border-t md:border-gray-100 md:dark:border-gray-700': i > 1 }">
                        <div class="flex gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"><component :is="g.icon" class="h-5 w-5" /></span>
                            <div>
                                <p class="font-medium text-gray-800 dark:text-gray-100">{{ g.label }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ g.hint }}</p>
                            </div>
                        </div>
                        <NumberStepper v-model="form.general[g.key]" :max="g.max" :step="g.step ?? 1" :unit="g.unit" class="self-end sm:self-auto" />
                    </div>
                </div>
                <p class="flex items-center gap-2 border-t border-gray-100 p-4 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <CalendarIcon class="h-4 w-4" /> {{ t('library.rule.friday', 'أيام الجمعة لا تُحسب في الغرامة ولا يقع فيها موعد الإرجاع.') }}
                </p>
            </section>

            <!-- Holidays -->
            <section class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-start gap-2 border-b border-gray-100 p-4 dark:border-gray-700">
                    <CalendarDaysIcon class="mt-0.5 h-5 w-5 text-[var(--accent-600)]" />
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ t('library.holidays', 'العطل الرسمية') }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('library.holidays_hint', 'لا يقع موعد الإرجاع في العطلة ولا تُحسب أيامها في الغرامة.') }}</p>
                    </div>
                </div>
                <form @submit.prevent="addHoliday" class="grid gap-3 p-4 sm:grid-cols-[1fr_1fr_2fr_auto] sm:items-end">
                    <label class="text-sm text-gray-600 dark:text-gray-300">{{ t('library.from', 'من') }}
                        <input v-model="holiday.from" type="date" required class="mt-1 block w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    </label>
                    <label class="text-sm text-gray-600 dark:text-gray-300">{{ t('library.to', 'إلى (اختياري)') }}
                        <input v-model="holiday.to" type="date" class="mt-1 block w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    </label>
                    <label class="text-sm text-gray-600 dark:text-gray-300">{{ t('library.holiday_name', 'المناسبة') }}
                        <input v-model="holiday.name" :placeholder="t('library.holiday_ph', 'مثال: عطلة عيد الفطر')" class="mt-1 block w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    </label>
                    <button :disabled="holiday.processing" class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800 dark:bg-white dark:text-gray-900">
                        <PlusIcon class="h-4 w-4" /> {{ t('action.add', 'إضافة') }}
                    </button>
                </form>
                <div class="space-y-4 border-t border-gray-100 p-4 dark:border-gray-700">
                    <p v-if="!holidays.length" class="text-center text-sm text-gray-400">{{ t('library.no_holidays', 'لا توجد عطل مسجّلة.') }}</p>
                    <div v-for="(list, month) in holidaysByMonth" :key="month">
                        <p class="mb-2 text-xs font-semibold text-gray-500">{{ monthLabel(month) }}</p>
                        <div class="flex flex-wrap gap-2">
                            <span v-for="h in list" :key="h.id" class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 py-1.5 pe-1.5 ps-3 text-sm dark:border-gray-600 dark:bg-gray-700/50">
                                <span class="font-semibold" dir="ltr">{{ h.date.slice(8, 10) }}/{{ h.date.slice(5, 7) }}</span>
                                <span v-if="h.name" class="text-gray-600 dark:text-gray-300">{{ h.name }}</span>
                                <button type="button" @click="removeHoliday(h)" class="rounded-lg p-1 text-gray-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-900/30" :aria-label="t('action.delete', 'حذف')"><XMarkIcon class="h-4 w-4" /></button>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Save bar -->
            <div class="sticky bottom-0 z-10 -mx-4 border-t sm:-mx-6 border-gray-200 bg-white/95 backdrop-blur dark:border-gray-700 dark:bg-gray-900/95">
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <p class="text-sm" :class="form.isDirty ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-gray-500'">
                        {{ form.isDirty ? t('library.rules.unsaved', 'لديك تغييرات غير محفوظة') : (form.recentlySuccessful ? t('library.rules.saved', 'تم الحفظ') : t('library.rules.no_changes', 'لا توجد تغييرات')) }}
                    </p>
                    <div class="flex gap-2">
                        <button v-if="form.isDirty" type="button" @click="undo" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.undo', 'تراجع') }}</button>
                        <button type="button" @click="save" :disabled="form.processing || !form.isDirty" class="rounded-xl bg-[var(--accent-600)] px-6 py-2 text-sm font-semibold text-white disabled:opacity-40">{{ t('action.save', 'حفظ') }}</button>
                    </div>
                </div>
            </div>
        </div>

    </AppLayout>
</template>
