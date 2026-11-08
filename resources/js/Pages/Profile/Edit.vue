<script setup>
import { computed, ref, onMounted } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import AvatarCropper from '@/Components/AvatarCropper.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import PreferencesForm from './Partials/PreferencesForm.vue';
import TwoFactorAuthenticationForm from './Partials/TwoFactorAuthenticationForm.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    CameraIcon, TrashIcon, UserCircleIcon, ShieldCheckIcon, AdjustmentsHorizontalIcon, ExclamationTriangleIcon,
    DocumentIcon, FolderIcon, BoltIcon, CircleStackIcon, CalendarDaysIcon, ClockIcon, EnvelopeIcon, CheckBadgeIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();

const props = defineProps({
    mustVerifyEmail: { type: Boolean },
    status: { type: String },
    stats: { type: Object, default: () => ({}) },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const twoFactor = computed(() => page.props.auth.twoFactorEnabled);

const ROLE_LABELS = {
    admin: t('role.admin', 'مدير'), editor: t('role.editor', 'محرّر'),
    contributor: t('role.contributor', 'مساهم'), user: t('role.user', 'مستخدم'),
};

const tabs = [
    { key: 'info', label: t('profile.tab_info', 'المعلومات الشخصية'), icon: UserCircleIcon },
    { key: 'security', label: t('profile.tab_security', 'الأمان وكلمة المرور'), icon: ShieldCheckIcon },
    { key: 'preferences', label: t('profile.tab_preferences', 'التفضيلات'), icon: AdjustmentsHorizontalIcon },
    { key: 'danger', label: t('profile.tab_danger', 'حذف الحساب'), icon: ExclamationTriangleIcon },
];
const tab = ref('info');
// The open section survives the page reload after saving a form.
onMounted(() => {
    let h = window.location.hash.slice(1);
    if (!h) {
        try { h = sessionStorage.getItem('profile-tab') || ''; } catch { h = ''; }
    }
    if (tabs.some((x) => x.key === h)) tab.value = h;
});
function pick(key) {
    tab.value = key;
    try { sessionStorage.setItem('profile-tab', key); } catch { /* private mode */ }
}

// Profile picture
const fileInput = ref(null);
const chosen = ref(null);
const busy = ref(false);
const avatarError = ref(null);

function choose() {
    avatarError.value = null;
    fileInput.value?.click();
}
function picked(e) {
    const f = e.target.files?.[0];
    e.target.value = '';
    if (!f) return;
    if (!f.type.startsWith('image/')) {
        avatarError.value = t('profile.avatar_not_image', 'اختر ملف صورة (JPG أو PNG أو WEBP).');
        return;
    }
    if (f.size > 15 * 1024 * 1024) {
        avatarError.value = t('profile.avatar_too_big', 'الصورة كبيرة جداً، الحد 15 ميغابايت.');
        return;
    }
    chosen.value = f;
}
function upload(blob) {
    busy.value = true;
    router.post(route('profile.avatar'), { avatar: new File([blob], 'avatar.jpg', { type: 'image/jpeg' }) }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { chosen.value = null; },
        onError: (errors) => { avatarError.value = errors.avatar || t('profile.avatar_failed', 'تعذّر رفع الصورة.'); chosen.value = null; },
        onFinish: () => { busy.value = false; },
    });
}
function removeAvatar() {
    if (!confirm(t('profile.avatar_remove_confirm', 'حذف الصورة الشخصية؟'))) return;
    router.delete(route('profile.avatar.destroy'), { preserveScroll: true });
}

// Numbers
const nf = new Intl.NumberFormat('ar-IQ');
function bytes(n) {
    if (!n) return '0';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(u.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
    return (n / 1024 ** i).toFixed(i ? 1 : 0) + ' ' + u[i];
}
function date(iso, withTime = false) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('ar-IQ', {
        timeZone: 'Asia/Baghdad', year: 'numeric', month: 'long', day: 'numeric',
        ...(withTime ? { hour: 'numeric', minute: '2-digit' } : {}),
    });
}
const storagePercent = computed(() => {
    const total = props.stats.storage_total;
    return total ? Math.min(100, Math.round((props.stats.storage_used / total) * 100)) : 0;
});
const cards = computed(() => [
    { label: t('profile.stat_files', 'الملفات'), value: nf.format(props.stats.files ?? 0), icon: DocumentIcon, tone: 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300' },
    { label: t('profile.stat_folders', 'المجلدات'), value: nf.format(props.stats.folders ?? 0), icon: FolderIcon, tone: 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300' },
    { label: t('profile.stat_activity', 'نشاط آخر 30 يوماً'), value: nf.format(props.stats.activity_30d ?? 0), icon: BoltIcon, tone: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' },
]);
</script>

<template>
    <Head :title="t('nav.profile', 'الملف الشخصي والإعدادات')" />

    <AppLayout>
        <template #header>
            <h2 class="truncate text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('nav.profile_short', 'الملف الشخصي') }}</h2>
        </template>

        <div class="py-6 sm:py-10">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Header card -->
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="h-24 bg-gradient-to-l from-[var(--accent-600)] via-[var(--accent-500)] to-[var(--accent-700)] sm:h-32" />
                    <div class="px-5 pb-5 sm:px-8">
                        <div class="-mt-12 flex flex-col items-center gap-4 sm:-mt-14 sm:flex-row sm:items-end">
                            <div class="relative">
                                <div class="rounded-full bg-white p-1 shadow-md dark:bg-gray-800">
                                    <UserAvatar :user="user" size="h-24 w-24 text-3xl sm:h-28 sm:w-28 sm:text-4xl" />
                                </div>
                                <button
                                    type="button"
                                    class="absolute bottom-1 end-1 flex h-9 w-9 items-center justify-center rounded-full bg-[var(--accent-600)] text-white shadow-lg ring-4 ring-white hover:bg-[var(--accent-700)] dark:ring-gray-800"
                                    :title="t('profile.avatar_change', 'تغيير الصورة')"
                                    @click="choose"
                                >
                                    <CameraIcon class="h-5 w-5" />
                                </button>
                                <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="picked" />
                            </div>

                            <div class="min-w-0 flex-1 text-center sm:pb-1 sm:text-start">
                                <h1 class="truncate text-xl font-bold text-gray-900 dark:text-gray-50 sm:text-2xl">{{ user.name }}</h1>
                                <p class="mt-0.5 flex items-center justify-center gap-1.5 truncate text-sm text-gray-500 dark:text-gray-400 sm:justify-start" dir="ltr">
                                    <EnvelopeIcon class="h-4 w-4 shrink-0" />{{ user.email }}
                                </p>
                                <div class="mt-2 flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                                    <span class="rounded-full bg-[var(--accent-50)] px-2.5 py-0.5 text-xs font-semibold text-[var(--accent-700)] dark:bg-gray-700 dark:text-gray-200">
                                        {{ ROLE_LABELS[user.role] ?? user.role }}
                                    </span>
                                    <span v-if="user.is_librarian" class="rounded-full bg-violet-50 px-2.5 py-0.5 text-xs font-semibold text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">
                                        {{ t('profile.librarian', 'أمين المكتبة') }}
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                        :class="twoFactor ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                                    >
                                        <CheckBadgeIcon v-if="twoFactor" class="h-3.5 w-3.5" />
                                        {{ twoFactor ? t('profile.2fa_on', 'التحقق بخطوتين مفعّل') : t('profile.2fa_off', 'التحقق بخطوتين غير مفعّل') }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex gap-2 sm:pb-1">
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="choose">
                                    <CameraIcon class="h-4 w-4" />{{ user.avatar_url ? t('profile.avatar_change', 'تغيير الصورة') : t('profile.avatar_add', 'إضافة صورة') }}
                                </button>
                                <button v-if="user.avatar_url" type="button" class="inline-flex items-center rounded-lg border border-gray-200 px-2.5 py-2 text-red-600 hover:bg-red-50 dark:border-gray-600 dark:hover:bg-red-500/10" :title="t('profile.avatar_remove', 'حذف الصورة')" @click="removeAvatar">
                                    <TrashIcon class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <p v-if="avatarError" class="mt-3 text-center text-sm text-red-600 sm:text-start">{{ avatarError }}</p>

                        <!-- Numbers -->
                        <div class="mt-6 grid grid-cols-3 gap-3">
                            <div v-for="c in cards" :key="c.label" class="flex flex-col items-center gap-2 rounded-xl border border-gray-100 p-3 text-center dark:border-gray-700 sm:flex-row sm:text-start">
                                <span :class="[c.tone, 'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg']"><component :is="c.icon" class="h-5 w-5" /></span>
                                <div class="min-w-0">
                                    <p class="text-lg font-bold text-gray-900 dark:text-gray-50">{{ c.value }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ c.label }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-700 sm:col-span-1">
                                <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                    <span class="flex items-center gap-1.5"><CircleStackIcon class="h-4 w-4" />{{ t('profile.storage', 'المساحة المستخدمة') }}</span>
                                    <span dir="ltr">{{ bytes(stats.storage_used) }}<template v-if="stats.storage_total"> / {{ bytes(stats.storage_total) }}</template></span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                    <div class="h-full rounded-full" :class="storagePercent > 90 ? 'bg-red-500' : storagePercent > 70 ? 'bg-amber-500' : 'bg-[var(--accent-600)]'" :style="{ width: storagePercent + '%' }" />
                                </div>
                            </div>
                            <div class="flex items-center gap-2 rounded-xl border border-gray-100 p-3 text-sm dark:border-gray-700">
                                <CalendarDaysIcon class="h-5 w-5 shrink-0 text-gray-400" />
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('profile.member_since', 'عضو منذ') }}</p>
                                    <p class="truncate font-medium text-gray-800 dark:text-gray-100">{{ date(stats.member_since) }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 rounded-xl border border-gray-100 p-3 text-sm dark:border-gray-700">
                                <ClockIcon class="h-5 w-5 shrink-0 text-gray-400" />
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t('profile.last_login', 'آخر دخول') }}</p>
                                    <p class="truncate font-medium text-gray-800 dark:text-gray-100">{{ date(stats.last_login_at, true) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[15rem_1fr]">
                    <!-- Sections: chips on phone, side list on desktop -->
                    <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 lg:mx-0 lg:flex-col lg:self-start lg:overflow-visible lg:rounded-2xl lg:border lg:border-gray-200 lg:bg-white lg:p-2 lg:dark:border-gray-700 lg:dark:bg-gray-800">
                        <button
                            v-for="x in tabs" :key="x.key" type="button" @click="pick(x.key)"
                            class="flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2.5 text-sm font-medium transition"
                            :class="tab === x.key
                                ? (x.key === 'danger' ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-[var(--accent-600)] text-white shadow-sm')
                                : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 lg:border-0'"
                        >
                            <component :is="x.icon" class="h-5 w-5" />{{ x.label }}
                        </button>
                    </nav>

                    <div class="min-w-0 space-y-6">
                        <div v-show="tab === 'info'" class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-8">
                            <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" class="max-w-xl" />
                        </div>

                        <div v-show="tab === 'security'" class="space-y-6">
                            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-8">
                                <UpdatePasswordForm class="max-w-xl" />
                            </div>
                            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-8">
                                <TwoFactorAuthenticationForm class="max-w-xl" />
                            </div>
                        </div>

                        <div v-show="tab === 'preferences'" class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-8">
                            <PreferencesForm class="max-w-xl" />
                        </div>

                        <div v-show="tab === 'danger'" class="rounded-2xl border border-red-200 bg-white p-5 shadow-sm dark:border-red-900/50 dark:bg-gray-800 sm:p-8">
                            <DeleteUserForm class="max-w-xl" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <AvatarCropper :file="chosen" :busy="busy" @close="chosen = null" @cropped="upload" />
    </AppLayout>
</template>
