<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0 dark:bg-gray-900"
    >
        <div class="flex flex-col items-center gap-2">
            <Link href="/">
                <ApplicationLogo class="h-16 w-16 fill-current text-[var(--accent-600)]" />
            </Link>
            <span class="text-xl font-bold text-gray-700">{{ $page.props.appSettings.siteName }}</span>
        </div>

        <div
            class="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg dark:bg-gray-800"
        >
            <slot />
        </div>
    </div>
</template>
