<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, GlobeAltIcon, KeyIcon, ArrowPathIcon, CheckIcon, ExclamationTriangleIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline';

// Services that need the internet: the central server and the halls' servers, and SMS/WhatsApp.
const props = defineProps({ offline: Boolean, central: Object, hall: Object, address: String, messages: Object, amc: Object });
const { t } = useTrans();
const hallForm = useForm({ url: props.hall.url ?? '', key: '' });
const saveHall = () => hallForm.post(route('exams.monitor.central.hall'), { preserveScroll: true, onSuccess: () => hallForm.reset('key') });
const syncNow = () => router.post(route('exams.monitor.central.hall'), { now: true }, { preserveScroll: true });
const newKey = () => router.post(route('exams.monitor.central.key'), {}, { preserveScroll: true });
const keyOff = () => router.post(route('exams.monitor.central.key'), { off: true }, { preserveScroll: true });
const msgForm = useForm({ url: props.messages.url ?? '', method: props.messages.method, body: props.messages.body ?? '', header: '', country: props.messages.country, test: '' });
const saveMsg = () => msgForm.post(route('exams.monitor.central.messages'), { preserveScroll: true, onSuccess: () => msgForm.reset('header', 'test') });
const amcForm = useForm({ url: props.amc?.url ?? '', token: '' });
const saveAmc = () => amcForm.post(route('exams.monitor.central.amc'), { preserveScroll: true, onSuccess: () => amcForm.reset('token') });
const fmt = (iso) => (iso ? new Date(iso).toLocaleString(document.documentElement.lang || 'ar') : '—');
const summary = (s) => { const [a, b] = String(s ?? '0|0').split('|'); return { taken: a, sent: b }; };
const input = 'rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700';
</script>

<template>
    <Head :title="t('exam.central', 'السيرفر المركزي والقاعات')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('exams.monitor.index')" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="flex items-center gap-2 text-lg font-semibold"><GlobeAltIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.central', 'السيرفر المركزي والقاعات') }}</h2>
            </div>
        </template>
        <div class="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
            <section v-if="!offline" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="font-semibold">{{ t('exam.central_main', 'هذا هو السيرفر المركزي') }}</p>
                <p class="text-xs text-gray-500">{{ t('exam.central_main_hint', 'سيرفرات القاعات تأخذ من هنا الامتحانات المؤشّر عليها «يُرسل إلى سيرفرات القاعات»، وترجع نتائجها كلما اتصلت بالإنترنت، فيرى الطلاب نتائجهم ويعترضون من البيت. اكتب في كل سيرفر قاعة هذا العنوان والمفتاح.') }} <span class="font-mono" dir="ltr">{{ address }}</span></p>
                <p class="text-xs">{{ t('exam.central_shared', 'امتحانات مُرسلة للقاعات: :n', { n: central.shared }) }}</p>
                <p v-if="central.key" class="rounded-lg bg-amber-50 p-3 font-mono text-base dark:bg-amber-900/30" dir="ltr">{{ central.key }}</p>
                <div class="flex gap-2">
                    <button @click="newKey" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-600"><KeyIcon class="h-4 w-4" /> {{ central.enabled ? t('exam.mirror_new_key', 'مفتاح جديد') : t('exam.mirror_enable', 'تفعيل وصنع مفتاح') }}</button>
                    <button v-if="central.enabled" @click="keyOff" class="rounded-lg border border-gray-300 px-3 py-1.5 text-red-600 dark:border-gray-600">{{ t('exam.mirror_off', 'إيقاف') }}</button>
                </div>
            </section>

            <form @submit.prevent="saveHall" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="font-semibold">{{ t('exam.central_hall', 'هذا سيرفر قاعة') }}</p>
                <p class="text-xs text-gray-500">{{ t('exam.central_hall_hint', 'كل عشر دقائق، إن وُجد الإنترنت: يأخذ الامتحانات الجديدة من السيرفر المركزي (غير منشورة، ليراجعها الأستاذ وينشرها هنا) ويرسل النتائج. بلا إنترنت يعمل كما هو.') }}</p>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.central_url', 'عنوان السيرفر المركزي') }}</span><input v-model="hallForm.url" dir="ltr" placeholder="https://exams.example.edu" :class="input" class="w-full font-mono" /></label>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.mirror_key', 'مفتاحه') }}<template v-if="hall.has_key"> ({{ t('settings.saved', 'محفوظ') }})</template></span><input v-model="hallForm.key" dir="ltr" autocomplete="off" :class="input" class="w-full font-mono" /></label>
                <div class="flex flex-wrap items-center gap-2">
                    <button :disabled="hallForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-white">{{ t('action.save', 'حفظ') }}</button>
                    <button v-if="hall.url" type="button" @click="syncNow" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-600"><ArrowPathIcon class="h-4 w-4" /> {{ t('exam.central_now', 'مزامنة الآن') }}</button>
                </div>
                <p v-if="hall.url" class="flex items-center gap-1 text-xs" :class="hall.last_error ? 'text-red-600' : 'text-emerald-700 dark:text-emerald-400'">
                    <ExclamationTriangleIcon v-if="hall.last_error" class="h-4 w-4" /><CheckIcon v-else class="h-4 w-4" />
                    {{ t('exam.central_last', 'آخر مزامنة ناجحة: :at — أُخذ :a امتحاناً وأُرسلت نتائج :b', { at: fmt(hall.last_ok), a: summary(hall.summary).taken, b: summary(hall.summary).sent }) }}<template v-if="hall.last_error"> — {{ hall.last_error }}</template></p>
            </form>

            <form @submit.prevent="saveAmc" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="font-semibold">{{ t('exam.amc_connector', 'خادم Auto Multiple Choice') }}</p>
                <p class="text-xs text-gray-500">{{ t('exam.amc_connector_hint', 'برنامج AMC يعمل على خادم لينكس (حاوية amc-connector). على الاستضافة مضبوط تلقائياً؛ لسيرفر قاعة اكتب عنوان خادم AMC في الشبكة المحلية ومفتاحه.') }}
                    <template v-if="amc?.env && !amc?.url"> {{ t('exam.amc_env', 'الآن: المضبوط في بيئة السيرفر.') }}</template></p>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.amc_url', 'عنوان خادم AMC') }}</span><input v-model="amcForm.url" dir="ltr" placeholder="http://192.168.1.20:8080" :class="input" class="w-full font-mono" /></label>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.mirror_key', 'مفتاحه') }}<template v-if="amc?.has_token"> ({{ t('settings.saved', 'محفوظ') }})</template></span><input v-model="amcForm.token" dir="ltr" autocomplete="off" :class="input" class="w-full font-mono" /></label>
                <button :disabled="amcForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-white">{{ t('exam.amc_save_test', 'حفظ واختبار') }}</button>
            </form>

            <form v-if="!offline" @submit.prevent="saveMsg" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="flex items-center gap-2 font-semibold"><ChatBubbleLeftRightIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.messages', 'رسائل SMS وواتساب') }}</p>
                <p class="text-xs text-gray-500">{{ t('exam.messages_hint', 'بوابة الرسائل التي تستعملها المؤسسة (SMS أو واتساب). اكتب عنوانها مع {phone} و{message} في مكانهما، وجسم الطلب لـ POST إن احتاج. تُرسل النتائج والتذكيرات من صفحة نتائج كل امتحان، لمن له رقم هاتف.') }}</p>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.messages_url', 'عنوان البوابة') }}</span><input v-model="msgForm.url" dir="ltr" placeholder="https://api.gateway.example/send?to={phone}&text={message}" :class="input" class="w-full font-mono" /></label>
                <div class="flex flex-wrap gap-3">
                    <label><span class="mb-1 block text-xs text-gray-500">{{ t('exam.messages_method', 'الطريقة') }}</span><select v-model="msgForm.method" :class="input"><option>POST</option><option>GET</option></select></label>
                    <label><span class="mb-1 block text-xs text-gray-500">{{ t('exam.messages_country', 'رمز الدولة') }}</span><input v-model="msgForm.country" dir="ltr" :class="input" class="w-20" /></label>
                    <label class="min-w-0 flex-1"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.messages_header', 'ترويسة المفتاح (Authorization: Bearer …)') }}<template v-if="messages.has_header"> ({{ t('settings.saved', 'محفوظ') }})</template></span><input v-model="msgForm.header" dir="ltr" autocomplete="off" :class="input" class="w-full font-mono" /></label>
                </div>
                <label v-if="msgForm.method === 'POST'" class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.messages_body', 'جسم الطلب (اختياري)') }}</span><textarea v-model="msgForm.body" rows="2" dir="ltr" placeholder='{"to": "{phone}", "body": "{message}"}' :class="input" class="w-full font-mono" /></label>
                <div class="flex flex-wrap items-center gap-2">
                    <button :disabled="msgForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-white">{{ t('action.save', 'حفظ') }}</button>
                    <input v-model="msgForm.test" dir="ltr" :placeholder="t('exam.messages_test_ph', 'رقم لرسالة تجريبية')" :class="input" class="w-44" />
                    <button type="button" :disabled="!msgForm.test" @click="saveMsg" class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40 dark:border-gray-600">{{ t('exam.messages_test', 'إرسال تجريبي') }}</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
