<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Components/Pagination.vue';

defineProps({ logs: Object, filters: Object });
</script>

<template>
    <Head title="سجل النشاطات" />

    <AdminLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800">سجل النشاطات</h2>
        </template>

        <div class="p-6">
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-start">التاريخ</th>
                            <th class="px-4 py-3 text-start">المستخدم</th>
                            <th class="px-4 py-3 text-start">الحدث</th>
                            <th class="px-4 py-3 text-start">العنوان</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="log in logs.data" :key="log.id">
                            <td class="px-4 py-3 text-gray-500">{{ new Date(log.created_at).toLocaleString('ar') }}</td>
                            <td class="px-4 py-3 text-gray-700">{{ log.user?.name ?? 'النظام' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ log.action }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ log.ip_address }}</td>
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
