<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
defineProps({ logs: Object, filters: Object });
</script>

<template>
    <Head :title="t('admin.nav.activity', 'سجل النشاطات')" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('admin.nav.activity', 'سجل النشاطات') }}</h2>
        </template>

        <div class="p-6">
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3 text-start">{{ t('activity.date', 'التاريخ') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('activity.user', 'المستخدم') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('activity.event', 'الحدث') }}</th>
                            <th class="px-4 py-3 text-start">{{ t('activity.ip', 'العنوان') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="log in logs.data" :key="log.id">
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ new Date(log.created_at).toLocaleString('ar') }}</td>
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ log.user?.name ?? t('admin.dashboard.system', 'النظام') }}</td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ log.action }}</td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ log.ip_address }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-4">
                <Pagination :links="logs.links" />
            </div>
        </div>
    </AdminLayout>
</template>
