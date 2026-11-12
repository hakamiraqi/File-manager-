<script setup>
import { useTrans } from '@/Composables/useTrans';
import { ref } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const { t } = useTrans();

const usingRecoveryCode = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

function toggleRecoveryCode() {
    usingRecoveryCode.value = !usingRecoveryCode.value;
    form.reset();
    form.clearErrors();
}

function submit() {
    form.post(route('two-factor.challenge'), {
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.2fa_title', 'التحقق بخطوتين')" />

        <p class="mb-4 text-sm text-gray-600">
            <span v-if="!usingRecoveryCode">{{ t('auth.2fa_code_text', 'أدخل رمز التحقق المكوّن من 6 أرقام من تطبيق المصادقة الخاص بك.') }}</span>
            <span v-else>{{ t('auth.2fa_recovery_text', 'أدخل أحد رموز الاسترداد التي حفظتها عند تفعيل المصادقة الثنائية.') }}</span>
        </p>

        <form @submit.prevent="submit">
            <div v-if="!usingRecoveryCode">
                <InputLabel for="code" :value="t('auth.2fa_code', 'رمز التحقق')" />
                <TextInput
                    id="code"
                    v-model="form.code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="mt-1 block w-full"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <div v-else>
                <InputLabel for="recovery_code" :value="t('auth.2fa_recovery', 'رمز الاسترداد')" />
                <TextInput
                    id="recovery_code"
                    v-model="form.recovery_code"
                    class="mt-1 block w-full"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.recovery_code" />
            </div>

            <div class="mt-4 flex items-center justify-between">
                <button
                    type="button"
                    @click="toggleRecoveryCode"
                    class="text-sm text-gray-600 underline hover:text-gray-900"
                >
                    {{ usingRecoveryCode ? t('auth.2fa_use_code', 'استخدام رمز التحقق بدلاً من ذلك') : t('auth.2fa_use_recovery', 'استخدام رمز استرداد بدلاً من ذلك') }}
                </button>

                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    {{ t('auth.confirm', 'تأكيد') }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
