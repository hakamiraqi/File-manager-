<script setup>
import {
    FolderIcon, EyeIcon, EyeSlashIcon, SparklesIcon, ShieldCheckIcon, PencilSquareIcon, PlusCircleIcon, UserIcon,
    BookOpenIcon, XMarkIcon, ClipboardDocumentIcon, CheckIcon,
} from '@heroicons/vue/24/outline';
import UserAvatar from '@/Components/UserAvatar.vue';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import { computed, ref, watch } from 'vue';
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
    folderOptions: { type: Array, default: () => [] }, // [{id, path, owner}]
});
const emit = defineEmits(['close']);

const isMasterAdmin = computed(() => usePage().props.auth.user?.is_master_admin ?? false);

const CAPABILITIES = [
    { key: 'can_manage_users', label: () => t('capability.manage_users', 'إدارة المستخدمين'), hint: () => t('capability.manage_users_hint', 'إضافة الحسابات وتعديلها وإيقافها') },
    { key: 'can_view_statistics', label: () => t('capability.view_statistics', 'عرض التحليلات والسجلات'), hint: () => t('capability.view_statistics_hint', 'الإحصائيات وسجل النشاطات') },
    { key: 'can_manage_settings', label: () => t('capability.manage_settings', 'إدارة الإعدادات العامة'), hint: () => t('capability.manage_settings_hint', 'اسم الموقع والتسجيل والبريد') },
    { key: 'can_manage_appearance', label: () => t('capability.manage_appearance', 'إدارة المظهر'), hint: () => t('capability.manage_appearance_hint', 'الشعار والألوان') },
    { key: 'can_manage_translations', label: () => t('capability.manage_translations', 'إدارة الترجمات'), hint: () => t('capability.manage_translations_hint', 'نصوص الواجهة') },
];

const ROLES = [
    { key: 'user', label: () => t('role.user', 'مستخدم'), hint: () => t('role.user_hint', 'يتصفح ويرفع ملفاته'), icon: UserIcon },
    { key: 'contributor', label: () => t('role.contributor', 'مساهم'), hint: () => t('role.contributor_hint', 'يضيف محتوى دون حذف'), icon: PlusCircleIcon },
    { key: 'editor', label: () => t('role.editor', 'محرّر'), hint: () => t('role.editor_hint', 'يرفع ويعدّل وينظّم'), icon: PencilSquareIcon },
    { key: 'admin', label: () => t('role.admin', 'مدير'), hint: () => t('role.admin_hint', 'صلاحيات إدارية'), icon: ShieldCheckIcon, masterOnly: true },
];
const QUOTAS = [['', () => t('admin.users.quota_default', 'الافتراضي')], [500, () => '500 MB'], [1024, () => '1 GB'], [5120, () => '5 GB'], [10240, () => '10 GB']];

const showPassword = ref(false);
const copied = ref(false);
function generatePassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    const arr = new Uint32Array(12);
    crypto.getRandomValues(arr);
    form.password = Array.from(arr, (n) => chars[n % chars.length]).join('') + '#7';
    showPassword.value = true;
}
async function copyPassword() {
    try {
        await navigator.clipboard.writeText(form.password);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch { /* not allowed */ }
}
const isSelf = computed(() => props.user && props.user.id === usePage().props.auth.user?.id);
const lockActive = computed(() => isSelf.value || props.user?.is_master_admin);

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: 'user',
    storage_quota_mb: '',
    folder_ids: [],
    is_librarian: false,
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
            showPassword.value = false;
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
                form.folder_ids = [...(props.user.folder_ids ?? [])];
                form.is_librarian = !!props.user.is_librarian;
            } else {
                form.reset();
                form.folder_ids = [];
                form.role = 'user';
                form.is_active = true;
            }
        }
    },
);

// Assigned folders: the user works inside them with their role's permissions.
const folderSearch = ref('');
const pickedFolders = computed(() => props.folderOptions.filter((f) => form.folder_ids.includes(f.id)));
const folderMatches = computed(() => {
    const q = folderSearch.value.trim().toLowerCase();
    return props.folderOptions
        .filter((f) => !form.folder_ids.includes(f.id) && (!q || f.path.toLowerCase().includes(q)))
        .slice(0, 50);
});
function toggleFolder(id) {
    form.folder_ids = form.folder_ids.includes(id) ? form.folder_ids.filter((x) => x !== id) : [...form.folder_ids, id];
}

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
    <Modal :show="show" max-width="2xl" @close="$emit('close')">
        <form @submit.prevent="submit" class="flex max-h-[90vh] flex-col">
            <!-- Header -->
            <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                <UserAvatar :user="{ name: form.name || '+', avatar_url: user?.avatar_url }" size="h-11 w-11 text-lg" />
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-lg font-semibold text-gray-900 dark:text-gray-100">{{ user ? t('admin.users.edit', 'تعديل المستخدم') : t('admin.users.new', 'مستخدم جديد') }}</h2>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ form.name || t('admin.users.fill', 'املأ البيانات ثم احفظ') }}</p>
                </div>
                <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" @click="$emit('close')"><XMarkIcon class="h-5 w-5" /></button>
            </div>

            <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5">
                <!-- Status -->
                <div class="flex items-center gap-3 rounded-xl border p-3" :class="form.is_active ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/50 dark:bg-emerald-500/10' : 'border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-500/10'">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="form.is_active ? 'bg-emerald-500' : 'bg-red-500'" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold" :class="form.is_active ? 'text-emerald-800 dark:text-emerald-300' : 'text-red-800 dark:text-red-300'">
                            {{ form.is_active ? t('admin.users.status_active', 'الحساب نشط') : t('admin.users.status_inactive', 'الحساب غير نشط') }}
                        </p>
                        <p class="text-xs text-gray-600 dark:text-gray-400">
                            {{ lockActive ? t('admin.users.status_locked', 'لا يمكن إيقاف هذا الحساب.') : form.is_active ? t('admin.users.status_active_hint', 'يستطيع الدخول واستخدام النظام.') : t('admin.users.status_inactive_hint', 'لا يستطيع الدخول، وتبقى ملفاته محفوظة.') }}
                        </p>
                    </div>
                    <ToggleSwitch v-model="form.is_active" tone="green" :disabled="lockActive" />
                </div>
                <InputError :message="form.errors.is_active" />

                <!-- Basics -->
                <section class="space-y-4">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ t('admin.users.basics', 'البيانات الأساسية') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel :value="t('auth.name', 'الاسم')" />
                            <TextInput v-model="form.name" class="mt-1 block w-full" />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel :value="t('auth.email', 'البريد الإلكتروني')" />
                            <TextInput type="email" dir="ltr" v-model="form.email" class="mt-1 block w-full" />
                            <InputError :message="form.errors.email" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel :value="user ? t('admin.users.new_password_optional', 'كلمة مرور جديدة (اختياري)') : t('auth.password', 'كلمة المرور')" />
                        <div class="mt-1 flex gap-2">
                            <div class="relative flex-1">
                                <TextInput :type="showPassword ? 'text' : 'password'" dir="ltr" v-model="form.password" autocomplete="new-password" class="block w-full pe-10" />
                                <button type="button" class="absolute end-2 top-1/2 -translate-y-1/2 p-1 text-gray-400" @click="showPassword = !showPassword">
                                    <component :is="showPassword ? EyeSlashIcon : EyeIcon" class="h-5 w-5" />
                                </button>
                            </div>
                            <button v-if="form.password && showPassword" type="button" class="rounded-lg border border-gray-300 px-2.5 text-gray-500 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700" :title="t('action.copy', 'نسخ')" @click="copyPassword">
                                <component :is="copied ? CheckIcon : ClipboardDocumentIcon" class="h-5 w-5" />
                            </button>
                            <button type="button" class="inline-flex items-center gap-1 whitespace-nowrap rounded-lg border border-gray-300 px-3 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700" @click="generatePassword">
                                <SparklesIcon class="h-4 w-4" />{{ t('admin.users.generate', 'توليد') }}
                            </button>
                        </div>
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                </section>

                <!-- Role -->
                <section>
                    <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ t('admin.users.role', 'الدور') }}</h3>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <button
                            v-for="r in ROLES" :key="r.key" type="button"
                            :disabled="r.masterOnly && !isMasterAdmin && user?.role !== 'admin'"
                            class="flex flex-col items-start gap-1 rounded-xl border p-3 text-start transition disabled:cursor-not-allowed disabled:opacity-40"
                            :class="form.role === r.key ? 'border-[var(--accent-600)] bg-[var(--accent-50)] ring-1 ring-[var(--accent-600)] dark:bg-gray-700' : 'border-gray-200 hover:border-gray-300 dark:border-gray-600'"
                            @click="form.role = r.key"
                        >
                            <component :is="r.icon" class="h-5 w-5" :class="form.role === r.key ? 'text-[var(--accent-600)]' : 'text-gray-400'" />
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ r.label() }}</span>
                            <span class="text-[11px] leading-snug text-gray-500 dark:text-gray-400">{{ r.hint() }}</span>
                        </button>
                    </div>
                    <InputError :message="form.errors.role" class="mt-1" />
                </section>

                <!-- Admin capabilities -->
                <section v-if="form.role === 'admin' && isMasterAdmin" class="rounded-xl border border-gray-200 dark:border-gray-700">
                    <h3 class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-200">{{ t('admin.users.admin_capabilities', 'صلاحيات هذا المدير') }}</h3>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        <label v-for="c in CAPABILITIES" :key="c.key" class="flex cursor-pointer items-center gap-3 px-4 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-gray-800 dark:text-gray-100">{{ c.label() }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ c.hint() }}</p>
                            </div>
                            <ToggleSwitch v-model="form[c.key]" size="sm" />
                        </label>
                    </div>
                </section>

                <!-- Storage -->
                <section>
                    <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ t('admin.users.quota_title', 'مساحة التخزين') }}</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="[mb, label] in QUOTAS" :key="mb" type="button"
                            class="rounded-full border px-3 py-1.5 text-sm"
                            :class="String(form.storage_quota_mb) === String(mb) ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white' : 'border-gray-200 text-gray-600 hover:border-gray-300 dark:border-gray-600 dark:text-gray-300'"
                            @click="form.storage_quota_mb = mb"
                        >{{ label() }}</button>
                        <div class="flex items-center gap-1">
                            <TextInput type="number" min="1" v-model="form.storage_quota_mb" :placeholder="t('admin.users.quota_custom', 'مخصص')" class="block w-28 text-sm" />
                            <span class="text-xs text-gray-400">MB</span>
                        </div>
                    </div>
                    <InputError :message="form.errors.storage_quota_mb" class="mt-1" />
                </section>

                <!-- Library -->
                <label v-if="form.role !== 'admin'" class="flex cursor-pointer items-center gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300"><BookOpenIcon class="h-5 w-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ t('admin.users.librarian_title', 'أمين مكتبة') }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('admin.users.librarian_hint', 'يعير ويستلم الكتب ويدير الفهرس') }}</p>
                    </div>
                    <ToggleSwitch v-model="form.is_librarian" />
                </label>

                <!-- Folders -->
                <section v-if="form.role !== 'admin'">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ t('admin.users.folders', 'المجلدات المخصّصة له') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ t('admin.users.folders_hint', 'تظهر له هذه المجلدات في ملفاته، ويعمل داخلها حسب صلاحيته (المحرّر يرفع ويعدّل ويضيف).') }}
                    </p>
                    <div v-if="pickedFolders.length" class="mt-2 flex flex-wrap gap-1.5">
                        <span v-for="f in pickedFolders" :key="f.id" class="inline-flex items-center gap-1 rounded-full bg-[var(--accent-50)] py-0.5 pe-1 ps-2.5 text-sm text-[var(--accent-700)] dark:bg-gray-600 dark:text-gray-100">
                            <FolderIcon class="h-3.5 w-3.5" />{{ f.path }}
                            <button type="button" class="rounded-full p-0.5 hover:bg-white/60 dark:hover:bg-gray-500" @click="toggleFolder(f.id)"><XMarkIcon class="h-3.5 w-3.5" /></button>
                        </span>
                    </div>
                    <TextInput v-model="folderSearch" :placeholder="t('admin.users.folders_search', 'ابحث عن مجلد لإضافته...')" class="mt-2 block w-full" />
                    <div class="mt-1 max-h-40 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <button v-for="f in folderMatches" :key="f.id" type="button" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700" @click="toggleFolder(f.id)">
                            <span class="flex min-w-0 items-center gap-1"><FolderIcon class="h-4 w-4 shrink-0 text-amber-500" /><span class="truncate">{{ f.path }}</span></span>
                            <span class="shrink-0 text-xs text-gray-400">{{ f.owner }}</span>
                        </button>
                        <p v-if="!folderMatches.length" class="px-3 py-2 text-sm text-gray-400">{{ t('admin.users.folders_none', 'لا توجد مجلدات مطابقة.') }}</p>
                    </div>
                    <InputError :message="form.errors.folder_ids" class="mt-1" />
                </section>
            </div>

            <!-- Footer -->
            <div class="flex justify-end gap-3 border-t border-gray-100 px-5 py-3 dark:border-gray-700">
                <SecondaryButton @click="$emit('close')">{{ t('action.cancel', 'إلغاء') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing">{{ user ? t('action.save', 'حفظ') : t('admin.users.create', 'إنشاء الحساب') }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
