<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GuestShell from '@/Components/GuestShell.vue';
import FileThumbnail from '@/Components/FileThumbnail.vue';
import { FolderIcon, ArrowDownTrayIcon, FolderOpenIcon, ChevronLeftIcon } from '@heroicons/vue/24/outline';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
defineProps({ currentFolder: Object, breadcrumbs: Array, folders: Array, files: Array, canDownload: Boolean, filesHome: Boolean });
</script>

<template>
    <Head :title="currentFolder?.thesis_title || currentFolder?.name || t('guest.files', 'الملفات العامة')" />

    <GuestShell>
        <template #header>
            <nav class="flex flex-wrap items-center gap-1 text-sm">
                <Link v-if="filesHome" :href="route('guest.files')" class="flex items-center gap-1.5 font-medium" :class="breadcrumbs.length ? 'text-[var(--accent-600)] hover:underline' : 'text-gray-800 dark:text-gray-100'">
                    <FolderOpenIcon class="h-4 w-4" /> {{ t('guest.files', 'الملفات العامة') }}
                </Link>
                <template v-for="(crumb, i) in breadcrumbs" :key="crumb.id">
                    <ChevronLeftIcon v-if="filesHome || i > 0" class="h-3.5 w-3.5 text-gray-400 ltr:rotate-180" />
                    <Link :href="route('guest.show', crumb.id)" :class="i < breadcrumbs.length - 1 ? 'text-[var(--accent-600)] hover:underline' : 'font-semibold text-gray-800 dark:text-gray-100'">{{ crumb.name }}</Link>
                </template>
            </nav>
        </template>

        <div class="p-4 sm:p-6">
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="folder in folders" :key="'f' + folder.id">
                        <Link :href="route('guest.show', folder.id)" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <FolderIcon class="h-6 w-6 shrink-0 text-amber-400" />
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-700 dark:text-gray-200">{{ folder.name }}</span>
                            <ChevronLeftIcon class="h-4 w-4 text-gray-300 ltr:rotate-180" />
                        </Link>
                    </li>
                    <li v-for="file in files" :key="'file' + file.id" class="flex items-center gap-3 px-4 py-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center">
                            <FileThumbnail :file="file" :src="route('guest.thumbnail', file.id)" icon-class="h-6 w-6" />
                        </div>
                        <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-300" dir="auto">{{ file.name }}</span>
                        <span class="hidden text-xs text-gray-400 sm:inline dark:text-gray-500">{{ file.human_size }}</span>
                        <a
                            v-if="canDownload"
                            :href="route('guest.download', file.id)"
                            class="flex items-center gap-1 rounded-lg bg-gray-100 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                        >
                            <ArrowDownTrayIcon class="h-4 w-4" /> <span class="hidden sm:inline">{{ t('action.download', 'تحميل') }}</span>
                        </a>
                    </li>
                    <li v-if="!folders.length && !files.length" class="px-4 py-14 text-center text-sm text-gray-400 dark:text-gray-500">
                        <FolderOpenIcon class="mx-auto mb-2 h-10 w-10 text-gray-300" />
                        {{ t('guest.nothing_here', 'لا يوجد شيء هنا بعد.') }}
                    </li>
                </ul>
            </div>
        </div>
    </GuestShell>
</template>
