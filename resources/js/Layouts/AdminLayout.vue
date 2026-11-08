<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    Squares2X2Icon,
    UsersIcon,
    Cog6ToothIcon,
    ClipboardDocumentListIcon,
    ArrowUturnLeftIcon,
    Bars3Icon,
    XMarkIcon,
    LockClosedIcon,
    EnvelopeIcon,
    LanguageIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const page = usePage();
const user = computed(() => page.props.auth.user);
const admin = computed(() => page.props.permissions?.admin ?? {});
const mobileOpen = ref(false);

const nav = computed(() => [
    { label: t('admin.nav.dashboard', 'لوحة التحكم'), href: () => route('admin.dashboard'), icon: Squares2X2Icon, active: () => route().current('admin.dashboard'), show: true },
    { label: t('admin.nav.users', 'المستخدمون'), href: () => route('admin.users.index'), icon: UsersIcon, active: () => route().current('admin.users.*'), show: admin.value.users },
    { label: t('admin.nav.settings', 'الإعدادات العامة'), href: () => route('admin.settings.index'), icon: Cog6ToothIcon, active: () => route().current('admin.settings.*'), show: admin.value.settings || admin.value.appearance },
    { label: t('admin.nav.security', 'الأمان وتسجيل الدخول'), href: () => route('admin.security.index'), icon: LockClosedIcon, active: () => route().current('admin.security.*'), show: admin.value.settings },
    { label: t('admin.nav.mail', 'البريد الإلكتروني'), href: () => route('admin.mail.index'), icon: EnvelopeIcon, active: () => route().current('admin.mail.*'), show: admin.value.settings },
    { label: t('admin.nav.translations', 'الترجمات'), href: () => route('admin.translations.index'), icon: LanguageIcon, active: () => route().current('admin.translations.*'), show: admin.value.translations },
    { label: t('admin.nav.activity', 'سجل النشاطات'), href: () => route('admin.activity.index'), icon: ClipboardDocumentListIcon, active: () => route().current('admin.activity.*'), show: admin.value.statistics },
]);
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
        <aside
            class="fixed inset-y-0 z-30 w-64 shrink-0 border-e border-gray-200 bg-white transition-transform lg:static lg:translate-x-0 dark:border-gray-700 dark:bg-gray-800"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full lg:rtl:translate-x-0'"
        >
            <div class="flex h-16 items-center gap-2 border-b border-gray-200 px-4 dark:border-gray-700">
                <ApplicationLogo class="h-8 w-8 fill-current text-[var(--accent-600)]" />
                <span class="text-lg font-bold">{{ t('nav.admin', 'لوحة الإدارة') }}</span>
                <button class="ms-auto lg:hidden" @click="mobileOpen = false">
                    <XMarkIcon class="h-6 w-6" />
                </button>
            </div>

            <nav class="space-y-1 p-3">
                <Link
                    v-for="item in nav.filter((i) => i.show)"
                    :key="item.label"
                    :href="item.href()"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition"
                    :class="item.active() ? 'bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                >
                    <component :is="item.icon" class="h-5 w-5" />
                    {{ item.label }}
                </Link>

                <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <Link :href="route('exams.index')" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        <ArrowUturnLeftIcon class="h-5 w-5" />
                        {{ t('admin.nav.back_to_files', 'العودة إلى ملفاتي') }}
                    </Link>
                </div>
            </nav>
        </aside>

        <div v-if="mobileOpen" class="fixed inset-0 z-20 bg-black/30 lg:hidden" @click="mobileOpen = false" />

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex min-h-16 items-center gap-3 border-b border-gray-200 bg-white px-4 py-2 sm:gap-4 dark:border-gray-700 dark:bg-gray-800">
                <button class="shrink-0 lg:hidden" @click="mobileOpen = true">
                    <Bars3Icon class="h-6 w-6" />
                </button>
                <div class="min-w-0 flex-1">
                    <slot name="header" />
                </div>
                <NotificationBell />
                <ThemeToggle />
                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button class="flex shrink-0 items-center gap-2 rounded-full bg-gray-100 p-1 text-sm font-medium text-gray-700 hover:bg-gray-200 sm:pe-3 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            <UserAvatar :user="user" />
                            <span class="hidden max-w-[10rem] truncate sm:inline">{{ user.name }}</span>
                        </button>
                    </template>
                    <template #content>
                        <DropdownLink :href="route('profile.edit')">{{ t('nav.profile', 'الملف الشخصي والإعدادات') }}</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">{{ t('nav.logout', 'تسجيل الخروج') }}</DropdownLink>
                    </template>
                </Dropdown>
            </header>

            <FlashMessages />

            <main class="flex-1 overflow-y-auto">
                <slot />
            </main>
        </div>
    </div>
</template>
