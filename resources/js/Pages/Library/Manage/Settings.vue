<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import NumberStepper from '@/Components/NumberStepper.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    AcademicCapIcon, ArrowPathIcon, BanknotesIcon, BellAlertIcon, BellIcon, BookmarkIcon, BookOpenIcon, BriefcaseIcon,
    CalendarDaysIcon, CalendarIcon, GlobeAltIcon, TrashIcon, LinkIcon, CheckCircleIcon, ClockIcon, ExclamationTriangleIcon, InformationCircleIcon, PlusIcon, Squares2X2Icon,
    UserGroupIcon, XMarkIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({
    rules: Object, holidays: Array, sruServers: { type: Array, default: () => [] }, sruDefaults: { type: Array, default: () => [] },
    koha: { type: Object, default: () => ({}) }, canEditKoha: { type: Boolean, default: false }, marcOrg: { type: String, default: '' },
});

// «الربط مع Koha» (الخيار ب): Koha lends, FileNest is the front.
const kohaOn = computed(() => props.koha.koha_enabled === '1');
const kohaForm = useForm({
    koha_enabled: props.koha.koha_enabled === '1',
    koha_url: props.koha.koha_url ?? '',
    koha_auth: props.koha.koha_auth ?? 'oauth',
    koha_client_id: props.koha.koha_client_id ?? '',
    koha_client_secret: '',
    koha_username: props.koha.koha_username ?? '',
    koha_password: '',
    koha_library: props.koha.koha_library ?? '',
    koha_match: props.koha.koha_match ?? 'cardnumber',
    koha_create_patrons: props.koha.koha_create_patrons === '1',
    koha_categories: { student: 'ST', teacher: 'T', staff: 'S', ...(props.koha.koha_categories ?? {}) },
    koha_item_type: props.koha.koha_item_type ?? 'BK',
    koha_push: props.koha.koha_push !== '0',
});
const saveKoha = () => kohaForm.post(route('library.manage.koha.save'), { preserveScroll: true, onSuccess: () => { kohaForm.koha_client_secret = ''; kohaForm.koha_password = ''; kohaForm.defaults(); } });
const kohaTest = ref(null);
const kohaBusy = ref(false);
const kohaSync = ref(props.koha.last_sync ?? {});
async function testKoha() {
    kohaBusy.value = true;
    kohaTest.value = null;
    try {
        kohaTest.value = (await window.axios.post(route('library.manage.koha.test'))).data;
    } catch (e) {
        kohaTest.value = { ok: false, message: e.response?.data?.message ?? 'تعذّر الاتصال.' };
    } finally {
        kohaBusy.value = false;
    }
}
async function syncKoha(full = false) {
    kohaBusy.value = true;
    try {
        kohaTest.value = { ok: true, message: (await window.axios.post(route('library.manage.koha.sync'), { full })).data.message };
        setTimeout(refreshKohaStatus, 15000);
    } catch (e) {
        kohaTest.value = { ok: false, message: e.response?.data?.message ?? 'تعذّرت المزامنة.' };
    } finally {
        kohaBusy.value = false;
    }
}
async function refreshKohaStatus() {
    try { kohaSync.value = (await window.axios.get(route('library.manage.koha.status'))).data.last_sync ?? {}; } catch { /* the page still works */ }
}
const syncLine = computed(() => {
    const s = kohaSync.value;
    if (!s?.at) return 'لم تجرِ مزامنة بعد.';
    const when = new Date(s.at).toLocaleString('ar-IQ', { dateStyle: 'medium', timeStyle: 'short' });
    if (s.error) return `آخر محاولة ${when}: فشلت — ${s.error}`;
    const c = s.counts ?? {};
    return `آخر مزامنة ${when}: ${c.books ?? 0} كتاب، ${c.copies ?? 0} نسخة، ${c.loans ?? 0} إعارة، ${c.returned ?? 0} إرجاع، ${c.holds ?? 0} حجز، ${c.readers ?? 0} قارئ جديد.`;
});

const form = useForm(JSON.parse(JSON.stringify(props.rules)));
const save = () => form.post(route('library.manage.settings.update'), { preserveScroll: true, onSuccess: () => form.defaults() });
const undo = () => form.reset();

// World catalogues (SRU / Z39.50) used by "copy cataloguing" in the book form.
const sru = useForm({ servers: JSON.parse(JSON.stringify(props.sruServers)), marc_org: props.marcOrg });
const saveSru = () => sru.post(route('library.manage.sru.save'), { preserveScroll: true, onSuccess: () => sru.defaults() });
const addSru = () => sru.servers.push({ name: '', url: 'https://', isbn: 'bath.isbn', title: 'dc.title', author: 'dc.creator', schema: 'marcxml', on: true });
const resetSru = () => { sru.servers = JSON.parse(JSON.stringify(props.sruDefaults)); };

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

            <!-- Koha link (option B) -->
            <section class="rounded-2xl border bg-white shadow-sm dark:bg-gray-800" :class="kohaOn ? 'border-[var(--accent-500)]' : 'border-gray-200 dark:border-gray-700'">
                <div class="flex items-start gap-2 border-b border-gray-100 p-4 dark:border-gray-700">
                    <LinkIcon class="mt-0.5 h-5 w-5 text-[var(--accent-600)]" />
                    <div class="flex-1">
                        <h3 class="font-semibold text-gray-900 dark:text-white">
                            {{ t('library.koha.title', 'الربط مع Koha') }}
                            <span v-if="kohaOn" class="ms-2 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ t('library.koha.on', 'يعمل') }}</span>
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ t('library.koha.hint', 'عند التشغيل تتم الإعارة والإرجاع والتجديد والحجز في Koha وبقواعده وغراماته، ويبقى FileNest الواجهة: الموقع والتطبيق والمكتب وRFID والخريطة. الكتب والنسخ والإعارات تتزامن من Koha كل 10 دقائق، والكتب المفهرسة هنا تُرسل إلى Koha.') }}
                        </p>
                    </div>
                </div>
                <div v-if="kohaOn" class="mx-4 mt-4 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                    <InformationCircleIcon class="mt-0.5 h-5 w-5 shrink-0" />
                    {{ t('library.koha.rules_note', 'قواعد الإعارة والغرامات أدناه لا تُطبَّق الآن: Koha هو من يقرر المدة والحدود والغرامات.') }}
                </div>
                <details v-if="canEditKoha" class="mx-4 mt-4 rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
                    <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">{{ t('library.koha.needs', 'ما تحتاجه من Koha') }}</summary>
                    <ol class="mt-2 list-decimal space-y-1 ps-5 text-gray-600 dark:text-gray-300">
                        <li>{{ t('library.koha.need1', 'Koha إصدار 23.11 أو أحدث (الإرجاع عبر الواجهة يحتاج إصداراً أحدث، وإلا يُرجَع الكتاب من واجهة Koha).') }}</li>
                        <li>{{ t('library.koha.need2', 'حساب موظف للربط بصلاحيات: circulate، catalogue، editcatalogue، borrowers، reserveforothers.') }}</li>
                        <li>{{ t('library.koha.need3', 'إما مفتاح API: يفعّل المسؤول RESTOAuth2ClientCredentials، ثم من صفحة الموظف More ← Manage API keys ← Generate (يحتاج صلاحية edit_borrowers).') }}</li>
                        <li>{{ t('library.koha.need4', 'أو اسم المستخدم وكلمة المرور: يفعّل المسؤول RESTBasicAuth من الإدارة ← تفضيلات النظام ← Web services.') }}</li>
                        <li>{{ t('library.koha.need5', 'أرقام بطاقات القراء في Koha مطابقة لأرقام البطاقات هنا (أو اختر المطابقة بالبريد).') }}</li>
                    </ol>
                </details>
                <form v-if="canEditKoha" @submit.prevent="saveKoha" class="grid gap-4 p-4 sm:grid-cols-2">
                    <label class="flex items-center gap-2 text-sm font-medium sm:col-span-2">
                        <input type="checkbox" v-model="kohaForm.koha_enabled" class="rounded" />
                        {{ t('library.koha.enable', 'تشغيل الربط: Koha يدير الإعارة') }}
                    </label>
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.koha.url', 'عنوان Koha (واجهة الموظفين)') }}</span>
                        <input v-model="kohaForm.koha_url" dir="ltr" placeholder="https://koha.university.edu.iq" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <span v-if="kohaForm.errors.koha_url" class="mt-1 block text-xs text-red-600">{{ kohaForm.errors.koha_url }}</span>
                    </label>
                    <div class="text-sm sm:col-span-2">
                        <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.koha.auth', 'طريقة الدخول إلى Koha') }}</span>
                        <div class="flex gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-700/50">
                            <button type="button" class="flex-1 rounded-md px-3 py-1.5" :class="kohaForm.koha_auth === 'oauth' ? 'bg-white shadow dark:bg-gray-800' : 'text-gray-500'" @click="kohaForm.koha_auth = 'oauth'">{{ t('library.koha.auth_key', 'مفتاح API') }}</button>
                            <button type="button" class="flex-1 rounded-md px-3 py-1.5" :class="kohaForm.koha_auth === 'basic' ? 'bg-white shadow dark:bg-gray-800' : 'text-gray-500'" @click="kohaForm.koha_auth = 'basic'">{{ t('library.koha.auth_basic', 'اسم مستخدم وكلمة مرور') }}</button>
                        </div>
                        <span v-if="kohaForm.koha_auth === 'basic'" class="mt-1 block text-xs text-gray-500">{{ t('library.koha.basic_hint', 'حساب موظف في Koha. يتطلب أن يفعّل مسؤول Koha التفضيل RESTBasicAuth.') }}</span>
                    </div>
                    <template v-if="kohaForm.koha_auth === 'oauth'">
                        <label class="text-sm">
                            <span class="mb-1 block text-gray-600 dark:text-gray-300">Client ID</span>
                            <input v-model="kohaForm.koha_client_id" dir="ltr" autocomplete="off" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-gray-600 dark:text-gray-300">Client Secret</span>
                            <input v-model="kohaForm.koha_client_secret" type="password" dir="ltr" autocomplete="new-password" :placeholder="koha.has_secret ? t('library.koha.secret_saved', 'محفوظ — اتركه فارغاً للإبقاء عليه') : ''" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        </label>
                    </template>
                    <template v-else>
                        <label class="text-sm">
                            <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.koha.username', 'اسم المستخدم في Koha') }}</span>
                            <input v-model="kohaForm.koha_username" dir="ltr" autocomplete="off" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.koha.password', 'كلمة المرور') }}</span>
                            <input v-model="kohaForm.koha_password" type="password" dir="ltr" autocomplete="new-password" :placeholder="koha.has_password ? t('library.koha.secret_saved', 'محفوظ — اتركه فارغاً للإبقاء عليه') : ''" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        </label>
                    </template>
                    <label class="text-sm">
                        <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.koha.library', 'رمز المكتبة (الفرع) في Koha') }}</span>
                        <input v-model="kohaForm.koha_library" dir="ltr" list="koha-libraries" placeholder="DENT" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <datalist id="koha-libraries"><option v-for="l in kohaTest?.libraries ?? []" :key="l.code" :value="l.code">{{ l.name }}</option></datalist>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.koha.match', 'يُعرف القارئ في Koha من') }}</span>
                        <select v-model="kohaForm.koha_match" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option value="cardnumber">{{ t('library.koha.match_card', 'رقم البطاقة') }}</option>
                            <option value="email">{{ t('library.koha.match_email', 'البريد الإلكتروني') }}</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" v-model="kohaForm.koha_create_patrons" class="rounded" />
                        {{ t('library.koha.create_patrons', 'القارئ غير الموجود في Koha يُضاف إليه تلقائياً بهذه الفئات:') }}
                    </label>
                    <div class="grid grid-cols-3 gap-2 sm:col-span-2" dir="ltr">
                        <label v-for="(info, type) in TYPES" :key="type" class="text-xs text-gray-500">
                            {{ info.label }}
                            <input v-model="kohaForm.koha_categories[type]" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        </label>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="kohaForm.koha_push" class="rounded" />
                        {{ t('library.koha.push', 'الكتب المفهرسة في FileNest تُرسل إلى Koha') }}
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.koha.item_type', 'نوع المادة للنسخ المرسلة') }}</span>
                        <input v-model="kohaForm.koha_item_type" dir="ltr" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    </label>
                    <div class="flex flex-wrap items-center justify-end gap-2 sm:col-span-2">
                        <button type="button" :disabled="kohaBusy" @click="testKoha" class="rounded-xl border border-gray-300 px-4 py-2 text-sm disabled:opacity-40 dark:border-gray-600">{{ t('library.koha.test', 'اختبار الاتصال') }}</button>
                        <button :disabled="kohaForm.processing || !kohaForm.isDirty" class="rounded-xl bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white disabled:opacity-40">{{ t('action.save', 'حفظ') }}</button>
                    </div>
                </form>
                <div class="space-y-2 p-4 pt-0 text-sm" :class="{ 'pt-4': !canEditKoha }">
                    <p v-if="!canEditKoha && !kohaOn" class="text-gray-500">{{ t('library.koha.admin_only', 'يضبط مدير النظام هذا الربط.') }}</p>
                    <div v-if="kohaTest" class="flex items-start gap-2 rounded-xl p-3" :class="kohaTest.ok ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200' : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300'">
                        <component :is="kohaTest.ok ? CheckCircleIcon : ExclamationTriangleIcon" class="mt-0.5 h-5 w-5 shrink-0" />
                        <div>
                            {{ kohaTest.message }}
                            <div v-if="kohaTest.missing?.length" class="mt-1 text-xs">{{ t('library.koha.missing', 'غير متوفر في إصدار Koha لديكم (يُنجز من واجهة Koha):') }} <span dir="ltr">{{ kohaTest.missing.join(', ') }}</span></div>
                        </div>
                    </div>
                    <div v-if="kohaOn" class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                        <span :class="kohaSync?.error ? 'text-red-600' : 'text-gray-600 dark:text-gray-300'">{{ syncLine }}</span>
                        <div class="flex gap-2">
                            <button type="button" :disabled="kohaBusy" @click="syncKoha(false)" class="flex items-center gap-1 rounded-xl border border-gray-300 px-3 py-1.5 text-sm disabled:opacity-40 dark:border-gray-600"><ArrowPathIcon class="h-4 w-4" /> {{ t('library.koha.sync', 'زامن الآن') }}</button>
                            <button type="button" :disabled="kohaBusy" @click="syncKoha(true)" class="rounded-xl border border-gray-300 px-3 py-1.5 text-sm disabled:opacity-40 dark:border-gray-600">{{ t('library.koha.sync_full', 'مزامنة كاملة') }}</button>
                        </div>
                    </div>
                </div>
            </section>

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

            <!-- World catalogues (SRU) -->
            <section class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-start gap-2 border-b border-gray-100 p-4 dark:border-gray-700">
                    <GlobeAltIcon class="mt-0.5 h-5 w-5 text-[var(--accent-600)]" />
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ t('library.sru.title', 'الفهارس العالمية (Z39.50 / SRU)') }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('library.sru.hint', 'عند إضافة كتاب، زر «فهرسة منقولة» يبحث في هذه الفهارس ويملأ بيانات الكتاب بسجل MARC جاهز.') }}</p>
                    </div>
                </div>
                <div class="space-y-3 p-4">
                    <div v-for="(s, i) in sru.servers" :key="i" class="grid gap-2 rounded-xl border border-gray-200 p-3 sm:grid-cols-[auto_1fr_2fr_auto] sm:items-center dark:border-gray-700">
                        <input type="checkbox" v-model="s.on" class="rounded" :title="t('library.sru.enabled', 'مفعّل')" />
                        <input v-model="s.name" :placeholder="t('library.sru.name', 'الاسم')" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <input v-model="s.url" dir="ltr" placeholder="https://…" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <button type="button" @click="sru.servers.splice(i, 1)" class="justify-self-end rounded-lg p-2 text-gray-400 hover:bg-red-50 hover:text-red-600"><TrashIcon class="h-5 w-5" /></button>
                        <details class="sm:col-span-4">
                            <summary class="cursor-pointer text-xs text-gray-500">{{ t('library.sru.advanced', 'فهارس البحث والصيغة') }}</summary>
                            <div class="mt-2 grid gap-2 sm:grid-cols-4" dir="ltr">
                                <label class="text-xs text-gray-500">ISBN<input v-model="s.isbn" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                                <label class="text-xs text-gray-500">Title<input v-model="s.title" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                                <label class="text-xs text-gray-500">Author<input v-model="s.author" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                                <label class="text-xs text-gray-500">recordSchema<input v-model="s.schema" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                            </div>
                        </details>
                    </div>
                    <label class="block text-sm">
                        <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.marc_org', 'رمز مكتبتكم في سجلات MARC (الحقل 040، فهرسة RDA)') }}</span>
                        <input v-model="sru.marc_org" dir="ltr" maxlength="30" placeholder="IQ-BaUDent" class="w-full max-w-xs rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    </label>
                    <p v-if="Object.keys(sru.errors).length" class="text-sm text-red-600">{{ Object.values(sru.errors)[0] }}</p>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex gap-2">
                            <button type="button" @click="addSru" class="flex items-center gap-1 rounded-xl border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><PlusIcon class="h-4 w-4" /> {{ t('library.sru.add', 'إضافة فهرس') }}</button>
                            <button type="button" @click="resetSru" class="flex items-center gap-1 rounded-xl border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><ArrowPathIcon class="h-4 w-4" /> {{ t('library.sru.reset', 'الافتراضي') }}</button>
                        </div>
                        <button type="button" @click="saveSru" :disabled="sru.processing || !sru.isDirty" class="rounded-xl bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white disabled:opacity-40">{{ t('action.save', 'حفظ') }}</button>
                    </div>
                </div>
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
