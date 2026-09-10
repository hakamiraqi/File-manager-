<script setup>
import { reactive } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useTrans } from '@/Composables/useTrans';
import { ShieldCheckIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ matrix: Object, roles: Array, actions: Array });

const ROLE_LABELS = {
    guest: t('role.guest', 'زائر (بدون تسجيل)'),
    user: t('role.user', 'مستخدم'),
    editor: t('role.editor', 'محرّر'),
    contributor: t('role.contributor', 'مساهم'),
};
const ACTION_LABELS = {
    view: t('permission.view', 'عرض الملفات'),
    download: t('permission.download', 'تحميل الملفات'),
    share: t('permission.share', 'إنشاء روابط مشاركة'),
    upload: t('permission.upload', 'رفع ملفات'),
    delete: t('permission.delete', 'حذف ملفات'),
    rename: t('permission.rename', 'إعادة تسمية ملفات'),
    move: t('permission.move', 'نقل ملفات/مجلدات'),
    copy: t('permission.copy', 'نسخ ملفات/مجلدات'),
    new_folder: t('permission.new_folder', 'إنشاء مجلدات'),
    delete_folder: t('permission.delete_folder', 'حذف مجلدات'),
    rename_folder: t('permission.rename_folder', 'إعادة تسمية مجلدات'),
    manage_questions: t('permission.manage_questions', 'إدارة أسئلة الفيديو التفاعلي'),
    comment: t('permission.comment', 'التعليق على الفيديوهات'),
};

// Guests can only ever view/download/share by design (no drive of their own
// to upload/delete/rename into) — the other cells stay disabled for them.
const GUEST_ALLOWED_ACTIONS = ['view', 'download', 'share'];

const matrix = reactive(JSON.parse(JSON.stringify(props.matrix)));

const form = useForm({ matrix });

function submit() {
    form.transform((data) => ({ matrix: data.matrix })).post(route('admin.permissions.update'), { preserveScroll: true });
}

function isEditable(role, action) {
    return role !== 'guest' || GUEST_ALLOWED_ACTIONS.includes(action);
}
</script>

<template>
    <Head :title="t('admin.nav.permissions', 'الصلاحيات')" />

    <AdminLayout>
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-gray-100">
                <ShieldCheckIcon class="h-5 w-5 text-gray-500" />
                {{ t('permissions.title', 'مصفوفة صلاحيات الأدوار') }}
            </h2>
        </template>

        <form @submit.prevent="submit" class="space-y-6 p-6">
            <p class="max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                {{ t('permissions.description', 'يملك المدير (Admin) دائماً كل الصلاحيات ولا يظهر في هذا الجدول. الجدول أدناه يتحكم بما يستطيع كل دور آخر فعله داخل مساحته الخاصة، ويتحكم أيضاً بما يستطيعه الزوار غير المسجّلين عند تفعيل "الوصول كضيف".') }}
            </p>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-4 py-3 text-start font-semibold text-gray-600 dark:text-gray-300">{{ t('permissions.action', 'الإجراء') }}</th>
                            <th v-for="role in roles" :key="role" class="px-4 py-3 text-center font-semibold text-gray-600 dark:text-gray-300">
                                {{ ROLE_LABELS[role] }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="action in actions" :key="action">
                            <td class="px-4 py-3 font-medium text-gray-700 dark:text-gray-300">{{ ACTION_LABELS[action] }}</td>
                            <td v-for="role in roles" :key="role" class="px-4 py-3 text-center">
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-gray-300"
                                    :disabled="!isEditable(role, action)"
                                    v-model="matrix[role][action]"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <PrimaryButton :disabled="form.processing">{{ t('permissions.save', 'حفظ الصلاحيات') }}</PrimaryButton>
        </form>
    </AdminLayout>
</template>
