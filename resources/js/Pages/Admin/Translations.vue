<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { LanguageIcon, ArrowUturnLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ locale: String, locales: Object, rows: Array });

const locale = ref(props.locale);

function changeLocale() {
    router.get(route('admin.translations.index'), { locale: locale.value }, { preserveState: true });
}

function save(row) {
    useForm({ locale: props.locale, key: row.key, value: row.value }).post(route('admin.translations.update'), {
        preserveScroll: true,
        preserveState: true,
    });
}

function resetRow(row) {
    useForm({ locale: props.locale, key: row.key }).delete(route('admin.translations.reset'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="الترجمات" />

    <AdminLayout>
        <template #header>
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-800">
                <LanguageIcon class="h-5 w-5 text-gray-500" />
                محرّر الترجمات
            </h2>
        </template>

        <div class="space-y-4 p-6">
            <select v-model="locale" @change="changeLocale" class="rounded-md border-gray-300 text-sm">
                <option v-for="(meta, code) in locales" :key="code" :value="code">{{ meta.label }}</option>
            </select>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-start font-semibold text-gray-600">المفتاح</th>
                            <th class="px-4 py-3 text-start font-semibold text-gray-600">النص</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in rows" :key="row.key">
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs text-gray-500">{{ row.key }}</td>
                            <td class="px-4 py-2">
                                <input
                                    v-model="row.value"
                                    class="block w-full rounded-md border-gray-300 text-sm"
                                    @keyup.enter="save(row)"
                                />
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-end">
                                <PrimaryButton class="me-2 !py-1 !text-xs" @click="save(row)">حفظ</PrimaryButton>
                                <button
                                    v-if="row.overridden"
                                    type="button"
                                    class="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700"
                                    @click="resetRow(row)"
                                >
                                    <ArrowUturnLeftIcon class="h-4 w-4" /> الأصلي
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
