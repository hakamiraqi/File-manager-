<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    ArrowDownTrayIcon, ArrowsRightLeftIcon, ArrowUpTrayIcon, ChatBubbleLeftRightIcon, CheckIcon, DocumentDuplicateIcon,
    DocumentIcon, EyeIcon, FolderIcon, FolderMinusIcon, FolderPlusIcon, GlobeAltIcon, LockClosedIcon, PencilIcon,
    PencilSquareIcon, PlayCircleIcon, QuestionMarkCircleIcon, ShareIcon, ShieldCheckIcon, TableCellsIcon, TrashIcon,
    UserIcon, UsersIcon, ViewColumnsIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ matrix: Object, roles: Array, actions: Array });

const ROLES = {
    guest: { label: t('role.guest', 'زائر'), hint: t('role.guest_hint', 'بدون تسجيل دخول، عند تفعيل الوصول كضيف'), icon: GlobeAltIcon },
    user: { label: t('role.user', 'مستخدم'), hint: t('role.user_hint', 'الحساب العادي'), icon: UserIcon },
    editor: { label: t('role.editor', 'محرّر'), hint: t('role.editor_hint', 'ينظّم الملفات ويعدّلها'), icon: PencilSquareIcon },
    contributor: { label: t('role.contributor', 'مساهم'), hint: t('role.contributor_hint', 'يضيف محتوى دون حذف'), icon: UsersIcon },
};
const ACTIONS = {
    view: { label: t('permission.view', 'عرض الملفات'), hint: 'فتح المجلدات ومعاينة الملفات', icon: EyeIcon },
    download: { label: t('permission.download', 'تحميل الملفات'), hint: 'تنزيل الملفات إلى الجهاز', icon: ArrowDownTrayIcon },
    upload: { label: t('permission.upload', 'رفع ملفات'), hint: 'إضافة ملفات جديدة', icon: ArrowUpTrayIcon },
    rename: { label: t('permission.rename', 'إعادة تسمية ملفات'), hint: 'تغيير اسم الملف', icon: PencilIcon },
    move: { label: t('permission.move', 'نقل ملفات/مجلدات'), hint: 'النقل بين المجلدات', icon: ArrowsRightLeftIcon },
    copy: { label: t('permission.copy', 'نسخ ملفات/مجلدات'), hint: 'إنشاء نسخة في مكان آخر', icon: DocumentDuplicateIcon },
    delete: { label: t('permission.delete', 'حذف ملفات'), hint: 'نقل الملفات إلى سلة المحذوفات', icon: TrashIcon },
    new_folder: { label: t('permission.new_folder', 'إنشاء مجلدات'), hint: 'مجلدات جديدة داخل المساحة', icon: FolderPlusIcon },
    rename_folder: { label: t('permission.rename_folder', 'إعادة تسمية مجلدات'), hint: 'وتعديل بيانات المجلد', icon: PencilIcon },
    delete_folder: { label: t('permission.delete_folder', 'حذف مجلدات'), hint: 'حذف المجلد مع محتواه', icon: FolderMinusIcon },
    share: { label: t('permission.share', 'إنشاء روابط مشاركة'), hint: 'روابط عامة أو بالبريد', icon: ShareIcon },
    manage_questions: { label: t('permission.manage_questions', 'إدارة أسئلة الفيديو'), hint: 'إضافة أسئلة داخل الفيديو التفاعلي', icon: QuestionMarkCircleIcon },
    comment: { label: t('permission.comment', 'التعليق على الفيديوهات'), hint: 'كتابة تعليقات أسفل الفيديو', icon: ChatBubbleLeftRightIcon },
};
const GROUPS = [
    { key: 'files', label: t('permissions.g.files', 'الملفات'), icon: DocumentIcon, actions: ['view', 'download', 'upload', 'rename', 'move', 'copy', 'delete'] },
    { key: 'folders', label: t('permissions.g.folders', 'المجلدات'), icon: FolderIcon, actions: ['new_folder', 'rename_folder', 'delete_folder'] },
    { key: 'sharing', label: t('permissions.g.sharing', 'المشاركة'), icon: ShareIcon, actions: ['share'] },
    { key: 'video', label: t('permissions.g.video', 'الفيديو التفاعلي'), icon: PlayCircleIcon, actions: ['manage_questions', 'comment'] },
].map((g) => ({ ...g, actions: g.actions.filter((a) => props.actions.includes(a)) }));
// Any action the server knows but the groups above don't list still shows up.
const ungrouped = props.actions.filter((a) => !GROUPS.some((g) => g.actions.includes(a)));
if (ungrouped.length) GROUPS.push({ key: 'other', label: t('permissions.g.other', 'أخرى'), icon: ShieldCheckIcon, actions: ungrouped });

const PRESETS = [
    { key: 'read', label: t('permissions.p.read', 'قراءة فقط'), actions: ['view', 'download'] },
    { key: 'contribute', label: t('permissions.p.contribute', 'إضافة بدون حذف'), actions: ['view', 'download', 'upload', 'new_folder', 'rename', 'rename_folder', 'copy', 'share', 'comment'] },
    { key: 'all', label: t('permissions.p.all', 'كل الصلاحيات'), actions: null },
    { key: 'none', label: t('permissions.p.none', 'إيقاف الكل'), actions: [] },
];

// Guests can only ever view/download/share (no drive of their own to change).
const GUEST_ALLOWED = ['view', 'download', 'share'];
const editable = (role, action) => role !== 'guest' || GUEST_ALLOWED.includes(action);

const form = useForm({ matrix: JSON.parse(JSON.stringify(props.matrix)) });
const role = ref(props.roles.includes('user') ? 'user' : props.roles[0]);
const view = ref('cards');

const count = (r) => props.actions.filter((a) => form.matrix[r][a]).length;
const total = (r) => props.actions.filter((a) => editable(r, a)).length;
const groupState = (r, g) => {
    const list = g.actions.filter((a) => editable(r, a));
    const on = list.filter((a) => form.matrix[r][a]).length;
    return { list, on, all: list.length > 0 && on === list.length };
};
function toggle(r, a) {
    if (editable(r, a)) form.matrix[r][a] = !form.matrix[r][a];
}
function setGroup(r, g, value) {
    groupState(r, g).list.forEach((a) => (form.matrix[r][a] = value));
}
function applyPreset(r, p) {
    props.actions.forEach((a) => {
        if (editable(r, a)) form.matrix[r][a] = p.actions === null ? true : p.actions.includes(a);
    });
}
const changed = computed(() => props.roles.reduce((n, r) => n + props.actions.filter((a) => form.matrix[r][a] !== props.matrix[r][a]).length, 0));

function submit() {
    form.post(route('admin.permissions.update'), { preserveScroll: true, onSuccess: () => form.defaults() });
}
</script>

<template>
    <Head :title="t('admin.nav.permissions', 'الصلاحيات')" />

    <AdminLayout>
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-gray-100">
                <ShieldCheckIcon class="h-5 w-5 text-gray-500" />
                {{ t('permissions.title_short', 'الصلاحيات') }}
            </h2>
        </template>

        <div class="space-y-5 p-4 sm:p-6">
            <div class="flex gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-900 dark:border-indigo-900/50 dark:bg-indigo-900/20 dark:text-indigo-200">
                <ShieldCheckIcon class="h-5 w-5 shrink-0" />
                <p>{{ t('permissions.description_short', 'المدير يملك كل الصلاحيات دائماً. اختر دوراً ثم فعّل أو أوقف ما يستطيع فعله. التغييرات لا تُطبَّق إلا بعد الحفظ.') }}</p>
            </div>

            <!-- Roles -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <button
                    v-for="r in roles" :key="r" type="button" @click="role = r; view = 'cards'"
                    class="rounded-2xl border p-4 text-start transition"
                    :class="role === r && view === 'cards'
                        ? 'border-[var(--accent-600)] bg-white shadow-md ring-2 ring-indigo-100 dark:ring-indigo-900/40 dark:bg-gray-800'
                        : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800'"
                >
                    <div class="flex items-center justify-between">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl" :class="role === r && view === 'cards' ? 'bg-[var(--accent-600)] text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                            <component :is="ROLES[r]?.icon ?? UserIcon" class="h-5 w-5" />
                        </span>
                        <span class="text-xs font-medium text-gray-500" dir="ltr">{{ count(r) }} / {{ total(r) }}</span>
                    </div>
                    <p class="mt-3 font-semibold text-gray-900 dark:text-white">{{ ROLES[r]?.label ?? r }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ ROLES[r]?.hint }}</p>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-[var(--accent-600)] transition-all" :style="{ width: (count(r) / Math.max(1, total(r))) * 100 + '%' }" />
                    </div>
                </button>
            </div>

            <!-- View switch + presets -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div v-if="view === 'cards'" class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-500">{{ t('permissions.quick', 'إعداد سريع:') }}</span>
                    <button v-for="p in PRESETS" :key="p.key" type="button" @click="applyPreset(role, p)" class="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:border-[var(--accent-600)] hover:text-[var(--accent-600)] dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">{{ p.label }}</button>
                </div>
                <span v-else />
                <div class="inline-flex rounded-xl border border-gray-200 bg-white p-1 dark:border-gray-700 dark:bg-gray-800">
                    <button type="button" @click="view = 'cards'" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm" :class="view === 'cards' ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'text-gray-600 dark:text-gray-300'"><ViewColumnsIcon class="h-4 w-4" /> {{ t('permissions.by_role', 'حسب الدور') }}</button>
                    <button type="button" @click="view = 'table'" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm" :class="view === 'table' ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'text-gray-600 dark:text-gray-300'"><TableCellsIcon class="h-4 w-4" /> {{ t('permissions.compare', 'مقارنة الأدوار') }}</button>
                </div>
            </div>

            <!-- Cards for one role -->
            <div v-if="view === 'cards'" class="grid gap-4 lg:grid-cols-2">
                <section v-for="g in GROUPS" :key="g.key" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <header class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                        <h3 class="flex items-center gap-2 font-semibold text-gray-900 dark:text-white">
                            <component :is="g.icon" class="h-5 w-5 text-[var(--accent-600)]" /> {{ g.label }}
                            <span class="rounded-full bg-gray-100 px-2 text-xs font-medium text-gray-500 dark:bg-gray-700" dir="ltr">{{ groupState(role, g).on }}/{{ groupState(role, g).list.length }}</span>
                        </h3>
                        <button v-if="groupState(role, g).list.length > 1" type="button" @click="setGroup(role, g, !groupState(role, g).all)" class="text-xs font-medium text-[var(--accent-600)] hover:underline">
                            {{ groupState(role, g).all ? t('permissions.none_group', 'إيقاف الكل') : t('permissions.all_group', 'تفعيل الكل') }}
                        </button>
                    </header>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <li v-for="a in g.actions" :key="a">
                            <button type="button" @click="toggle(role, a)" :disabled="!editable(role, a)" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 disabled:cursor-not-allowed disabled:hover:bg-transparent dark:hover:bg-gray-700/40">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="form.matrix[role][a] ? 'bg-indigo-50 text-[var(--accent-600)] dark:bg-indigo-900/30' : 'bg-gray-100 text-gray-400 dark:bg-gray-700'">
                                    <component :is="ACTIONS[a]?.icon ?? ShieldCheckIcon" class="h-5 w-5" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium" :class="editable(role, a) ? 'text-gray-900 dark:text-white' : 'text-gray-400'">{{ ACTIONS[a]?.label ?? a }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        <template v-if="editable(role, a)">{{ ACTIONS[a]?.hint }}</template>
                                        <span v-else class="inline-flex items-center gap-1"><LockClosedIcon class="h-3.5 w-3.5" /> {{ t('permissions.guest_locked', 'غير متاح للزوار') }}</span>
                                    </span>
                                </span>
                                <span role="switch" :aria-checked="form.matrix[role][a]" class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition" :class="[form.matrix[role][a] ? 'bg-[var(--accent-600)]' : 'bg-gray-300 dark:bg-gray-600', { 'opacity-40': !editable(role, a) }]">
                                    <span class="absolute h-5 w-5 rounded-full bg-white shadow transition-all" :class="form.matrix[role][a] ? 'start-[1.375rem]' : 'start-0.5'" />
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- Compare all roles -->
            <div v-else class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 bg-gray-50 text-xs text-gray-500 dark:bg-gray-900/60">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('permissions.action', 'الإجراء') }}</th>
                            <th v-for="r in roles" :key="r" class="px-3 py-3 text-center"><component :is="ROLES[r]?.icon ?? UserIcon" class="mx-auto mb-1 h-4 w-4" />{{ ROLES[r]?.label ?? r }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="g in GROUPS" :key="g.key">
                            <tr class="bg-gray-50/60 dark:bg-gray-900/30"><td :colspan="roles.length + 1" class="px-4 py-2 text-xs font-semibold text-gray-500"><component :is="g.icon" class="me-1 inline h-4 w-4" />{{ g.label }}</td></tr>
                            <tr v-for="a in g.actions" :key="a" class="border-t border-gray-100 dark:border-gray-700">
                                <td class="px-4 py-2.5 text-gray-800 dark:text-gray-200">{{ ACTIONS[a]?.label ?? a }}</td>
                                <td v-for="r in roles" :key="r" class="px-3 py-2.5 text-center">
                                    <LockClosedIcon v-if="!editable(r, a)" class="mx-auto h-4 w-4 text-gray-300 dark:text-gray-600" />
                                    <button v-else type="button" @click="toggle(r, a)" class="mx-auto flex h-7 w-7 items-center justify-center rounded-lg border transition" :class="form.matrix[r][a] ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white' : 'border-gray-300 text-transparent hover:border-gray-400 dark:border-gray-600'" :aria-pressed="form.matrix[r][a]">
                                        <CheckIcon class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Save bar -->
            <div class="sticky bottom-0 z-10 -mx-4 border-t border-gray-200 bg-white/95 backdrop-blur sm:-mx-6 dark:border-gray-700 dark:bg-gray-900/95">
                <div class="flex items-center justify-between gap-3 px-4 py-3 sm:px-6">
                    <p class="text-sm" :class="changed ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-gray-500'">
                        <template v-if="changed">{{ t('permissions.changed', 'تغييرات غير محفوظة') }}: {{ changed }}</template>
                        <template v-else-if="form.recentlySuccessful">{{ t('permissions.saved', 'تم حفظ الصلاحيات') }}</template>
                        <template v-else>{{ t('permissions.no_changes', 'لا توجد تغييرات') }}</template>
                    </p>
                    <div class="flex gap-2">
                        <button v-if="changed" type="button" @click="form.reset()" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.undo', 'تراجع') }}</button>
                        <button type="button" @click="submit" :disabled="form.processing || !changed" class="rounded-xl bg-[var(--accent-600)] px-6 py-2 text-sm font-semibold text-white disabled:opacity-40">{{ t('permissions.save', 'حفظ الصلاحيات') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
