<script setup>
import UserAvatar from '@/Components/UserAvatar.vue';
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import { useTrans } from '@/Composables/useTrans';
import { UserGroupIcon,
    Bars3Icon,
    XMarkIcon,
    ShieldCheckIcon,
    ClipboardDocumentListIcon,
    PencilSquareIcon,
    ArchiveBoxIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const page = usePage();
const user = computed(() => page.props.auth.user);
const mobileOpen = ref(false);

const nav = computed(() => [
    { label: t('nav.exams', 'امتحاناتي'), href: route('exams.index'), icon: ClipboardDocumentListIcon, active: route().current('exams.index') || route().current('exams.show') || route().current('exams.attempts.*') },
    ...(page.props.permissions?.manage_exams
        ? [
            { label: t('nav.exams_manage', 'إدارة الامتحانات'), href: route('exams.manage.index'), icon: PencilSquareIcon, active: route().current('exams.manage.*') && !route().current('exams.manage.bank') && !route().current('exams.manage.groups') },
            { label: t('exam.bank', 'بنك الأسئلة'), href: route('exams.manage.bank'), icon: ArchiveBoxIcon, active: route().current('exams.manage.bank') },
            { label: t('exam.groups', 'الصفوف والمجموعات'), href: route('exams.manage.groups'), icon: UserGroupIcon, active: route().current('exams.manage.groups') },
        ]
        : []),
]);

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 z-30 w-64 shrink-0 border-e border-gray-200 bg-white transition-transform lg:static lg:translate-x-0 dark:border-gray-700 dark:bg-gray-800"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full lg:rtl:translate-x-0'"
        >
            <div class="flex h-16 items-center gap-2 border-b border-gray-200 px-4 dark:border-gray-700">
                <Link :href="route('exams.index')" class="flex items-center gap-2">
                    <ApplicationLogo class="h-8 w-8 fill-current text-[var(--accent-600)]" />
                    <span class="text-lg font-bold">{{ $page.props.appSettings.siteName }}</span>
                </Link>
                <button class="ms-auto lg:hidden" @click="mobileOpen = false">
                    <XMarkIcon class="h-6 w-6" />
                </button>
            </div>

            <nav class="space-y-1 p-3">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition"
                    :class="item.active ? 'bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                >
                    <component :is="item.icon" class="h-5 w-5" />
                    {{ item.label }}
                    <span v-if="item.badge" class="ms-auto rounded-full bg-red-500 px-1.5 text-xs text-white">{{ item.badge }}</span>
                </Link>

                <div v-if="user.role === 'admin'" class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <Link
                        :href="route('admin.dashboard')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                        :class="{ 'bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700': route().current('admin.*') }"
                    >
                        <ShieldCheckIcon class="h-5 w-5" />
                        {{ t('nav.admin', 'لوحة الإدارة') }}
                    </Link>
                </div>
            </nav>

        </aside>

        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-20 bg-black/30 lg:hidden"
            @click="mobileOpen = false"
        />

        <!-- Main -->
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex min-h-16 items-center gap-3 border-b py-2 sm:gap-4 border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-800">
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
                        <button class="flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            <UserAvatar :user="user" />
                            <span class="hidden max-w-[10rem] truncate sm:inline">{{ user.name }}</span>
                        </button>
                    </template>
                    <template #content>
                        <DropdownLink :href="route('profile.edit')">{{ t('nav.profile', 'الملف الشخصي والإعدادات') }}</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button" @click="logout">{{ t('nav.logout', 'تسجيل الخروج') }}</DropdownLink>
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
