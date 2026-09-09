<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import FileIcon from '@/Components/FileIcon.vue';
import { FolderIcon, ArrowDownTrayIcon, HomeIcon } from '@heroicons/vue/24/outline';

defineProps({ currentFolder: Object, breadcrumbs: Array, folders: Array, files: Array, canDownload: Boolean });
</script>

<template>
    <Head title="تصفح الملفات العامة" />

    <div class="min-h-screen bg-gray-50">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-4">
                <ApplicationLogo class="h-8 w-8 fill-current text-[var(--accent-600)]" />
                <span class="text-lg font-bold text-gray-800">{{ $page.props.appSettings.siteName }}</span>
                <span class="ms-auto text-sm text-gray-400">تصفح عام (بدون تسجيل دخول)</span>
            </div>
        </header>

        <main class="mx-auto max-w-5xl p-4">
            <nav class="mb-4 flex flex-wrap items-center gap-1 text-sm text-gray-500">
                <Link :href="route('guest.index')" class="flex items-center gap-1 hover:text-gray-800">
                    <HomeIcon class="h-4 w-4" /> الرئيسية
                </Link>
                <template v-for="crumb in breadcrumbs" :key="crumb.id">
                    <span>/</span>
                    <Link :href="route('guest.show', crumb.id)" class="hover:text-gray-800">{{ crumb.name }}</Link>
                </template>
            </nav>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <ul class="divide-y divide-gray-100">
                    <li v-for="folder in folders" :key="'f' + folder.id">
                        <Link :href="route('guest.show', folder.id)" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
                            <FolderIcon class="h-6 w-6 text-amber-400" />
                            <span class="text-sm font-medium text-gray-700">{{ folder.name }}</span>
                        </Link>
                    </li>
                    <li v-for="file in files" :key="'file' + file.id" class="flex items-center gap-3 px-4 py-3">
                        <FileIcon :extension="file.extension" :mime-type="file.mime_type" class="h-6 w-6" />
                        <span class="flex-1 text-sm text-gray-700">{{ file.name }}</span>
                        <span class="text-xs text-gray-400">{{ file.human_size }}</span>
                        <a
                            v-if="canDownload"
                            :href="route('guest.download', file.id)"
                            class="flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200"
                        >
                            <ArrowDownTrayIcon class="h-4 w-4" /> تحميل
                        </a>
                    </li>
                    <li v-if="!folders.length && !files.length" class="px-4 py-10 text-center text-sm text-gray-400">
                        لا يوجد شيء هنا بعد.
                    </li>
                </ul>
            </div>
        </main>
    </div>
</template>
