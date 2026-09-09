<script setup>
import { reactive } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { ShieldCheckIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ matrix: Object, roles: Array, actions: Array });

const ROLE_LABELS = { guest: 'زائر (بدون تسجيل)', user: 'مستخدم', editor: 'محرّر', contributor: 'مساهم' };
const ACTION_LABELS = {
    view: 'عرض الملفات',
    download: 'تحميل الملفات',
    share: 'إنشاء روابط مشاركة',
    upload: 'رفع ملفات',
    delete: 'حذف ملفات',
    rename: 'إعادة تسمية ملفات',
    move: 'نقل ملفات/مجلدات',
    copy: 'نسخ ملفات/مجلدات',
    new_folder: 'إنشاء مجلدات',
    delete_folder: 'حذف مجلدات',
    rename_folder: 'إعادة تسمية مجلدات',
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
    <Head title="الصلاحيات" />

    <AdminLayout>
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800">
                <ShieldCheckIcon class="h-5 w-5 text-gray-500" />
                مصفوفة صلاحيات الأدوار
            </h2>
        </template>

        <form @submit.prevent="submit" class="space-y-6 p-6">
            <p class="max-w-2xl text-sm text-gray-500">
                يملك المدير (Admin) دائماً كل الصلاحيات ولا يظهر في هذا الجدول. الجدول أدناه يتحكم بما يستطيع كل دور آخر
                فعله داخل مساحته الخاصة، ويتحكم أيضاً بما يستطيعه الزوار غير المسجّلين عند تفعيل "الوصول كضيف".
            </p>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-start font-semibold text-gray-600">الإجراء</th>
                            <th v-for="role in roles" :key="role" class="px-4 py-3 text-center font-semibold text-gray-600">
                                {{ ROLE_LABELS[role] }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="action in actions" :key="action">
                            <td class="px-4 py-3 font-medium text-gray-700">{{ ACTION_LABELS[action] }}</td>
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

            <PrimaryButton :disabled="form.processing">حفظ الصلاحيات</PrimaryButton>
        </form>
    </AdminLayout>
</template>
