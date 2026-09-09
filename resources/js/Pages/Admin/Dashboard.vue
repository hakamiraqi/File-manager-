<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { UsersIcon, DocumentIcon, FolderIcon, ShareIcon, CircleStackIcon } from '@heroicons/vue/24/outline';

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
    { label: 'المستخدمون', value: (s) => s.users, icon: UsersIcon },
    { label: 'الملفات', value: (s) => s.files, icon: DocumentIcon },
    { label: 'المجلدات', value: (s) => s.folders, icon: FolderIcon },
    { label: 'روابط المشاركة', value: (s) => s.shares, icon: ShareIcon },
];
</script>

<template>
    <Head title="لوحة الإدارة" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800">نظرة عامة</h2>
        </template>

        <div class="space-y-6 p-6">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="card in cards" :key="card.label" class="rounded-xl border border-gray-200 bg-white p-5">
                    <component :is="card.icon" class="h-6 w-6 text-[var(--accent-600)]" />
                    <p class="mt-2 text-2xl font-bold text-gray-800">{{ card.value(stats) }}</p>
                    <p class="text-sm text-gray-500">{{ card.label }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <CircleStackIcon class="h-6 w-6 text-[var(--accent-600)]" />
                    <p class="mt-2 text-2xl font-bold text-gray-800">{{ humanSize(stats.storage_used) }}</p>
                    <p class="text-sm text-gray-500">إجمالي التخزين المستخدم</p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h3 class="mb-3 font-semibold text-gray-800">أعلى المستخدمين استهلاكاً للمساحة</h3>
                    <ul class="space-y-2">
                        <li v-for="u in topUsers" :key="u.id" class="flex items-center justify-between text-sm">
                            <span class="truncate text-gray-700">{{ u.name }}</span>
                            <span class="text-gray-400">{{ humanSize(u.storage_used_bytes) }}</span>
                        </li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h3 class="mb-3 font-semibold text-gray-800">أحدث النشاطات</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="log in recentActivity" :key="log.id" class="flex justify-between gap-2 text-gray-600">
                            <span class="truncate">{{ log.description ?? log.action }} — {{ log.user?.name ?? 'النظام' }}</span>
                            <span class="shrink-0 text-xs text-gray-400">{{ new Date(log.created_at).toLocaleString('ar') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
