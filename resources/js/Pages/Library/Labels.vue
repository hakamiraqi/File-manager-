<script setup>
import { onMounted, nextTick } from 'vue';
import { Head } from '@inertiajs/vue3';
import JsBarcode from 'jsbarcode';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
defineProps({ labels: Array });
const print = () => window.print();

onMounted(async () => {
    await nextTick();
    document.querySelectorAll('svg[data-barcode]').forEach((el) => {
        try {
            JsBarcode(el, el.dataset.barcode, { format: 'CODE128', height: 42, width: 1.6, fontSize: 13, margin: 0 });
        } catch {
            /* a code CODE128 cannot hold stays blank */
        }
    });
});
</script>

<template>
    <Head :title="t('library.labels', 'ملصقات الباركود')" />
    <div class="labels-page min-h-screen bg-white p-4 text-black">
        <div class="no-print mb-4 flex items-center gap-3">
            <p class="text-sm">{{ t('library.labels_count', ':n ملصق — A4، 3 أعمدة', { n: labels.length }) }}</p>
            <button @click="print" class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white">{{ t('library.print', 'طباعة') }}</button>
        </div>
        <p v-if="!labels.length" class="text-gray-500">{{ t('library.no_labels', 'لا توجد نسخ للطباعة.') }}</p>
        <div class="grid grid-cols-3 gap-2">
            <div v-for="l in labels" :key="l.barcode" class="label flex flex-col items-center justify-center rounded border border-gray-300 px-2 py-1.5 text-center">
                <p class="w-full truncate text-[11px] font-semibold" dir="auto">{{ l.title }}</p>
                <svg :data-barcode="l.barcode" class="mx-auto max-w-full"></svg>
                <p v-if="l.shelf" class="text-[10px]">{{ t('library.shelf', 'الرف') }}: {{ l.shelf }}</p>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    .no-print { display: none !important; }
    @page { size: A4; margin: 8mm; }
    .labels-page { padding: 0 !important; }
    .label { break-inside: avoid; height: 33mm; }
}
</style>
