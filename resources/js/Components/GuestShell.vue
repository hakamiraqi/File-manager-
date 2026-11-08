<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    HomeIcon, FolderIcon, BookOpenIcon, AcademicCapIcon, ChatBubbleLeftRightIcon, MapIcon,
    Bars3Icon, XMarkIcon, ArrowLeftEndOnRectangleIcon, UserCircleIcon,
} from '@heroicons/vue/24/outline';

// The visitors' site frame: top navigation with the switched-on sections, login, and a footer.
const { t } = useTrans();
const page = usePage();
const site = computed(() => page.props.appSettings?.publicSite ?? {});
const user = computed(() => page.props.auth?.user);
const open = ref(false);
watch(() => page.url, () => (open.value = false));

const nav = computed(() => [
    site.value.enabled && { label: t('guest.home', 'الرئيسية'), href: route('guest.index'), icon: HomeIcon, active: route().current('guest.index') },
    site.value.library && { label: t('nav.library', 'المكتبة'), href: route('library.index'), icon: BookOpenIcon, active: route().current('library.*') && !route().current('library.map') },
    site.value.library && { label: t('library.tab.map', 'خريطة المكتبة'), href: route('library.map'), icon: MapIcon, active: route().current('library.map') },
    site.value.theses && { label: t('nav.theses', 'الرسائل والأطاريح'), href: route('theses.index'), icon: AcademicCapIcon, active: route().current('theses.*') },
    site.value.files && { label: t('guest.files', 'الملفات العامة'), href: route('guest.files'), icon: FolderIcon, active: route().current('guest.files') || route().current('guest.show') },
    site.value.contact && { label: t('nav.contact', 'تواصل معنا'), href: route('contact.create'), icon: ChatBubbleLeftRightIcon, active: route().current('contact.*') },
].filter(Boolean));
</script>

<template>
    <div class="flex min-h-screen flex-col bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
        <header class="sticky top-0 z-30 border-b border-gray-200/80 bg-white/90 backdrop-blur dark:border-gray-700/80 dark:bg-gray-800/90">
            <div class="mx-auto flex h-16 max-w-6xl items-center gap-2 px-4">
                <Link :href="site.enabled ? route('guest.index') : route('login')" class="flex min-w-0 items-center gap-2">
                    <img v-if="$page.props.appSettings.logoUrl" :src="$page.props.appSettings.logoUrl" class="h-9 w-9 rounded-lg object-contain" alt="" />
                    <ApplicationLogo v-else class="h-8 w-8 shrink-0 fill-current text-[var(--accent-600)]" />
                    <span class="truncate text-base font-bold sm:text-lg">{{ $page.props.appSettings.siteName }}</span>
                </Link>

                <nav class="ms-6 hidden items-center gap-1 lg:flex">
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition"
                        :class="item.active ? 'bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700 dark:text-white' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                    >
                        <component :is="item.icon" class="h-4 w-4" /> {{ item.label }}
                    </Link>
                </nav>

                <div class="ms-auto flex items-center gap-1">
                    <ThemeToggle />
                    <Link
                        :href="user ? route('dashboard') : route('login')"
                        class="flex items-center gap-1.5 rounded-full bg-[var(--accent-600)] px-3 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)]"
                    >
                        <UserCircleIcon v-if="user" class="h-4 w-4" />
                        <ArrowLeftEndOnRectangleIcon v-else class="h-4 w-4 ltr:rotate-180" />
                        <span class="hidden sm:inline">{{ user ? t('nav.dashboard', 'ملفاتي') : t('auth.login', 'تسجيل الدخول') }}</span>
                    </Link>
                    <button v-if="nav.length" type="button" class="rounded-full p-2 text-gray-600 hover:bg-gray-100 lg:hidden dark:text-gray-300 dark:hover:bg-gray-700" @click="open = !open" :aria-label="t('nav.menu', 'القائمة')">
                        <XMarkIcon v-if="open" class="h-6 w-6" />
                        <Bars3Icon v-else class="h-6 w-6" />
                    </button>
                </div>
            </div>

            <!-- Phone menu -->
            <Transition enter-from-class="opacity-0 -translate-y-2" enter-active-class="transition duration-150" leave-to-class="opacity-0 -translate-y-2" leave-active-class="transition duration-100">
                <nav v-if="open" class="border-t border-gray-100 px-4 pb-4 pt-2 lg:hidden dark:border-gray-700">
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium"
                        :class="item.active ? 'bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700 dark:text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700'"
                    >
                        <component :is="item.icon" class="h-5 w-5" /> {{ item.label }}
                    </Link>
                </nav>
            </Transition>
        </header>

        <div v-if="$slots.header" class="border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="mx-auto max-w-6xl px-4 py-3 sm:px-6">
                <slot name="header" />
            </div>
        </div>

        <main class="mx-auto w-full max-w-6xl flex-1">
            <slot />
        </main>

        <footer class="border-t border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="mx-auto flex max-w-6xl flex-col items-center gap-3 px-4 py-6 text-sm text-gray-500 sm:flex-row dark:text-gray-400">
                <span>© {{ new Date().getFullYear() }} {{ $page.props.appSettings.siteName }}</span>
                <nav class="flex flex-wrap justify-center gap-x-4 gap-y-1 sm:ms-auto">
                    <Link v-for="item in nav" :key="'f' + item.href" :href="item.href" class="hover:text-gray-800 dark:hover:text-gray-200">{{ item.label }}</Link>
                </nav>
            </div>
        </footer>

        <FlashMessages />
    </div>
</template>
