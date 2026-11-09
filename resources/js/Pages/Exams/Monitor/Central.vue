<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, GlobeAltIcon, KeyIcon, ArrowPathIcon, CheckIcon, ExclamationTriangleIcon, ChatBubbleLeftRightIcon, CloudArrowUpIcon, BellAlertIcon } from '@heroicons/vue/24/outline';

// Services that need the internet: the central server and the halls' servers, and SMS/WhatsApp.
const props = defineProps({ offline: Boolean, central: Object, hall: Object, address: String, messages: Object, amc: Object, offsite: Object, watch: Object });
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
const offForm = useForm({ url: props.offsite?.url ?? '', bucket: props.offsite?.bucket ?? '', region: props.offsite?.region ?? 'auto', key_id: props.offsite?.key_id ?? '',
    secret: '', pass: '', prefix: props.offsite?.prefix ?? '', keep: props.offsite?.keep ?? 30 });
const saveOff = () => offForm.post(route('exams.monitor.central.offsite'), { preserveScroll: true, onSuccess: () => offForm.reset('secret', 'pass') });
const sendingOff = ref(false);
const offNow = () => router.post(route('exams.monitor.central.offsite'), { now: true }, { preserveScroll: true, onStart: () => (sendingOff.value = true), onFinish: () => (sendingOff.value = false) });
const watchForm = useForm({ ntfy: props.watch?.ntfy ?? '', ntfy_token: '', phones: props.watch?.phones ?? '', email: props.watch?.email ?? '' });
const saveWatch = () => watchForm.post(route('exams.monitor.central.watch'), { preserveScroll: true, onSuccess: () => watchForm.reset('ntfy_token') });
const testWatch = () => router.post(route('exams.monitor.central.watch'), { test: true }, { preserveScroll: true });
const newTopic = () => { const a = new Uint8Array(12); crypto.getRandomValues(a); watchForm.ntfy = 'https://ntfy.sh/examnest-' + Array.from(a, (b) => b.toString(36).padStart(2, '0')).join('').slice(0, 20); };
const mb = (n) => (n ? (n / 1048576).toFixed(1) + ' MB' : '');
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

            <form @submit.prevent="saveOff" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="flex items-center gap-2 font-semibold"><CloudArrowUpIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.offsite', 'نسخة احتياطية خارج السيرفر') }}</p>
                <p class="text-xs text-gray-500">{{ t('exam.offsite_hint', 'كل ليلة (2:40) تُرسل نسخة من قاعدة البيانات إلى تخزين سحابي متوافق مع S3 (Amazon S3، Cloudflare R2، Backblaze B2، Wasabi...)، مشفّرة هنا بكلمة سرّك فلا تقرؤها شركة التخزين. احفظ كلمة السر في مكان آمن: بدونها لا تُفتح النسخ.') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.offsite_url', 'عنوان الخدمة (Endpoint)') }}</span><input v-model="offForm.url" dir="ltr" placeholder="https://<account>.r2.cloudflarestorage.com" :class="input" class="w-full font-mono" /></label>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.offsite_bucket', 'اسم الحاوية (Bucket)') }}</span><input v-model="offForm.bucket" dir="ltr" :class="input" class="w-full font-mono" /></label>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.offsite_region', 'المنطقة (Region)') }}</span><input v-model="offForm.region" dir="ltr" placeholder="auto / eu-central-1" :class="input" class="w-full font-mono" /></label>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">Access key ID</span><input v-model="offForm.key_id" dir="ltr" autocomplete="off" :class="input" class="w-full font-mono" /></label>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">Secret access key<template v-if="offsite?.has_secret"> ({{ t('settings.saved', 'محفوظ') }})</template></span><input v-model="offForm.secret" type="password" dir="ltr" autocomplete="new-password" :class="input" class="w-full font-mono" /></label>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.offsite_pass', 'كلمة سر التشفير (12 حرفاً على الأقل)') }}<template v-if="offsite?.has_pass"> ({{ t('settings.saved', 'محفوظ') }})</template></span><input v-model="offForm.pass" type="password" dir="ltr" autocomplete="new-password" :class="input" class="w-full font-mono" /></label>
                    <div class="flex gap-3">
                        <label class="min-w-0 flex-1"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.offsite_prefix', 'مجلد (اختياري)') }}</span><input v-model="offForm.prefix" dir="ltr" :class="input" class="w-full font-mono" /></label>
                        <label><span class="mb-1 block text-xs text-gray-500">{{ t('exam.offsite_keep', 'الاحتفاظ (يوم)') }}</span><input v-model.number="offForm.keep" type="number" min="0" :class="input" class="w-20" /></label>
                    </div>
                </div>
                <p v-for="(e, k) in offForm.errors" :key="k" class="text-xs text-red-600">{{ e }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <button :disabled="offForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-white">{{ t('action.save', 'حفظ') }}</button>
                    <button v-if="offsite?.url && offsite?.has_secret && offsite?.has_pass" type="button" :disabled="sendingOff" @click="offNow" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-50 dark:border-gray-600"><ArrowPathIcon class="h-4 w-4" :class="sendingOff && 'animate-spin'" /> {{ t('exam.offsite_now', 'أرسل نسخة الآن') }}</button>
                </div>
                <p v-if="offsite?.last_ok" class="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"><CheckIcon class="h-4 w-4" /> {{ t('exam.offsite_last', 'آخر نسخة: :at', { at: fmt(offsite.last_ok) }) }} <span class="font-mono" dir="ltr">{{ offsite.last_file }} {{ mb(offsite.last_size) }}</span></p>
                <p v-if="offsite?.last_error" class="flex items-center gap-1 text-xs text-red-600"><ExclamationTriangleIcon class="h-4 w-4" /> {{ offsite.last_error }}</p>
                <p class="text-xs text-gray-500">{{ t('exam.offsite_restore', 'لفتح نسخة منزّلة: php artisan backup:offsite --decrypt=الملف ثم php artisan backup:restore.') }}</p>
            </form>

            <form @submit.prevent="saveWatch" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="flex items-center gap-2 font-semibold"><BellAlertIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.watch', 'تنبيهات السيرفر على الهاتف') }}</p>
                <p class="text-xs text-gray-500">{{ t('exam.watch_hint', 'كل خمس دقائق يفحص السيرفر نفسه: القرص، الذاكرة، قاعدة البيانات، المهام الخلفية، خادم AMC والنسخ الخارجي. عند مشكلة يصلك تنبيه (ويتكرر كل ست ساعات ما دامت)، وآخر حين تُصلح.') }}</p>
                <p v-if="watch?.problems?.length" class="flex items-center gap-1 rounded-lg bg-red-50 p-2 text-xs text-red-700 dark:bg-red-900/30 dark:text-red-300"><ExclamationTriangleIcon class="h-4 w-4" /> {{ t('exam.watch_now', 'مشاكل الآن: :list', { list: watch.problems.join('، ') }) }}</p>
                <p v-else-if="watch?.last" class="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"><CheckIcon class="h-4 w-4" /> {{ t('exam.watch_ok', 'كل شيء سليم — آخر فحص: :at', { at: fmt(watch.last) }) }}</p>
                <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.watch_ntfy', 'إشعارات الهاتف عبر تطبيق ntfy (مجاني): عنوان الموضوع') }}</span>
                    <div class="flex gap-2"><input v-model="watchForm.ntfy" dir="ltr" placeholder="https://ntfy.sh/..." :class="input" class="min-w-0 flex-1 font-mono" /><button type="button" @click="newTopic" class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-xs dark:border-gray-600">{{ t('exam.watch_topic', 'اصنع عنواناً') }}</button></div>
                    <span class="mt-1 block text-xs text-gray-500">{{ t('exam.watch_ntfy_hint', 'نزّل تطبيق ntfy على هاتفك، واشترك بنفس العنوان (الجزء بعد ntfy.sh/). العنوان العشوائي سرّي: لا تنشره.') }}</span></label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.watch_phones', 'أرقام لرسائل SMS/واتساب') }}<template v-if="!watch?.sms"> — {{ t('exam.watch_no_sms', 'اضبط البوابة أدناه أولاً') }}</template></span><input v-model="watchForm.phones" dir="ltr" placeholder="0770..., 0780..." :class="input" class="w-full font-mono" /></label>
                    <label class="block"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.watch_email', 'بريد إلكتروني (إن كان البريد مضبوطاً)') }}</span><input v-model="watchForm.email" dir="ltr" :class="input" class="w-full font-mono" /></label>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button :disabled="watchForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-white">{{ t('action.save', 'حفظ') }}</button>
                    <button type="button" @click="testWatch" class="rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-600">{{ t('exam.watch_test', 'تنبيه تجريبي') }}</button>
                </div>
                <p class="text-xs text-gray-500">{{ t('exam.watch_outside', 'لمعرفة توقف السيرفر كله (الكهرباء أو الشبكة) اربط خدمة مراقبة خارجية مجانية مثل UptimeRobot بالعنوان:') }} <span class="font-mono" dir="ltr">{{ address }}/up</span></p>
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
