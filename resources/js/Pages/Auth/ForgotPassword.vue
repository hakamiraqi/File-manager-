<script setup>
import { useTrans } from '@/Composables/useTrans';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import CaptchaField from '@/Components/CaptchaField.vue';
import { readRecaptchaToken } from '@/Composables/useRecaptcha';
import { Head, useForm } from '@inertiajs/vue3';
const { t } = useTrans();

const props = defineProps({
    status: {
        type: String,
    },
    captcha: { type: Object, default: () => ({ enabled: false }) },
});

const form = useForm({
    email: '',
    captcha_answer: '',
    'g-recaptcha-response': '',
});

const submit = () => {
    if (props.captcha?.siteKey) {
        form['g-recaptcha-response'] = readRecaptchaToken();
    }

    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.forgot_password', 'استعادة كلمة المرور')" />

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            نسيت كلمة المرور؟ لا مشكلة. فقط زوّدنا ببريدك الإلكتروني وسنرسل لك رابطاً لإعادة تعيين كلمة المرور.
        </div>

        <div
            v-if="status"
            class="mb-4 text-sm font-medium text-green-600 dark:text-green-400"
        >
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" :value="t('auth.email', 'البريد الإلكتروني')" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <CaptchaField v-model="form.captcha_answer" :captcha="captcha" :error="form.errors.captcha_answer" />

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    إرسال رابط إعادة التعيين
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
