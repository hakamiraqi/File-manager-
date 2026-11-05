<script setup>
import { Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
        <header class="border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-4">
                <ApplicationLogo class="h-8 w-8 fill-current text-[var(--accent-600)]" />
                <span class="text-lg font-bold">{{ $page.props.appSettings.siteName }}</span>
                <Link :href="route('guest.index')" class="ms-auto hidden text-sm text-gray-500 hover:underline sm:inline">{{ t('guest.browse_title', 'تصفح الملفات العامة') }}</Link>
                <Link :href="route('login')" class="ms-auto rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm font-medium text-white sm:ms-2">{{ t('auth.login', 'تسجيل الدخول') }}</Link>
            </div>
        </header>
        <div class="mx-auto max-w-6xl">
            <slot />
        </div>
    </div>
</template>
