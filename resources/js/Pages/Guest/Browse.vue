<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import FileThumbnail from '@/Components/FileThumbnail.vue';
import { FolderIcon, ArrowDownTrayIcon, HomeIcon } from '@heroicons/vue/24/outline';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
defineProps({ currentFolder: Object, breadcrumbs: Array, folders: Array, files: Array, canDownload: Boolean });
</script>

<template>
    <Head :title="t('guest.browse_title', 'تصفح الملفات العامة')" />

    <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
        <header class="border-b border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-4">
                <ApplicationLogo class="h-8 w-8 fill-current text-[var(--accent-600)]" />
                <span class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ $page.props.appSettings.siteName }}</span>
                <span class="ms-auto hidden text-sm text-gray-400 sm:inline dark:text-gray-500">{{ t('guest.public_browse', 'تصفح عام (بدون تسجيل دخول)') }}</span>
                <Link
                    :href="$page.props.auth?.user ? route('dashboard') : route('login')"
                    class="ms-auto rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)] sm:ms-2"
                >
                    {{ $page.props.auth?.user ? t('nav.dashboard', 'ملفاتي') : t('auth.login', 'تسجيل الدخول') }}
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-5xl p-4">
            <nav class="mb-4 flex flex-wrap items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
                <Link :href="route('guest.index')" class="flex items-center gap-1 hover:text-gray-800 dark:hover:text-gray-200">
                    <HomeIcon class="h-4 w-4" /> {{ t('guest.home', 'الرئيسية') }}
                </Link>
                <template v-for="crumb in breadcrumbs" :key="crumb.id">
                    <span>/</span>
                    <Link :href="route('guest.show', crumb.id)" class="hover:text-gray-800 dark:hover:text-gray-200">{{ crumb.name }}</Link>
                </template>
            </nav>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="folder in folders" :key="'f' + folder.id">
                        <Link :href="route('guest.show', folder.id)" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700">
                            <FolderIcon class="h-6 w-6 text-amber-400" />
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ folder.name }}</span>
                        </Link>
                    </li>
                    <li v-for="file in files" :key="'file' + file.id" class="flex items-center gap-3 px-4 py-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center">
                            <FileThumbnail :file="file" :src="route('guest.thumbnail', file.id)" icon-class="h-6 w-6" />
                        </div>
                        <span class="flex-1 text-sm text-gray-700 dark:text-gray-300">{{ file.name }}</span>
                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ file.human_size }}</span>
                        <a
                            v-if="canDownload"
                            :href="route('guest.download', file.id)"
                            class="flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        >
                            <ArrowDownTrayIcon class="h-4 w-4" /> {{ t('action.download', 'تحميل') }}
                        </a>
                    </li>
                    <li v-if="!folders.length && !files.length" class="px-4 py-10 text-center text-sm text-gray-400 dark:text-gray-500">
                        {{ t('guest.nothing_here', 'لا يوجد شيء هنا بعد.') }}
                    </li>
                </ul>
            </div>
        </main>
    </div>
</template>
