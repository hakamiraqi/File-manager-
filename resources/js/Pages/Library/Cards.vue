<script setup>
import { onMounted, nextTick } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import JsBarcode from 'jsbarcode';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
defineProps({ members: Array });
const site = usePage().props.appSettings?.siteName;
const typeLabel = (type) => ({ student: t('library.type.student', 'طالب'), teacher: t('library.type.teacher', 'تدريسي'), staff: t('library.type.staff', 'موظف') })[type] ?? type;
const print = () => window.print();

onMounted(async () => {
    await nextTick();
    document.querySelectorAll('svg[data-barcode]').forEach((el) => {
        try { JsBarcode(el, el.dataset.barcode, { format: 'CODE128', height: 40, width: 1.6, fontSize: 12, margin: 0 }); } catch { /* skip */ }
    });
});
</script>

<template>
    <Head :title="t('library.print_cards', 'طباعة البطاقات')" />
    <div class="min-h-screen bg-white p-4 text-black">
        <div class="no-print mb-4 flex items-center gap-3">
            <p class="text-sm">{{ t('library.cards_count', ':n بطاقة — 8 في الصفحة', { n: members.length }) }}</p>
            <button @click="print" class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white">{{ t('library.print', 'طباعة') }}</button>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div v-for="m in members" :key="m.id" class="card flex flex-col justify-between rounded-lg border border-gray-400 p-3">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-bold">{{ site }}</span>
                    <span>{{ t('library.reader_card', 'بطاقة قارئ — المكتبة') }}</span>
                </div>
                <p class="mt-1 text-base font-bold">{{ m.name }}</p>
                <p class="text-xs">{{ typeLabel(m.type) }}<template v-if="m.stage"> · {{ m.stage }}</template></p>
                <svg :data-barcode="m.card_number" class="mx-auto mt-1 max-w-full"></svg>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    .no-print { display: none !important; }
    @page { size: A4; margin: 10mm; }
    .card { break-inside: avoid; height: 62mm; }
}
</style>
