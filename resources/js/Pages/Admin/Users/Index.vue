<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import UserFormModal from '@/Components/UserFormModal.vue';
import Pagination from '@/Components/Pagination.vue';
import { useTrans } from '@/Composables/useTrans';
import UserAvatar from '@/Components/UserAvatar.vue';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import { PlusIcon, PencilIcon, TrashIcon, MagnifyingGlassIcon, ClipboardDocumentListIcon, ArrowUpTrayIcon, ArrowDownTrayIcon, PrinterIcon, CheckCircleIcon, NoSymbolIcon, XMarkIcon, EllipsisHorizontalIcon } from '@heroicons/vue/24/outline';
import Modal from '@/Components/Modal.vue';
import axios from 'axios';

const { t } = useTrans();
const props = defineProps({
    users: Object,
    filters: Object,
    roleCounts: { type: Object, default: () => ({}) },
    statusCounts: { type: Object, default: () => ({}) },
    groups: { type: Array, default: () => [] },
});

// Ticking users (2026-11-09, by request): one by one, everyone on the page, or every result of the
// filters; then switch them on or off, export or print them together.
const selected = ref(new Set());
const pageIds = computed(() => props.users.data.map((u) => u.id));
const pageAll = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.has(id)));
const pageSome = computed(() => !pageAll.value && pageIds.value.some((id) => selected.value.has(id)));
function tick(id) { const s = new Set(selected.value); s.has(id) ? s.delete(id) : s.add(id); selected.value = s; }
function tickPage() { const s = new Set(selected.value); pageAll.value ? pageIds.value.forEach((id) => s.delete(id)) : pageIds.value.forEach((id) => s.add(id)); selected.value = s; }
const filterParams = () => Object.fromEntries(Object.entries({ q: search.value, role: role.value, status: status.value }).filter(([, v]) => v));
const busyAll = ref(false);
async function tickAllResults() {
    busyAll.value = true;
    try { const { data } = await axios.get(route('admin.users.ids'), { params: filterParams() }); selected.value = new Set(data.ids); } finally { busyAll.value = false; }
}
const clearTicks = () => (selected.value = new Set());
const resultsTotal = computed(() => props.users.total ?? props.users.data.length);
function bulkActive(active, all = false) {
    const n = all ? resultsTotal.value : selected.value.size;
    const msg = active ? t('admin.users.bulk_on_confirm', 'تفعيل :n حساباً؟', { n }) : t('admin.users.bulk_off_confirm', 'إيقاف :n حساباً؟ لن يستطيعوا الدخول حتى تعيد تفعيلهم. (حسابك والمدير الرئيسي لا يُوقفان)', { n });
    if (!confirm(msg)) return;
    const body = all ? { is_active: active, all: 1, ...filterParams() } : { is_active: active, ids: [...selected.value] };
    router.post(route('admin.users.bulk-status'), body, { preserveScroll: true, onSuccess: () => clearTicks() });
    menuOpen.value = false;
}
// Print and export open in a new tab: the ticked users (kept for a moment on the server), or every result.
async function open(name, all = false) {
    const win = window.open('', '_blank');
    let params = all || !selected.value.size ? { all: 1, ...filterParams() } : null;
    if (!params) {
        try { params = { sel: (await axios.post(route('admin.users.selection'), { ids: [...selected.value] })).data.sel }; } catch { win?.close(); return; }
    }
    const url = route(name, params);
    if (win) win.location = url; else window.location = url;
    menuOpen.value = false;
}
const menuOpen = ref(false);

// Import: the same Excel/CSV import as for exams (name, e-mail or student number, phone), with a file of the new passwords back.
const importOpen = ref(false);
const importFile = ref(null);
const importGroup = ref('');
const importBusy = ref(false);
const importError = ref('');
async function runImport() {
    if (!importFile.value) return;
    importBusy.value = true;
    importError.value = '';
    try {
        const body = new FormData();
        body.append('file', importFile.value);
        if (importGroup.value) body.append('group_id', importGroup.value);
        const res = await axios.post(route('exams.manage.students.import'), body, { responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = (res.headers['content-disposition']?.match(/filename="?([^";]+)/)?.[1]) || 'passwords.csv';
        a.click();
        URL.revokeObjectURL(url);
        importOpen.value = false;
        importFile.value = null;
        router.reload({ only: ['users', 'roleCounts', 'statusCounts'] });
    } catch (e) {
        try { importError.value = JSON.parse(await e.response.data.text()).message; } catch { importError.value = t('exam.import_failed', 'تعذّر الاستيراد.'); }
    } finally { importBusy.value = false; }
}
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
const me = computed(() => page.props.auth.user?.id);
function canToggle(user) {
    return user.id !== me.value && !user.is_master_admin;
}
function setActive(user, active) {
    if (!active && !confirm(t('admin.users.confirm_disable', 'إيقاف حساب ":name"؟ لن يستطيع الدخول حتى تعيد تفعيله.', { name: user.name }))) return;
    router.patch(route('admin.users.status', user.id), { is_active: active }, { preserveScroll: true, preserveState: true });
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
    user: () => t('role.user', 'مستخدم'),
};

</script>

<template>
    <Head :title="t('admin.nav.users', 'المستخدمون')" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('admin.nav.users', 'المستخدمون') }}</h2>
                <div class="ms-auto flex items-center gap-1.5">
                    <button @click="importOpen = true" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700" :title="t('admin.users.import', 'استيراد')"><ArrowUpTrayIcon class="h-4 w-4" /><span class="hidden sm:inline">{{ t('admin.users.import', 'استيراد') }}</span></button>
                    <button @click="open('admin.users.export', true)" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700" :title="t('admin.users.export_all', 'تصدير القائمة (Excel)')"><ArrowDownTrayIcon class="h-4 w-4" /><span class="hidden sm:inline">{{ t('admin.users.export', 'تصدير') }}</span></button>
                    <button @click="open('admin.users.print', true)" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700" :title="t('admin.users.print_all', 'طباعة القائمة')"><PrinterIcon class="h-4 w-4" /><span class="hidden sm:inline">{{ t('admin.users.print', 'طباعة') }}</span></button>
                    <div class="relative">
                        <button @click="menuOpen = !menuOpen" class="rounded-lg border border-gray-300 p-1.5 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700" :title="t('admin.users.for_all', 'لكل النتائج')"><EllipsisHorizontalIcon class="h-5 w-5" /></button>
                        <div v-if="menuOpen" class="absolute end-0 top-full z-30 mt-1 w-60 overflow-hidden rounded-xl bg-white py-1 text-sm shadow-xl ring-1 ring-black/10 dark:bg-gray-800 dark:ring-white/10">
                            <p class="px-3 py-1.5 text-xs text-gray-500">{{ t('admin.users.for_all_n', 'لكل نتائج القائمة (:n)', { n: resultsTotal }) }}</p>
                            <button @click="bulkActive(true, true)" class="flex w-full items-center gap-2 px-3 py-2 text-start hover:bg-gray-50 dark:hover:bg-gray-700"><CheckCircleIcon class="h-4 w-4 text-emerald-600" /> {{ t('admin.users.activate_all', 'تفعيل الكل') }}</button>
                            <button @click="bulkActive(false, true)" class="flex w-full items-center gap-2 px-3 py-2 text-start text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30"><NoSymbolIcon class="h-4 w-4" /> {{ t('admin.users.deactivate_all', 'إيقاف الكل') }}</button>
                        </div>
                    </div>
                </div>
            </div>
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
                    v-for="r in ['', 'admin', 'user']" :key="r || 'all'" type="button" @click="pickRole(r)"
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
                    {{ s === 'active' ? t('admin.users.active_short', 'نشط') : t('admin.users.inactive_short', 'غير نشط') }}
                    <span class="text-xs opacity-75">{{ nf.format(statusCounts[s] ?? 0) }}</span>
                </button>
            </div>

            <!-- Ticking: everyone on the page, every result, and what to do with them -->
            <div v-if="users.data.length" class="flex flex-wrap items-center gap-2 rounded-2xl px-3 py-2 text-sm" :class="selected.size ? 'bg-[var(--accent-600)] text-white shadow-md' : 'border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'">
                <label class="flex cursor-pointer items-center gap-2 font-medium">
                    <input type="checkbox" :checked="pageAll" :indeterminate.prop="pageSome" @change="tickPage" class="rounded" />
                    <template v-if="selected.size">{{ t('admin.users.n_selected', ':n محدد', { n: selected.size }) }}</template>
                    <template v-else>{{ t('admin.users.select_page', 'تحديد الكل') }}</template>
                </label>
                <button v-if="selected.size < resultsTotal" @click="tickAllResults" :disabled="busyAll" class="rounded-lg px-2.5 py-1 text-xs font-medium underline-offset-2 hover:underline" :class="selected.size ? 'bg-white/15' : 'text-[var(--accent-700)] dark:text-[var(--accent-300)]'">{{ busyAll ? '…' : t('admin.users.select_results', 'تحديد كل النتائج (:n)', { n: resultsTotal }) }}</button>
                <template v-if="selected.size">
                    <span class="flex-1" />
                    <button @click="bulkActive(true)" class="inline-flex items-center gap-1 rounded-lg bg-white/15 px-2.5 py-1 hover:bg-white/25"><CheckCircleIcon class="h-4 w-4" /> {{ t('admin.users.activate', 'تفعيل') }}</button>
                    <button @click="bulkActive(false)" class="inline-flex items-center gap-1 rounded-lg bg-white/15 px-2.5 py-1 hover:bg-white/25"><NoSymbolIcon class="h-4 w-4" /> {{ t('admin.users.deactivate', 'إيقاف') }}</button>
                    <button @click="open('admin.users.export')" class="inline-flex items-center gap-1 rounded-lg bg-white/15 px-2.5 py-1 hover:bg-white/25"><ArrowDownTrayIcon class="h-4 w-4" /> {{ t('admin.users.export', 'تصدير') }}</button>
                    <button @click="open('admin.users.print')" class="inline-flex items-center gap-1 rounded-lg bg-white/15 px-2.5 py-1 hover:bg-white/25"><PrinterIcon class="h-4 w-4" /> {{ t('admin.users.print', 'طباعة') }}</button>
                    <button @click="clearTicks" class="rounded-lg p-1 hover:bg-white/20" :title="t('admin.users.clear', 'إلغاء التحديد')"><XMarkIcon class="h-4 w-4" /></button>
                </template>
            </div>

            <div v-if="!users.data.length" class="rounded-2xl border border-dashed border-gray-300 py-12 text-center text-sm text-gray-500 dark:border-gray-700">
                {{ t('admin.users.none', 'لا يوجد مستخدمون بهذه الفلاتر.') }}
            </div>

            <!-- Phone: cards -->
            <div class="space-y-2 md:hidden">
                <div v-for="user in users.data" :key="user.id" class="flex items-center gap-3 rounded-2xl border bg-white p-3 dark:bg-gray-800" :class="selected.has(user.id) ? 'border-[var(--accent-500)] ring-1 ring-[var(--accent-500)]' : 'border-gray-200 dark:border-gray-700'">
                    <input type="checkbox" :checked="selected.has(user.id)" @change="tick(user.id)" class="h-5 w-5 shrink-0 rounded" :aria-label="user.name" />
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
                            <span v-if="user.is_examiner" class="rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">{{ t('profile.examiner', 'أستاذ') }}</span>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col items-center gap-1">
                        <ToggleSwitch :model-value="user.is_active" tone="green" size="sm" :disabled="!canToggle(user)" :title="user.is_active ? t('admin.users.active', 'نشط') : t('admin.users.suspended', 'غير نشط')" @update:model-value="setActive(user, $event)" />
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
                            <th class="w-10 py-3 ps-4"><input type="checkbox" :checked="pageAll" :indeterminate.prop="pageSome" @change="tickPage" class="rounded" :aria-label="t('admin.users.select_page', 'تحديد الكل')" /></th>
                            <th class="px-4 py-3 text-start">{{ t('auth.name', 'الاسم') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('admin.users.role', 'الدور') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('admin.users.exam_role', 'في الامتحانات') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('admin.users.last_login', 'آخر دخول') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('admin.users.status', 'الحالة') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/30" :class="selected.has(user.id) ? 'bg-[var(--accent-50)] dark:bg-gray-700/50' : ''">
                            <td class="py-3 ps-4"><input type="checkbox" :checked="selected.has(user.id)" @change="tick(user.id)" class="rounded" :aria-label="user.name" /></td>
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
                                {{ user.is_examiner || user.role === 'admin' ? t('profile.examiner', 'أستاذ') : t('admin.users.student', 'طالب') }}<span v-if="Number(user.exam_time_multiplier) > 1" class="ms-1 text-xs text-sky-600">×{{ Number(user.exam_time_multiplier) }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ lastSeen(user.last_login_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <ToggleSwitch :model-value="user.is_active" tone="green" size="sm" :disabled="!canToggle(user)" @update:model-value="setActive(user, $event)" />
                                    <span class="text-xs" :class="user.is_active ? 'text-emerald-600' : 'text-red-500'">
                                        {{ user.is_active ? t('admin.users.active_short', 'نشط') : t('admin.users.inactive_short', 'غير نشط') }}
                                    </span>
                                </div>
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

        <UserFormModal :show="modalShow" :user="editingUser" @close="modalShow = false" />

        <Modal :show="importOpen" @close="importOpen = false" max-width="md">
            <form @submit.prevent="runImport" class="space-y-3 p-6 text-sm">
                <h3 class="flex items-center gap-2 text-lg font-semibold"><ArrowUpTrayIcon class="h-5 w-5" /> {{ t('admin.users.import_title', 'استيراد مستخدمين من Excel') }}</h3>
                <p class="text-gray-600 dark:text-gray-300">{{ t('admin.users.import_hint', 'ملف Excel أو CSV صفّه الأول عناوين: «الاسم» و«البريد» أو «الرقم الجامعي»، واختيارياً «الهاتف» و«كلمة المرور». يُنزَّل بعدها ملف بكلمات المرور الجديدة. ملف «تصدير» من هذه الصفحة يصلح للاستيراد أيضاً.') }}</p>
                <input type="file" accept=".xlsx,.csv,.txt" @change="importFile = $event.target.files[0] ?? null" class="block w-full" />
                <label v-if="groups.length" class="block"><span class="text-xs text-gray-500">{{ t('admin.users.import_group', 'أضفهم إلى مجموعة (اختياري)') }}</span>
                    <select v-model="importGroup" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700"><option value="">—</option><option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option></select></label>
                <p v-if="importError" class="text-red-600">{{ importError }}</p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="importOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button :disabled="!importFile || importBusy" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 font-medium text-white disabled:opacity-50">{{ importBusy ? '…' : t('admin.users.import', 'استيراد') }}</button>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>
