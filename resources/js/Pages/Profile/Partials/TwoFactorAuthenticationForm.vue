<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { usePage, useForm } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const page = usePage();
const enabled = computed(() => page.props.auth?.twoFactorEnabled ?? false);

const qrCodeUri = computed(() => page.props.flash?.twoFactorQrCodeUri ?? null);
const recoveryCodes = computed(() => page.props.flash?.twoFactorRecoveryCodes ?? null);

const qrCanvas = ref(null);
const manualSecret = computed(() => {
    if (!qrCodeUri.value) return null;
    const match = qrCodeUri.value.match(/[?&]secret=([^&]+)/);
    return match ? match[1] : null;
});

watch(qrCodeUri, async (uri) => {
    if (!uri) return;
    await nextTick();
    if (qrCanvas.value) {
        QRCode.toCanvas(qrCanvas.value, uri, { width: 200 }).catch(() => {});
    }
}, { immediate: true });

const enableForm = useForm({});
const confirmForm = useForm({ code: '' });
const disableForm = useForm({ password: '' });

const confirmingDisable = ref(false);
const passwordInput = ref(null);

function enable() {
    enableForm.post(route('two-factor.enable'), { preserveScroll: true });
}

function confirm() {
    confirmForm.post(route('two-factor.confirm'), {
        preserveScroll: true,
        onSuccess: () => confirmForm.reset(),
    });
}

function confirmDisable() {
    confirmingDisable.value = true;
    nextTick(() => passwordInput.value?.focus());
}

function disable() {
    disableForm.delete(route('two-factor.disable'), {
        preserveScroll: true,
        onSuccess: () => closeDisableModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => disableForm.reset(),
    });
}

function closeDisableModal() {
    confirmingDisable.value = false;
    disableForm.clearErrors();
    disableForm.reset();
}

function regenerateRecoveryCodes() {
    enableForm.post(route('two-factor.recovery-codes'), { preserveScroll: true });
}
</script>

<template>
    <section class="space-y-6">
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                المصادقة الثنائية (2FA)
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                طبقة حماية إضافية لحسابك: بعد إدخال كلمة المرور، سيُطلب منك أيضاً رمز تحقق من تطبيق مصادقة على هاتفك (مثل Google Authenticator أو Authy).
            </p>
        </header>

        <div v-if="!enabled && !qrCodeUri">
            <PrimaryButton @click="enable" :disabled="enableForm.processing">
                تفعيل المصادقة الثنائية
            </PrimaryButton>
        </div>

        <div v-else-if="!enabled && qrCodeUri" class="space-y-4 rounded-lg border border-gray-200 p-4">
            <p class="text-sm text-gray-700">
                امسح رمز QR هذا بتطبيق المصادقة، أو أدخل المفتاح يدوياً، ثم اكتب رمز التحقق المكوّن من 6 أرقام لتفعيل الحماية.
            </p>

            <canvas ref="qrCanvas"></canvas>

            <p v-if="manualSecret" class="break-all rounded bg-gray-50 p-2 font-mono text-xs text-gray-600">
                {{ manualSecret }}
            </p>

            <form @submit.prevent="confirm" class="flex items-end gap-3">
                <div class="flex-1">
                    <InputLabel for="two_factor_code" value="رمز التحقق" />
                    <TextInput
                        id="two_factor_code"
                        v-model="confirmForm.code"
                        inputmode="numeric"
                        class="mt-1 block w-full"
                        autocomplete="one-time-code"
                    />
                    <InputError :message="confirmForm.errors.code" class="mt-2" />
                </div>
                <PrimaryButton :disabled="confirmForm.processing">تأكيد</PrimaryButton>
            </form>
        </div>

        <div v-else class="space-y-4">
            <p class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700">
                المصادقة الثنائية مفعّلة
            </p>

            <div v-if="recoveryCodes" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-medium text-amber-800">
                    احفظ رموز الاسترداد هذه في مكان آمن — كل رمز يُستخدم مرة واحدة فقط لتسجيل الدخول إذا فقدت الوصول إلى تطبيق المصادقة، ولن تظهر هذه الرموز مجدداً بعد مغادرة الصفحة.
                </p>
                <div class="grid grid-cols-2 gap-2 font-mono text-sm text-amber-900">
                    <span v-for="rc in recoveryCodes" :key="rc">{{ rc }}</span>
                </div>
            </div>

            <div class="flex gap-3">
                <SecondaryButton @click="regenerateRecoveryCodes" :disabled="enableForm.processing">
                    توليد رموز استرداد جديدة
                </SecondaryButton>
                <DangerButton @click="confirmDisable">تعطيل</DangerButton>
            </div>
        </div>

        <Modal :show="confirmingDisable" @close="closeDisableModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    تعطيل المصادقة الثنائية
                </h2>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    أدخل كلمة المرور الحالية لتأكيد تعطيل المصادقة الثنائية عن حسابك.
                </p>

                <div class="mt-6">
                    <InputLabel for="disable_password" value="كلمة المرور" class="sr-only" />
                    <TextInput
                        id="disable_password"
                        ref="passwordInput"
                        v-model="disableForm.password"
                        type="password"
                        class="mt-1 block w-3/4"
                        placeholder="كلمة المرور"
                        @keyup.enter="disable"
                    />
                    <InputError :message="disableForm.errors.password" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeDisableModal">إلغاء</SecondaryButton>
                    <DangerButton
                        class="ms-3"
                        :class="{ 'opacity-25': disableForm.processing }"
                        :disabled="disableForm.processing"
                        @click="disable"
                    >
                        تعطيل المصادقة الثنائية
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>
