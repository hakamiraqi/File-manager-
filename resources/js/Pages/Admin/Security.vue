<script setup>
import { useForm } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { LockClosedIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ settings: Object, ipRules: Array, appUrl: String });

const form = useForm({
    guest_access_enabled: props.settings.guest_access_enabled === '1',
    remote_upload_enabled: props.settings.remote_upload_enabled === '1',
    show_captcha_register: props.settings.show_captcha_register === '1',
    show_captcha_reset: props.settings.show_captcha_reset === '1',
    show_captcha_download: props.settings.show_captcha_download === '1',
    recaptcha_enabled: props.settings.recaptcha_enabled === '1',
    recaptcha_invisible: props.settings.recaptcha_invisible === '1',
    recaptcha_site_key: props.settings.recaptcha_site_key,
    recaptcha_secret_key: props.settings.recaptcha_secret_key,
    ip_filter_mode: props.settings.ip_filter_mode,
    ip_redirect_url: props.settings.ip_redirect_url,
    oauth_google_client_id: props.settings.oauth_google_client_id,
    oauth_google_client_secret: props.settings.oauth_google_client_secret,
    oauth_github_client_id: props.settings.oauth_github_client_id,
    oauth_github_client_secret: props.settings.oauth_github_client_secret,
    oauth_linkedin_client_id: props.settings.oauth_linkedin_client_id,
    oauth_linkedin_client_secret: props.settings.oauth_linkedin_client_secret,
});

function submit() {
    form.post(route('admin.security.update'), { preserveScroll: true });
}

const ipRuleForm = useForm({ type: 'blacklist', ip_or_cidr: '', note: '' });

function addIpRule() {
    ipRuleForm.post(route('admin.security.ip-rules.store'), {
        preserveScroll: true,
        onSuccess: () => ipRuleForm.reset('ip_or_cidr', 'note'),
    });
}

function removeIpRule(rule) {
    if (confirm('حذف هذه القاعدة؟')) {
        useForm({}).delete(route('admin.security.ip-rules.destroy', rule.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="الأمان" />

    <AdminLayout>
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800">
                <LockClosedIcon class="h-5 w-5 text-gray-500" />
                الأمان وتسجيل الدخول
            </h2>
        </template>

        <div class="max-w-3xl space-y-8 p-6">
            <form @submit.prevent="submit" class="space-y-8">
                <section class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-4 font-semibold text-gray-800">الوصول العام</h3>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="form.guest_access_enabled" class="rounded border-gray-300" />
                            السماح للزوار غير المسجّلين بتصفح المجلدات العامة (حسب صلاحيات دور "زائر")
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="form.remote_upload_enabled" class="rounded border-gray-300" />
                            السماح برفع الملفات من رابط خارجي (Remote Upload)
                        </label>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-4 font-semibold text-gray-800">رمز التحقق (Captcha)</h3>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="form.show_captcha_register" class="rounded border-gray-300" />
                            عرضه في صفحة إنشاء حساب
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="form.show_captcha_reset" class="rounded border-gray-300" />
                            عرضه في صفحة استعادة كلمة المرور
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="form.show_captcha_download" class="rounded border-gray-300" />
                            عرضه عند تحميل زائر لملف من رابط مشاركة عام
                        </label>
                    </div>

                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" v-model="form.recaptcha_enabled" class="rounded border-gray-300" />
                            استخدام Google reCAPTCHA بدل رمز التحقق الرياضي البسيط
                        </label>
                        <div v-if="form.recaptcha_enabled" class="mt-3 grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Site Key" />
                                <TextInput v-model="form.recaptcha_site_key" class="mt-1 block w-full" dir="ltr" />
                            </div>
                            <div>
                                <InputLabel value="Secret Key" />
                                <TextInput v-model="form.recaptcha_secret_key" type="password" class="mt-1 block w-full" dir="ltr" />
                            </div>
                            <label class="col-span-2 flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" v-model="form.recaptcha_invisible" class="rounded border-gray-300" />
                                استخدام النمط غير المرئي (Invisible reCAPTCHA)
                            </label>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-4 font-semibold text-gray-800">تسجيل الدخول عبر مزوّدين خارجيين (OAuth)</h3>
                    <p class="mb-4 text-xs text-gray-500">
                        رابط إعادة التوجيه (Redirect URI) لكل مزوّد: <code dir="ltr">{{ appUrl }}/auth/&lt;google|github|linkedin&gt;/callback</code>
                    </p>
                    <div class="space-y-4">
                        <div v-for="provider in ['google', 'github', 'linkedin']" :key="provider" class="grid grid-cols-2 gap-4 border-b border-gray-100 pb-4 last:border-0 last:pb-0">
                            <div>
                                <InputLabel :value="`Client ID (${provider})`" />
                                <TextInput v-model="form[`oauth_${provider}_client_id`]" class="mt-1 block w-full" dir="ltr" />
                            </div>
                            <div>
                                <InputLabel :value="`Client Secret (${provider})`" />
                                <TextInput v-model="form[`oauth_${provider}_client_secret`]" type="password" class="mt-1 block w-full" dir="ltr" />
                            </div>
                        </div>
                    </div>
                </section>

                <PrimaryButton :disabled="form.processing">حفظ إعدادات الأمان</PrimaryButton>
            </form>

            <section class="rounded-xl border border-gray-200 bg-white p-6">
                <h3 class="mb-4 font-semibold text-gray-800">حظر / سماح عناوين IP</h3>

                <div class="mb-4 flex items-center gap-4">
                    <select v-model="form.ip_filter_mode" @change="submit" class="rounded-md border-gray-300 text-sm">
                        <option value="none">معطّل</option>
                        <option value="blacklist">قائمة حظر (Blacklist)</option>
                        <option value="whitelist">قائمة سماح (Whitelist)</option>
                    </select>
                    <TextInput v-model="form.ip_redirect_url" placeholder="رابط إعادة توجيه عند الحظر (اختياري)" class="flex-1" dir="ltr" />
                </div>

                <form @submit.prevent="addIpRule" class="mb-4 flex items-end gap-2">
                    <select v-model="ipRuleForm.type" class="rounded-md border-gray-300 text-sm">
                        <option value="blacklist">حظر</option>
                        <option value="whitelist">سماح</option>
                    </select>
                    <TextInput v-model="ipRuleForm.ip_or_cidr" placeholder="192.168.1.1 أو 10.0.0.0/8" dir="ltr" />
                    <TextInput v-model="ipRuleForm.note" placeholder="ملاحظة (اختياري)" />
                    <PrimaryButton :disabled="ipRuleForm.processing">إضافة</PrimaryButton>
                </form>

                <ul class="divide-y divide-gray-100">
                    <li v-for="rule in ipRules" :key="rule.id" class="flex items-center justify-between py-2 text-sm">
                        <span>
                            <span class="me-2 rounded bg-gray-100 px-2 py-0.5 text-xs">{{ rule.type === 'blacklist' ? 'حظر' : 'سماح' }}</span>
                            <span dir="ltr" class="font-mono">{{ rule.ip_or_cidr }}</span>
                            <span v-if="rule.note" class="text-gray-400"> — {{ rule.note }}</span>
                        </span>
                        <button @click="removeIpRule(rule)" type="button" class="text-red-500 hover:text-red-700">
                            <TrashIcon class="h-4 w-4" />
                        </button>
                    </li>
                    <li v-if="!ipRules.length" class="py-2 text-sm text-gray-400">لا توجد قواعد بعد.</li>
                </ul>
            </section>
        </div>
    </AdminLayout>
</template>
