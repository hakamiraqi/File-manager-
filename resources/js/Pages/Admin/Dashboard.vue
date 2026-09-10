<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { UsersIcon, DocumentIcon, FolderIcon, ShareIcon, CircleStackIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ stats: Object, recentActivity: Array, topUsers: Array });

function humanSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let value = bytes;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }
    return `${value.toFixed(1)} ${units[i]}`;
}

const cards = [
    { label: () => t('admin.dashboard.users', 'المستخدمون'), value: (s) => s.users, icon: UsersIcon },
    { label: () => t('admin.dashboard.files', 'الملفات'), value: (s) => s.files, icon: DocumentIcon },
    { label: () => t('admin.dashboard.folders', 'المجلدات'), value: (s) => s.folders, icon: FolderIcon },
    { label: () => t('admin.dashboard.shares', 'روابط المشاركة'), value: (s) => s.shares, icon: ShareIcon },
];
</script>

<template>
    <Head :title="t('nav.admin', 'لوحة الإدارة')" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('admin.nav.dashboard', 'نظرة عامة') }}</h2>
        </template>

        <div class="space-y-6 p-6">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="card in cards" :key="card.label()" class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <component :is="card.icon" class="h-6 w-6 text-[var(--accent-600)]" />
                    <p class="mt-2 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ card.value(stats) }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ card.label() }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <CircleStackIcon class="h-6 w-6 text-[var(--accent-600)]" />
                    <p class="mt-2 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ humanSize(stats.storage_used) }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('admin.dashboard.total_storage', 'إجمالي التخزين المستخدم') }}</p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-100">{{ t('admin.dashboard.top_users', 'أعلى المستخدمين استهلاكاً للمساحة') }}</h3>
                    <ul class="space-y-2">
                        <li v-for="u in topUsers" :key="u.id" class="flex items-center justify-between text-sm">
                            <span class="truncate text-gray-700 dark:text-gray-300">{{ u.name }}</span>
                            <span class="text-gray-400">{{ humanSize(u.storage_used_bytes) }}</span>
                        </li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="mb-3 font-semibold text-gray-800 dark:text-gray-100">{{ t('admin.dashboard.recent_activity', 'أحدث النشاطات') }}</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="log in recentActivity" :key="log.id" class="flex justify-between gap-2 text-gray-600 dark:text-gray-400">
                            <span class="truncate">{{ log.description ?? log.action }} — {{ log.user?.name ?? t('admin.dashboard.system', 'النظام') }}</span>
                            <span class="shrink-0 text-xs text-gray-400">{{ new Date(log.created_at).toLocaleString('ar') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
