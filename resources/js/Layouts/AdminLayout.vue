<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import {
    Squares2X2Icon,
    UsersIcon,
    Cog6ToothIcon,
    ClipboardDocumentListIcon,
    ArrowUturnLeftIcon,
    Bars3Icon,
    XMarkIcon,
    ShieldCheckIcon,
    LockClosedIcon,
    EnvelopeIcon,
    ChartBarIcon,
    LanguageIcon,
} from '@heroicons/vue/24/outline';

const page = usePage();
const user = computed(() => page.props.auth.user);
const admin = computed(() => page.props.permissions?.admin ?? {});
const mobileOpen = ref(false);

const nav = computed(() => [
    { label: 'لوحة التحكم', href: () => route('admin.dashboard'), icon: Squares2X2Icon, active: () => route().current('admin.dashboard'), show: true },
    { label: 'المستخدمون', href: () => route('admin.users.index'), icon: UsersIcon, active: () => route().current('admin.users.*'), show: admin.value.users },
    { label: 'الصلاحيات', href: () => route('admin.permissions.index'), icon: ShieldCheckIcon, active: () => route().current('admin.permissions.*'), show: admin.value.settings },
    { label: 'الإعدادات العامة', href: () => route('admin.settings.index'), icon: Cog6ToothIcon, active: () => route().current('admin.settings.*'), show: admin.value.settings || admin.value.appearance },
    { label: 'الأمان وتسجيل الدخول', href: () => route('admin.security.index'), icon: LockClosedIcon, active: () => route().current('admin.security.*'), show: admin.value.settings },
    { label: 'البريد الإلكتروني', href: () => route('admin.mail.index'), icon: EnvelopeIcon, active: () => route().current('admin.mail.*'), show: admin.value.settings },
    { label: 'التحليلات', href: () => route('admin.analytics.index'), icon: ChartBarIcon, active: () => route().current('admin.analytics.*'), show: admin.value.statistics },
    { label: 'الترجمات', href: () => route('admin.translations.index'), icon: LanguageIcon, active: () => route().current('admin.translations.*'), show: admin.value.translations },
    { label: 'سجل النشاطات', href: () => route('admin.activity.index'), icon: ClipboardDocumentListIcon, active: () => route().current('admin.activity.*'), show: admin.value.statistics },
]);
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-gray-50 text-gray-900">
        <aside
            class="fixed inset-y-0 z-30 w-64 shrink-0 border-e border-gray-200 bg-white transition-transform lg:static lg:translate-x-0"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full lg:rtl:translate-x-0'"
        >
            <div class="flex h-16 items-center gap-2 border-b border-gray-200 px-4">
                <ApplicationLogo class="h-8 w-8 fill-current text-[var(--accent-600)]" />
                <span class="text-lg font-bold">لوحة الإدارة</span>
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
                    :class="item.active() ? 'bg-[var(--accent-50)] text-[var(--accent-700)]' : 'text-gray-700 hover:bg-gray-100'"
                >
                    <component :is="item.icon" class="h-5 w-5" />
                    {{ item.label }}
                </Link>

                <div class="mt-4 border-t border-gray-200 pt-4">
                    <Link :href="route('dashboard')" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
                        <ArrowUturnLeftIcon class="h-5 w-5" />
                        العودة إلى ملفاتي
                    </Link>
                </div>
            </nav>
        </aside>

        <div v-if="mobileOpen" class="fixed inset-0 z-20 bg-black/30 lg:hidden" @click="mobileOpen = false" />

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-16 items-center gap-4 border-b border-gray-200 bg-white px-4">
                <button class="lg:hidden" @click="mobileOpen = true">
                    <Bars3Icon class="h-6 w-6" />
                </button>
                <div class="min-w-0 flex-1">
                    <slot name="header" />
                </div>
                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button class="flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-200">
                            {{ user.name }}
                        </button>
                    </template>
                    <template #content>
                        <DropdownLink :href="route('profile.edit')">الملف الشخصي</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">تسجيل الخروج</DropdownLink>
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
