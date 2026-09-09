<script setup>
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import StorageMeter from '@/Components/StorageMeter.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import {
    FolderIcon,
    ShareIcon,
    TrashIcon,
    Cog6ToothIcon,
    Bars3Icon,
    XMarkIcon,
    ShieldCheckIcon,
} from '@heroicons/vue/24/outline';

const page = usePage();
const user = computed(() => page.props.auth.user);
const quota = computed(() => page.props.quota);
const mobileOpen = ref(false);

const nav = computed(() => [
    { label: 'ملفاتي', href: route('dashboard'), icon: FolderIcon, active: route().current('dashboard') },
    { label: 'الروابط المشتركة', href: route('shares.index'), icon: ShareIcon, active: route().current('shares.index') },
    { label: 'سلة المحذوفات', href: route('trash.index'), icon: TrashIcon, active: route().current('trash.index') },
]);

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-gray-50 text-gray-900">
        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 z-30 w-64 shrink-0 border-e border-gray-200 bg-white transition-transform lg:static lg:translate-x-0"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full lg:rtl:translate-x-0'"
        >
            <div class="flex h-16 items-center gap-2 border-b border-gray-200 px-4">
                <Link :href="route('dashboard')" class="flex items-center gap-2">
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
                    :class="item.active ? 'bg-[var(--accent-50)] text-[var(--accent-700)]' : 'text-gray-700 hover:bg-gray-100'"
                >
                    <component :is="item.icon" class="h-5 w-5" />
                    {{ item.label }}
                </Link>

                <div v-if="user.role === 'admin'" class="mt-4 border-t border-gray-200 pt-4">
                    <Link
                        :href="route('admin.dashboard')"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100"
                        :class="{ 'bg-[var(--accent-50)] text-[var(--accent-700)]': route().current('admin.*') }"
                    >
                        <ShieldCheckIcon class="h-5 w-5" />
                        لوحة الإدارة
                    </Link>
                </div>
            </nav>

            <div class="absolute inset-x-0 bottom-0 border-t border-gray-200 p-4" v-if="quota">
                <StorageMeter :used="quota.used" :total="quota.total" :percent="quota.percent" />
            </div>
        </aside>

        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-20 bg-black/30 lg:hidden"
            @click="mobileOpen = false"
        />

        <!-- Main -->
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
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[var(--accent-600)] text-xs text-white">
                                {{ user.name?.charAt(0) }}
                            </span>
                            {{ user.name }}
                        </button>
                    </template>
                    <template #content>
                        <DropdownLink :href="route('profile.edit')">الملف الشخصي والإعدادات</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button" @click="logout">تسجيل الخروج</DropdownLink>
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
