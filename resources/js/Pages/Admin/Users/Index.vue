<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import UserFormModal from '@/Components/UserFormModal.vue';
import Pagination from '@/Components/Pagination.vue';
import { useTrans } from '@/Composables/useTrans';
import UserAvatar from '@/Components/UserAvatar.vue';
import { PlusIcon, PencilIcon, TrashIcon, MagnifyingGlassIcon, ClipboardDocumentListIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({
    users: Object,
    filters: Object,
    folderOptions: { type: Array, default: () => [] },
    roleCounts: { type: Object, default: () => ({}) },
    statusCounts: { type: Object, default: () => ({}) },
});
const page = usePage();

const search = ref(props.filters.q ?? '');
const modalShow = ref(false);
const editingUser = ref(null);

const role = ref(props.filters.role ?? '');
const status = ref(props.filters.status ?? '');
function runSearch() {
    const q = {};
    if (search.value) q.q = search.value;
    if (role.value) q.role = role.value;
    if (status.value) q.status = status.value;
    router.get(route('admin.users.index'), q, { preserveState: true, preserveScroll: true, replace: true });
}
function pickRole(r) {
    role.value = r;
    runSearch();
}
function pickStatus(s) {
    status.value = status.value === s ? '' : s;
    runSearch();
}
const total = computed(() => Object.values(props.roleCounts).reduce((a, b) => a + Number(b), 0));
const nf = new Intl.NumberFormat('ar-IQ');
function lastSeen(iso) {
    if (!iso) return t('admin.users.never', 'لم يدخل بعد');
    return new Date(iso).toLocaleString('ar-IQ', { timeZone: 'Asia/Baghdad', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
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
    if (!confirm(t('admin.users.confirm_delete', 'هل أنت متأكد من حذف المستخدم ":name"؟ سيتم حذف جميع ملفاته نهائياً.', { name: user.name }))) return;
    router.delete(route('admin.users.destroy', user.id), { preserveScroll: true });
}

const ROLE_LABELS = {
    admin: () => t('role.admin', 'مدير'),
    editor: () => t('role.editor', 'محرّر'),
    contributor: () => t('role.contributor', 'مساهم'),
    user: () => t('role.user', 'مستخدم'),
};

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
    <Head :title="t('admin.nav.users', 'المستخدمون')" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('admin.nav.users', 'المستخدمون') }}</h2>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <!-- Search + new -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-[12rem] flex-1 sm:max-w-sm">
                    <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="search"
                        @keyup.enter="runSearch"
                        @search="runSearch"
                        type="search"
                        :placeholder="t('admin.users.search_placeholder', 'بحث بالاسم أو البريد...')"
                        class="w-full rounded-xl border-gray-300 ps-9 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                    />
                </div>
                <button @click="openCreate" class="ms-auto flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl bg-[var(--accent-600)] px-4 py-2.5 text-sm font-medium text-white hover:bg-[var(--accent-700)]">
                    <PlusIcon class="h-5 w-5" /> {{ t('admin.users.new', 'مستخدم جديد') }}
                </button>
            </div>

            <!-- Role filter -->
            <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 sm:flex-wrap">
                <button
                    v-for="r in ['', 'admin', 'editor', 'contributor', 'user']" :key="r || 'all'" type="button" @click="pickRole(r)"
                    class="flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm transition"
                    :class="role === r ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white shadow-sm' : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200'"
                >
                    {{ r ? ROLE_LABELS[r]() : t('admin.users.all_roles', 'كل الأدوار') }}
                    <span class="rounded-full px-1.5 text-xs" :class="role === r ? 'bg-white/20' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'">{{ nf.format(r ? (roleCounts[r] ?? 0) : total) }}</span>
                </button>
                <span class="mx-1 hidden w-px self-stretch bg-gray-200 dark:bg-gray-700 sm:block" />
                <button
                    v-for="s in ['active', 'suspended']" :key="s" type="button" @click="pickStatus(s)"
                    class="flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm transition"
                    :class="status === s ? (s === 'active' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-red-600 bg-red-600 text-white') : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200'"
                >
                    <span class="h-2 w-2 rounded-full" :class="s === 'active' ? 'bg-emerald-500' : 'bg-red-500'" />
                    {{ s === 'active' ? t('admin.users.active', 'مفعّل') : t('admin.users.suspended', 'معلّق') }}
                    <span class="text-xs opacity-75">{{ nf.format(statusCounts[s] ?? 0) }}</span>
                </button>
            </div>

            <div v-if="!users.data.length" class="rounded-2xl border border-dashed border-gray-300 py-12 text-center text-sm text-gray-500 dark:border-gray-700">
                {{ t('admin.users.none', 'لا يوجد مستخدمون بهذه الفلاتر.') }}
            </div>

            <!-- Phone: cards -->
            <div class="space-y-2 md:hidden">
                <div v-for="user in users.data" :key="user.id" class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <UserAvatar :user="user" size="h-11 w-11 text-base" />
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-1.5 truncate font-medium text-gray-800 dark:text-gray-100">
                            <span class="h-2 w-2 shrink-0 rounded-full" :class="user.is_active ? 'bg-emerald-500' : 'bg-red-500'" />
                            <span class="truncate">{{ user.name }}</span>
                        </p>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ user.email }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-1">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="user.role === 'admin' ? 'bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700 dark:text-gray-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">{{ ROLE_LABELS[user.role]?.() ?? user.role }}</span>
                            <span v-if="user.is_master_admin" class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ t('admin.users.master', 'رئيسي') }}</span>
                            <span class="text-[11px] text-gray-400">{{ humanSize(user.storage_used_bytes) }}</span>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col gap-1">
                        <button @click="openEdit(user)" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('action.edit', 'تعديل')"><PencilIcon class="h-4 w-4 text-gray-500" /></button>
                        <button v-if="!user.is_master_admin" @click="destroy(user)" class="rounded-lg p-2 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('action.delete', 'حذف')"><TrashIcon class="h-4 w-4 text-red-500" /></button>
                    </div>
                </div>
            </div>

            <!-- Desktop: table -->
            <div v-if="users.data.length" class="hidden overflow-x-auto rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800 md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('auth.name', 'الاسم') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('admin.users.role', 'الدور') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('storage.used_label', 'المساحة المستخدمة') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('admin.users.last_login', 'آخر دخول') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('admin.users.status', 'الحالة') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <UserAvatar :user="user" size="h-9 w-9 text-sm" />
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-800 dark:text-gray-100">{{ user.name }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ user.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="user.role === 'admin' ? 'bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700 dark:text-gray-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                                    {{ ROLE_LABELS[user.role]?.() ?? user.role }}
                                </span>
                                <span v-if="user.is_master_admin" class="ms-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                                    {{ t('admin.users.master', 'رئيسي') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                {{ humanSize(user.storage_used_bytes) }} / {{ humanSize(user.storage_quota_bytes) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ lastSeen(user.last_login_at) }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 text-xs" :class="user.is_active ? 'text-emerald-600' : 'text-red-500'">
                                    <span class="h-2 w-2 rounded-full" :class="user.is_active ? 'bg-emerald-500' : 'bg-red-500'" />
                                    {{ user.is_active ? t('admin.users.active', 'مفعّل') : t('admin.users.suspended', 'معلّق') }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-end">
                                <a :href="route('admin.activity.index', { user: user.id })" class="inline-flex rounded-md p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('admin.users.activity', 'نشاطاته')">
                                    <ClipboardDocumentListIcon class="h-4 w-4 text-gray-500" />
                                </a>
                                <button @click="openEdit(user)" class="rounded-md p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <PencilIcon class="h-4 w-4" />
                                </button>
                                <button v-if="!user.is_master_admin" @click="destroy(user)" class="rounded-md p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <TrashIcon class="h-4 w-4 text-red-500" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="users.links" />
        </div>

        <UserFormModal :show="modalShow" :user="editingUser" :folder-options="folderOptions" @close="modalShow = false" />
    </AdminLayout>
</template>
