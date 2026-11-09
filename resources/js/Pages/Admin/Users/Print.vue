<script setup>
// A printable list of users (A4): the ticked ones or all of the list's filters.
import { Head, Link } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { PrinterIcon, ArrowRightIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
defineProps({ users: Array, title: String });
const today = new Date().toLocaleDateString('ar-IQ', { dateStyle: 'long' });
const printNow = () => window.print();
const when = (iso) => (iso ? new Date(iso).toLocaleDateString('ar-IQ') : '—');
onMounted(() => setTimeout(printNow, 600));
</script>

<template>
    <Head :title="t('admin.users.print_title', 'قائمة المستخدمين')" />
    <div class="min-h-screen bg-gray-100 p-4 text-black print:bg-white print:p-0" dir="rtl">
        <div class="mx-auto mb-3 flex max-w-[210mm] items-center gap-2 print:hidden">
            <Link :href="route('admin.users.index')" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm"><ArrowRightIcon class="h-4 w-4 ltr:rotate-180" /> {{ t('admin.cards.back', 'رجوع') }}</Link>
            <span class="flex-1 text-sm">{{ t('admin.users.print_count', ':n مستخدماً', { n: users.length }) }}</span>
            <button @click="printNow" class="inline-flex items-center gap-1 rounded-lg bg-gray-900 px-4 py-1.5 text-sm text-white"><PrinterIcon class="h-4 w-4" /> {{ t('admin.users.print', 'طباعة') }}</button>
        </div>
        <div class="mx-auto max-w-[210mm] bg-white p-[10mm] shadow print:max-w-none print:p-0 print:shadow-none">
            <div class="mb-3 flex items-end justify-between border-b-2 border-gray-800 pb-2">
                <div><p class="text-lg font-bold">{{ title }}</p><p class="text-sm">{{ t('admin.users.print_title', 'قائمة المستخدمين') }} ({{ users.length }})</p></div>
                <p class="text-xs">{{ today }}</p>
            </div>
            <table class="w-full border-collapse text-[9.5pt]">
                <thead><tr class="bg-gray-100">
                    <th class="border border-gray-400 px-1.5 py-1 text-center">#</th>
                    <th class="border border-gray-400 px-1.5 py-1 text-start">{{ t('auth.name', 'الاسم') }}</th>
                    <th class="border border-gray-400 px-1.5 py-1 text-start">{{ t('admin.cards.number', 'الرقم الجامعي') }}</th>
                    <th class="border border-gray-400 px-1.5 py-1 text-start">{{ t('admin.cards.email', 'البريد') }}</th>
                    <th class="border border-gray-400 px-1.5 py-1 text-start">{{ t('admin.users.kind', 'الصفة') }}</th>
                    <th class="border border-gray-400 px-1.5 py-1 text-start">{{ t('admin.users.status', 'الحالة') }}</th>
                    <th class="border border-gray-400 px-1.5 py-1 text-start">{{ t('admin.users.last_login', 'آخر دخول') }}</th>
                </tr></thead>
                <tbody>
                    <tr v-for="(u, i) in users" :key="i" class="break-inside-avoid">
                        <td class="border border-gray-400 px-1.5 py-1 text-center">{{ i + 1 }}</td>
                        <td class="border border-gray-400 px-1.5 py-1 font-medium">{{ u.name }}</td>
                        <td class="border border-gray-400 px-1.5 py-1 font-mono" dir="ltr">{{ u.number || '' }}</td>
                        <td class="border border-gray-400 px-1.5 py-1" dir="ltr">{{ u.email || '' }}</td>
                        <td class="border border-gray-400 px-1.5 py-1">{{ u.kind }}</td>
                        <td class="border border-gray-400 px-1.5 py-1">{{ u.active ? t('admin.users.active_short', 'نشط') : t('admin.users.inactive_short', 'غير نشط') }}</td>
                        <td class="border border-gray-400 px-1.5 py-1">{{ when(u.last_login) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style>
@media print { @page { size: A4; margin: 10mm; } }
</style>
