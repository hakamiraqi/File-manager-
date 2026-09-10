<script setup>
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();

const props = defineProps({
    show: Boolean,
    user: { type: Object, default: null }, // null => create mode
});
const emit = defineEmits(['close']);

const isMasterAdmin = computed(() => usePage().props.auth.user?.is_master_admin ?? false);

const CAPABILITIES = [
    { key: 'can_manage_appearance', label: () => t('capability.manage_appearance', 'إدارة المظهر') },
    { key: 'can_manage_users', label: () => t('capability.manage_users', 'إدارة المستخدمين') },
    { key: 'can_manage_translations', label: () => t('capability.manage_translations', 'إدارة الترجمات') },
    { key: 'can_view_statistics', label: () => t('capability.view_statistics', 'عرض التحليلات والسجلات') },
    { key: 'can_manage_settings', label: () => t('capability.manage_settings', 'إدارة الإعدادات العامة') },
];

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: 'user',
    storage_quota_mb: '',
    is_active: true,
    can_manage_appearance: false,
    can_manage_users: false,
    can_manage_translations: false,
    can_view_statistics: false,
    can_manage_settings: false,
});

watch(
    () => props.show,
    (show) => {
        if (show) {
            form.clearErrors();
            if (props.user) {
                form.name = props.user.name;
                form.email = props.user.email;
                form.password = '';
                form.role = props.user.role;
                form.is_active = props.user.is_active;
                form.storage_quota_mb = props.user.storage_quota_bytes
                    ? Math.round(props.user.storage_quota_bytes / (1024 * 1024))
                    : '';
                CAPABILITIES.forEach((c) => (form[c.key] = !!props.user[c.key]));
            } else {
                form.reset();
                form.role = 'user';
                form.is_active = true;
            }
        }
    },
);

function submit() {
    if (props.user) {
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('admin.users.update', props.user.id), {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
    } else {
        form.post(route('admin.users.store'), {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
    }
}
</script>

<template>
    <Modal :show="show" @close="$emit('close')">
        <form @submit.prevent="submit" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ user ? t('admin.users.edit', 'تعديل المستخدم') : t('admin.users.new', 'مستخدم جديد') }}</h2>

            <div class="mt-4 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <InputLabel :value="t('auth.name', 'الاسم')" />
                    <TextInput v-model="form.name" class="mt-1 block w-full" />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div class="col-span-2">
                    <InputLabel :value="t('auth.email', 'البريد الإلكتروني')" />
                    <TextInput type="email" v-model="form.email" class="mt-1 block w-full" />
                    <InputError :message="form.errors.email" class="mt-1" />
                </div>
                <div class="col-span-2">
                    <InputLabel :value="user ? t('admin.users.new_password_optional', 'كلمة مرور جديدة (اختياري)') : t('auth.password', 'كلمة المرور')" />
                    <TextInput type="password" v-model="form.password" class="mt-1 block w-full" />
                    <InputError :message="form.errors.password" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('admin.users.role', 'الصلاحية')" />
                    <select v-model="form.role" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="user">{{ t('role.user', 'مستخدم') }}</option>
                        <option value="editor">{{ t('role.editor', 'محرّر') }}</option>
                        <option value="contributor">{{ t('role.contributor', 'مساهم') }}</option>
                        <option value="admin">{{ t('role.admin', 'مشرف') }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('admin.users.quota', 'حصة التخزين (ميغابايت، اتركه فارغاً للافتراضي)')" />
                    <TextInput type="number" min="1" v-model="form.storage_quota_mb" class="mt-1 block w-full" />
                </div>
                <div class="col-span-2" v-if="user">
                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <input type="checkbox" v-model="form.is_active" class="rounded border-gray-300" />
                        {{ t('admin.users.account_active', 'الحساب مفعّل') }}
                    </label>
                </div>
                <div class="col-span-2 border-t border-gray-100 pt-3 dark:border-gray-700" v-if="form.role === 'admin' && isMasterAdmin">
                    <InputLabel :value="t('admin.users.admin_capabilities', 'صلاحيات هذا المشرف')" />
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <label v-for="c in CAPABILITIES" :key="c.key" class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" v-model="form[c.key]" class="rounded border-gray-300" />
                            {{ c.label() }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="$emit('close')">{{ t('action.cancel', 'إلغاء') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing">{{ t('action.save', 'حفظ') }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
