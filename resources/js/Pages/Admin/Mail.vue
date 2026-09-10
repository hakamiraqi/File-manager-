<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useTrans } from '@/Composables/useTrans';
import { EnvelopeIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ settings: Object });

const form = useForm({
    mail_host: props.settings.mail_host,
    mail_port: props.settings.mail_port,
    mail_username: props.settings.mail_username,
    mail_password: '',
    mail_encryption: props.settings.mail_encryption,
    mail_from_address: props.settings.mail_from_address,
    mail_from_name: props.settings.mail_from_name,
    notify_upload: props.settings.notify_upload === '1',
    notify_download: props.settings.notify_download === '1',
    notify_login: props.settings.notify_login === '1',
    notify_newfolder: props.settings.notify_newfolder === '1',
    notify_registration: props.settings.notify_registration === '1',
});

function submit() {
    form.post(route('admin.mail.update'), { preserveScroll: true });
}

const testEmail = ref('');
const testForm = useForm({ email: '' });

function sendTest() {
    testForm.email = testEmail.value;
    testForm.post(route('admin.mail.test'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('admin.nav.mail', 'البريد الإلكتروني')" />

    <AdminLayout>
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-gray-100">
                <EnvelopeIcon class="h-5 w-5 text-gray-500" />
                {{ t('mail.title', 'إعدادات البريد الإلكتروني (SMTP)') }}
            </h2>
        </template>

        <div class="max-w-3xl space-y-8 p-6">
            <form @submit.prevent="submit" class="space-y-8">
                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('mail.smtp_server', 'خادم SMTP') }}</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel :value="t('mail.host', 'الخادم (Host)')" />
                            <TextInput v-model="form.mail_host" class="mt-1 block w-full" dir="ltr" />
                        </div>
                        <div>
                            <InputLabel :value="t('mail.port', 'المنفذ (Port)')" />
                            <TextInput type="number" v-model="form.mail_port" class="mt-1 block w-full" dir="ltr" />
                        </div>
                        <div>
                            <InputLabel :value="t('mail.username', 'اسم المستخدم')" />
                            <TextInput v-model="form.mail_username" class="mt-1 block w-full" dir="ltr" />
                        </div>
                        <div>
                            <InputLabel :value="t('auth.password', 'كلمة المرور')" />
                            <TextInput type="password" v-model="form.mail_password" :placeholder="t('mail.password_placeholder', 'اتركه فارغاً للإبقاء على القيمة الحالية')" class="mt-1 block w-full" dir="ltr" />
                        </div>
                        <div>
                            <InputLabel :value="t('mail.encryption', 'التشفير')" />
                            <select v-model="form.mail_encryption" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">{{ t('mail.none', 'بدون') }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel :value="t('mail.from_address', 'عنوان المرسل')" />
                            <TextInput v-model="form.mail_from_address" class="mt-1 block w-full" dir="ltr" />
                        </div>
                        <div>
                            <InputLabel :value="t('mail.from_name', 'اسم المرسل')" />
                            <TextInput v-model="form.mail_from_name" class="mt-1 block w-full" />
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('mail.notifications_title', 'إشعارات الأحداث (تُرسل لكل مشرف نشط)') }}</h3>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.notify_upload" class="rounded border-gray-300" /> {{ t('mail.notify_upload', 'عند رفع ملف') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.notify_download" class="rounded border-gray-300" /> {{ t('mail.notify_download', 'عند تحميل ملف') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.notify_login" class="rounded border-gray-300" /> {{ t('mail.notify_login', 'عند تسجيل دخول مستخدم') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.notify_newfolder" class="rounded border-gray-300" /> {{ t('mail.notify_newfolder', 'عند إنشاء مجلد جديد') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form.notify_registration" class="rounded border-gray-300" /> {{ t('mail.notify_registration', 'عند انضمام مستخدم جديد') }}
                        </label>
                    </div>
                </section>

                <PrimaryButton :disabled="form.processing">{{ t('mail.save', 'حفظ إعدادات البريد') }}</PrimaryButton>
            </form>

            <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                <h3 class="mb-4 font-semibold text-gray-800 dark:text-gray-100">{{ t('mail.send_test', 'إرسال رسالة اختبار') }}</h3>
                <div class="flex items-end gap-2">
                    <TextInput v-model="testEmail" type="email" placeholder="you@example.com" class="flex-1" dir="ltr" />
                    <SecondaryButton :disabled="testForm.processing" @click="sendTest">{{ t('share.send', 'إرسال') }}</SecondaryButton>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
