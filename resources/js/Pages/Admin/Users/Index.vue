<script setup>
import { ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import UserFormModal from '@/Components/UserFormModal.vue';
import Pagination from '@/Components/Pagination.vue';
import { PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ users: Object, filters: Object });
const page = usePage();

const search = ref(props.filters.q ?? '');
const modalShow = ref(false);
const editingUser = ref(null);

function runSearch() {
    router.get(route('admin.users.index'), { q: search.value }, { preserveState: true });
}

function openCreate() {
    editingUser.value = null;
    modalShow.value = true;
}

function openEdit(user) {
    editingUser.value = user;
    modalShow.value = true;
}

function destroy(user) {
    if (!confirm(`هل أنت متأكد من حذف المستخدم "${user.name}"؟ سيتم حذف جميع ملفاته نهائياً.`)) return;
    router.delete(route('admin.users.destroy', user.id), { preserveScroll: true });
}

const ROLE_LABELS = { admin: 'مشرف', editor: 'محرّر', contributor: 'مساهم', user: 'مستخدم' };

function humanSize(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let value = bytes;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }
    return `${value.toFixed(1)} ${units[i]}`;
}
</script>

<template>
    <Head title="المستخدمون" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800">المستخدمون</h2>
        </template>

        <div class="p-6">
            <div class="mb-4 flex items-center gap-3">
                <input
                    v-model="search"
                    @keyup.enter="runSearch"
                    type="search"
                    placeholder="بحث بالاسم أو البريد..."
                    class="w-64 rounded-lg border-gray-300 text-sm"
                />
                <button @click="openCreate" class="ms-auto flex items-center gap-2 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)]">
                    <PlusIcon class="h-5 w-5" /> مستخدم جديد
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-start">الاسم</th>
                            <th class="px-4 py-3 text-start">البريد الإلكتروني</th>
                            <th class="px-4 py-3 text-start">الصلاحية</th>
                            <th class="px-4 py-3 text-start">المساحة المستخدمة</th>
                            <th class="px-4 py-3 text-start">الحالة</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="user in users.data" :key="user.id">
                            <td class="px-4 py-3 font-medium text-gray-700">{{ user.name }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ user.email }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="user.role === 'admin' ? 'bg-[var(--accent-50)] text-[var(--accent-700)]' : 'bg-gray-100 text-gray-600'"
                                >
                                    {{ ROLE_LABELS[user.role] ?? user.role }}
                                </span>
                                <span v-if="user.is_master_admin" class="ms-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                                    رئيسي
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-500">
                                {{ humanSize(user.storage_used_bytes) }} / {{ humanSize(user.storage_quota_bytes) }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs" :class="user.is_active ? 'text-emerald-600' : 'text-red-500'">
                                    {{ user.is_active ? 'مفعّل' : 'معلّق' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <button @click="openEdit(user)" class="rounded-md p-1.5 hover:bg-gray-100">
                                    <PencilIcon class="h-4 w-4" />
                                </button>
                                <button v-if="!user.is_master_admin" @click="destroy(user)" class="rounded-md p-1.5 hover:bg-gray-100">
                                    <TrashIcon class="h-4 w-4 text-red-500" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                <Pagination :links="users.links" />
            </div>
        </div>

        <UserFormModal :show="modalShow" :user="editingUser" @close="modalShow = false" />
    </AdminLayout>
</template>
