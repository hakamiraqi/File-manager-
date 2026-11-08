<script setup>
import { ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useTrans } from '@/Composables/useTrans';
import { ClipboardIcon, CheckIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();

const props = defineProps({
    show: Boolean,
    item: { type: Object, default: null }, // { id, name, type }
});
const emit = defineEmits(['close']);

const page = usePage();
const linkUrl = ref(null);
const copied = ref(false);
const emailSent = ref(false);

const form = useForm({
    shareable_type: null,
    shareable_id: null,
    password: '',
    expires_at: '',
    max_downloads: '',
    one_time_download: false,
    allow_download: true,
});

const emailForm = useForm({ emails: '', message: '' });

watch(
    () => props.show,
    (show) => {
        if (show) {
            linkUrl.value = null;
            copied.value = false;
            emailSent.value = false;
            form.reset();
            emailForm.reset();
        }
    },
);

function submit() {
    form.shareable_type = props.item.type;
    form.shareable_id = props.item.id;

    form.post(route('shares.store'), {
        preserveScroll: true,
        onSuccess: () => {
            linkUrl.value = page.props.flash.shareUrl;
        },
    });
}

function copyLink() {
    navigator.clipboard.writeText(linkUrl.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

function sendEmail() {
    const shareId = page.props.flash.shareId;
    emailForm.transform((data) => ({
        ...data,
        emails: data.emails.split(',').map((e) => e.trim()).filter(Boolean),
    })).post(route('shares.email', shareId), {
        preserveScroll: true,
        onSuccess: () => (emailSent.value = true),
    });
}
</script>

<template>
    <Modal :show="show" @close="$emit('close')" max-width="lg">
        <div class="p-6" v-if="item">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ t('share.title', 'مشاركة ":name"', { name: item.name }) }}</h2>

            <form v-if="!linkUrl" @submit.prevent="submit" class="mt-4 space-y-4">
                <div>
                    <InputLabel :value="t('share.password_optional', 'كلمة مرور (اختياري)')" />
                    <TextInput type="password" v-model="form.password" class="mt-1 block w-full" :placeholder="t('share.no_password', 'بدون كلمة مرور')" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel :value="t('share.expires_optional', 'تاريخ انتهاء الصلاحية (اختياري)')" />
                        <TextInput type="datetime-local" v-model="form.expires_at" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('share.max_downloads_optional', 'حد التنزيلات (اختياري)')" />
                        <TextInput type="number" min="1" v-model="form.max_downloads" class="mt-1 block w-full" />
                    </div>
                </div>
                <label class="flex items-center gap-2">
                    <Checkbox v-model:checked="form.allow_download" />
                    <span class="text-sm text-gray-600 dark:text-gray-400">{{ t('share.allow_download', 'السماح بالتنزيل') }}</span>
                </label>
                <label class="flex items-center gap-2">
                    <Checkbox v-model:checked="form.one_time_download" />
                    <span class="text-sm text-gray-600 dark:text-gray-400">{{ t('share.one_time', 'رابط للاستخدام مرة واحدة فقط (يُلغى تلقائياً بعد أول تنزيل)') }}</span>
                </label>
                <div class="flex justify-end gap-3">
                    <SecondaryButton @click="$emit('close')">{{ t('action.cancel', 'إلغاء') }}</SecondaryButton>
                    <PrimaryButton :disabled="form.processing">{{ t('share.create_link', 'إنشاء رابط المشاركة') }}</PrimaryButton>
                </div>
            </form>

            <div v-else class="mt-4 space-y-5">
                <div class="flex items-center gap-2 rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                    <input readonly :value="linkUrl" class="flex-1 truncate border-0 bg-transparent text-sm focus:ring-0 dark:text-gray-200" />
                    <button type="button" @click="copyLink" class="rounded-md bg-gray-100 p-2 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600">
                        <CheckIcon v-if="copied" class="h-4 w-4 text-emerald-600" />
                        <ClipboardIcon v-else class="h-4 w-4" />
                    </button>
                </div>

                <form @submit.prevent="sendEmail" class="space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <InputLabel :value="t('share.email_label', 'إرسال عبر البريد الإلكتروني (افصل بين العناوين بفاصلة)')" />
                    <TextInput v-model="emailForm.emails" class="block w-full" placeholder="user@example.com, user2@example.com" />
                    <textarea v-model="emailForm.message" rows="2" class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" :placeholder="t('share.optional_message', 'رسالة اختيارية...')" />
                    <div class="flex items-center justify-between">
                        <span v-if="emailSent" class="text-sm text-emerald-600 dark:text-emerald-400">{{ t('share.email_sent', 'تم الإرسال بنجاح.') }}</span>
                        <PrimaryButton class="ms-auto" :disabled="emailForm.processing">{{ t('share.send', 'إرسال') }}</PrimaryButton>
                    </div>
                </form>

                <SecondaryButton @click="$emit('close')" class="w-full justify-center">{{ t('action.close', 'إغلاق') }}</SecondaryButton>
            </div>
        </div>
    </Modal>
</template>
