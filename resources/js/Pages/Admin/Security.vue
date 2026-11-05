<script setup>
// الأمان وتسجيل الدخول (redesigned 2026-11-05, by request): grouped cards, switches,
// OAuth providers as expandable rows with status, IP rules that fit a phone, and a
// save bar that stays at the bottom of the screen.
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputError from '@/Components/InputError.vue';
import SettingsSection from '@/Components/SettingsSection.vue';
import SettingToggle from '@/Components/SettingToggle.vue';
import SecretInput from '@/Components/SecretInput.vue';
import StickySaveBar from '@/Components/StickySaveBar.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    LockClosedIcon, GlobeAltIcon, PuzzlePieceIcon, KeyIcon, NoSymbolIcon, TrashIcon,
    ChevronDownIcon, ClipboardDocumentIcon, CheckIcon, PlusIcon, ShieldCheckIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
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
    ip_filter_mode: props.settings.ip_filter_mode || 'none',
    ip_redirect_url: props.settings.ip_redirect_url,
    oauth_google_client_id: props.settings.oauth_google_client_id,
    oauth_google_client_secret: props.settings.oauth_google_client_secret,
    oauth_github_client_id: props.settings.oauth_github_client_id,
    oauth_github_client_secret: props.settings.oauth_github_client_secret,
    oauth_linkedin_client_id: props.settings.oauth_linkedin_client_id,
    oauth_linkedin_client_secret: props.settings.oauth_linkedin_client_secret,
});

function submit() {
    form.post(route('admin.security.update'), { preserveScroll: true, onSuccess: () => form.defaults() });
}

const PROVIDERS = [
    { key: 'google', name: 'Google', initial: 'G', color: 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400' },
    { key: 'github', name: 'GitHub', initial: 'GH', color: 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' },
    { key: 'linkedin', name: 'LinkedIn', initial: 'in', color: 'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300' },
];
const openProvider = ref(null);
const configured = (p) => Boolean(form[`oauth_${p}_client_id`] && form[`oauth_${p}_client_secret`]);
const callbackUrl = (p) => `${props.appUrl}/auth/${p}/callback`;
const copied = ref(null);
async function copy(text, key) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        window.prompt('', text);
    }
}

const IP_MODES = [
    { value: 'none', label: t('security.ip_mode_off', 'معطّل'), hint: t('security.ip_mode_off_hint', 'الكل يدخل') },
    { value: 'blacklist', label: t('security.ip_mode_blacklist_short', 'قائمة حظر'), hint: t('security.ip_mode_blacklist_hint', 'يُمنع المدرَجون فقط') },
    { value: 'whitelist', label: t('security.ip_mode_whitelist_short', 'قائمة سماح'), hint: t('security.ip_mode_whitelist_hint', 'يدخل المدرَجون فقط') },
];
const captchaCount = computed(() => ['show_captcha_register', 'show_captcha_reset', 'show_captcha_download'].filter((k) => form[k]).length);

const ipRuleForm = useForm({ type: 'blacklist', ip_or_cidr: '', note: '' });

function addIpRule() {
    ipRuleForm.post(route('admin.security.ip-rules.store'), {
        preserveScroll: true,
        onSuccess: () => ipRuleForm.reset('ip_or_cidr', 'note'),
    });
}

function removeIpRule(rule) {
    if (confirm(t('security.confirm_delete_rule', 'حذف هذه القاعدة؟'))) {
        useForm({}).delete(route('admin.security.ip-rules.destroy', rule.id), { preserveScroll: true });
    }
}

const inputClass = 'block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-[var(--accent-600)] focus:ring-[var(--accent-600)] dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';
</script>

<template>
    <Head :title="t('admin.nav.security', 'الأمان')" />

    <AdminLayout>
        <template #header>
            <h2 class="flex min-w-0 items-center gap-2 text-base font-semibold text-gray-800 sm:text-lg dark:text-gray-100">
                <LockClosedIcon class="h-5 w-5 shrink-0 text-gray-500" />
                <span class="truncate">{{ t('admin.nav.security', 'الأمان وتسجيل الدخول') }}</span>
            </h2>
        </template>

        <div class="mx-auto max-w-3xl space-y-5 px-4 pt-4 sm:px-6 sm:pt-6">
            <!-- Overview -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('security.ov_guests', 'الزوار') }}</p>
                    <p class="mt-1 text-sm font-semibold" :class="form.guest_access_enabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-700 dark:text-gray-200'">{{ form.guest_access_enabled ? t('security.allowed', 'مسموح') : t('security.closed', 'مغلق') }}</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('security.ov_captcha', 'رمز التحقق') }}</p>
                    <p class="mt-1 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ captchaCount }} / 3 {{ t('security.pages', 'صفحات') }}</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('security.ov_oauth', 'دخول خارجي') }}</p>
                    <p class="mt-1 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ PROVIDERS.filter((p) => configured(p.key)).length }} / 3</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('security.ov_ip', 'تصفية IP') }}</p>
                    <p class="mt-1 text-sm font-semibold" :class="form.ip_filter_mode === 'none' ? 'text-gray-700 dark:text-gray-200' : 'text-amber-600 dark:text-amber-400'">{{ IP_MODES.find((m) => m.value === form.ip_filter_mode)?.label }}</p>
                </div>
            </div>

            <!-- Public access -->
            <SettingsSection :icon="GlobeAltIcon" tone="green" :title="t('security.public_access', 'الوصول العام')" :description="t('security.public_access_desc', 'من يقدر يتصفح بدون حساب، ومن أين يمكن رفع الملفات.')">
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    <SettingToggle v-model="form.guest_access_enabled" :description="t('security.guest_access_desc', 'حسب صلاحيات دور «زائر» في صفحة الصلاحيات.')">
                        {{ t('security.guest_access_short', 'السماح للزوار بتصفح المجلدات العامة') }}
                    </SettingToggle>
                    <SettingToggle v-model="form.remote_upload_enabled" :description="t('security.remote_upload_desc', 'يسمح بلصق رابط ملف من الإنترنت وتنزيله مباشرة إلى النظام.')">
                        {{ t('security.remote_upload_short', 'الرفع من رابط خارجي') }}
                    </SettingToggle>
                </div>
            </SettingsSection>

            <!-- Captcha -->
            <SettingsSection :icon="PuzzlePieceIcon" tone="sky" :title="t('security.captcha_title', 'رمز التحقق (Captcha)')" :description="t('security.captcha_desc', 'يمنع البرامج الآلية من إنشاء حسابات أو تنزيل الملفات بكثرة.')">
                <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('security.captcha_where', 'أين يظهر؟') }}</p>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    <SettingToggle v-model="form.show_captcha_register">{{ t('security.captcha_register', 'صفحة إنشاء حساب') }}</SettingToggle>
                    <SettingToggle v-model="form.show_captcha_reset">{{ t('security.captcha_reset', 'صفحة استعادة كلمة المرور') }}</SettingToggle>
                    <SettingToggle v-model="form.show_captcha_download">{{ t('security.captcha_download', 'تنزيل زائر لملف من رابط مشاركة') }}</SettingToggle>
                </div>

                <div class="mt-4 rounded-xl bg-gray-50 p-4 dark:bg-gray-900/40">
                    <SettingToggle v-model="form.recaptcha_enabled" :description="t('security.recaptcha_desc', 'بدل سؤال الحساب البسيط. يحتاج مفاتيح من Google.')">
                        {{ t('security.recaptcha_enabled_short', 'استخدام Google reCAPTCHA') }}
                    </SettingToggle>
                    <div v-if="form.recaptcha_enabled" class="mt-4 space-y-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Site Key</span>
                                <input v-model="form.recaptcha_site_key" dir="ltr" :class="inputClass" />
                                <InputError :message="form.errors.recaptcha_site_key" class="mt-1" />
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Secret Key</span>
                                <SecretInput v-model="form.recaptcha_secret_key" />
                                <InputError :message="form.errors.recaptcha_secret_key" class="mt-1" />
                            </label>
                        </div>
                        <SettingToggle v-model="form.recaptcha_invisible">{{ t('security.recaptcha_invisible', 'النمط غير المرئي (Invisible)') }}</SettingToggle>
                    </div>
                </div>
            </SettingsSection>

            <!-- OAuth -->
            <SettingsSection :icon="KeyIcon" :title="t('security.oauth_title_short', 'الدخول بحسابات خارجية')" :description="t('security.oauth_desc', 'يظهر زر المزوّد في صفحة الدخول عند إدخال المفتاحين.')">
                <div class="space-y-3">
                    <div v-for="p in PROVIDERS" :key="p.key" class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                        <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 dark:hover:bg-gray-700/40" @click="openProvider = openProvider === p.key ? null : p.key">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-xs font-bold" :class="p.color" dir="ltr">{{ p.initial }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-gray-900 dark:text-gray-100">{{ p.name }}</span>
                                <span class="block text-xs" :class="configured(p.key) ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400'">
                                    {{ configured(p.key) ? t('security.provider_on', 'مفعّل') : t('security.provider_off', 'غير مُعدّ') }}
                                </span>
                            </span>
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="configured(p.key) ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'" />
                            <ChevronDownIcon class="h-4 w-4 shrink-0 text-gray-400 transition" :class="{ 'rotate-180': openProvider === p.key }" />
                        </button>
                        <div v-show="openProvider === p.key" class="space-y-4 border-t border-gray-100 bg-gray-50/60 px-4 py-4 dark:border-gray-700 dark:bg-gray-900/30">
                            <div>
                                <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('security.redirect_uri', 'رابط إعادة التوجيه (Redirect URI)') }}</span>
                                <div class="flex items-center gap-2 rounded-xl border border-dashed border-gray-300 bg-white px-3 py-2 dark:border-gray-600 dark:bg-gray-900">
                                    <code dir="ltr" class="min-w-0 flex-1 break-all text-xs text-gray-700 dark:text-gray-300">{{ callbackUrl(p.key) }}</code>
                                    <button type="button" class="shrink-0 rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" :title="t('action.copy', 'نسخ')" @click="copy(callbackUrl(p.key), p.key)">
                                        <CheckIcon v-if="copied === p.key" class="h-4 w-4 text-emerald-500" />
                                        <ClipboardDocumentIcon v-else class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Client ID</span>
                                    <input v-model="form[`oauth_${p.key}_client_id`]" dir="ltr" autocomplete="off" :class="inputClass" />
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Client Secret</span>
                                    <SecretInput v-model="form[`oauth_${p.key}_client_secret`]" />
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </SettingsSection>

            <!-- IP rules -->
            <SettingsSection :icon="NoSymbolIcon" tone="red" :title="t('security.ip_rules_title', 'حظر / سماح عناوين IP')" :description="t('security.ip_desc', 'تحكم بالأجهزة والشبكات التي تقدر تفتح النظام.')">
                <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('security.ip_mode', 'وضع التصفية') }}</p>
                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="m in IP_MODES" :key="m.value" type="button" @click="form.ip_filter_mode = m.value"
                        class="rounded-xl border px-2 py-2.5 text-center transition"
                        :class="form.ip_filter_mode === m.value
                            ? 'border-[var(--accent-600)] bg-indigo-50 text-[var(--accent-600)] ring-1 ring-[var(--accent-600)] dark:bg-indigo-900/30'
                            : 'border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700/40'"
                    >
                        <span class="block text-sm font-semibold">{{ m.label }}</span>
                        <span class="mt-0.5 block text-[11px] opacity-75">{{ m.hint }}</span>
                    </button>
                </div>

                <label v-if="form.ip_filter_mode !== 'none'" class="mt-4 block">
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('security.ip_redirect', 'رابط إعادة توجيه المحظورين (اختياري)') }}</span>
                    <input v-model="form.ip_redirect_url" dir="ltr" placeholder="https://" :class="inputClass" />
                    <InputError :message="form.errors.ip_redirect_url" class="mt-1" />
                </label>

                <div class="mt-5 rounded-xl bg-gray-50 p-4 dark:bg-gray-900/40">
                    <p class="mb-3 text-sm font-medium text-gray-800 dark:text-gray-100">{{ t('security.add_rule', 'إضافة قاعدة') }}</p>
                    <form @submit.prevent="addIpRule" class="grid grid-cols-1 gap-3 sm:grid-cols-[auto_1fr_1fr_auto]">
                        <div class="grid grid-cols-2 gap-1 rounded-xl border border-gray-300 bg-white p-1 dark:border-gray-600 dark:bg-gray-900">
                            <button type="button" @click="ipRuleForm.type = 'blacklist'" class="rounded-lg px-3 py-1.5 text-sm" :class="ipRuleForm.type === 'blacklist' ? 'bg-red-500 text-white' : 'text-gray-600 dark:text-gray-300'">{{ t('security.block', 'حظر') }}</button>
                            <button type="button" @click="ipRuleForm.type = 'whitelist'" class="rounded-lg px-3 py-1.5 text-sm" :class="ipRuleForm.type === 'whitelist' ? 'bg-emerald-500 text-white' : 'text-gray-600 dark:text-gray-300'">{{ t('security.allow', 'سماح') }}</button>
                        </div>
                        <div>
                            <input v-model="ipRuleForm.ip_or_cidr" dir="ltr" placeholder="192.168.1.1 / 10.0.0.0/8" :class="inputClass" />
                            <InputError :message="ipRuleForm.errors.ip_or_cidr" class="mt-1" />
                        </div>
                        <input v-model="ipRuleForm.note" :placeholder="t('security.note_optional', 'ملاحظة (اختياري)')" :class="inputClass" />
                        <button type="submit" :disabled="ipRuleForm.processing || !ipRuleForm.ip_or_cidr" class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40 dark:bg-gray-100 dark:text-gray-900">
                            <PlusIcon class="h-4 w-4" /> {{ t('security.add', 'إضافة') }}
                        </button>
                    </form>
                </div>

                <ul class="mt-4 space-y-2">
                    <li v-for="rule in ipRules" :key="rule.id" class="flex items-center gap-3 rounded-xl border border-gray-200 px-3 py-2.5 dark:border-gray-700">
                        <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="rule.type === 'blacklist' ? 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400'">
                            {{ rule.type === 'blacklist' ? t('security.block', 'حظر') : t('security.allow', 'سماح') }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span dir="ltr" class="block truncate font-mono text-sm text-gray-800 dark:text-gray-200">{{ rule.ip_or_cidr }}</span>
                            <span v-if="rule.note" class="block truncate text-xs text-gray-400">{{ rule.note }}</span>
                        </span>
                        <button @click="removeIpRule(rule)" type="button" class="shrink-0 rounded-lg p-2 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30" :title="t('action.delete', 'حذف')">
                            <TrashIcon class="h-4 w-4" />
                        </button>
                    </li>
                    <li v-if="!ipRules.length" class="flex flex-col items-center gap-1 rounded-xl border border-dashed border-gray-200 py-6 text-sm text-gray-400 dark:border-gray-700">
                        <ShieldCheckIcon class="h-6 w-6" />
                        {{ t('security.no_rules', 'لا توجد قواعد بعد.') }}
                    </li>
                </ul>
            </SettingsSection>

            <StickySaveBar :form="form" :label="t('security.save', 'حفظ إعدادات الأمان')" @save="submit" @reset="form.reset()" />
        </div>
    </AdminLayout>
</template>
