<script setup>
// QR stickers for «خريطة المكتبة» (2026-11-06, by request): one per bookcase. Scanning it with
// the phone camera (or the scan button on the map) opens the map at «أنت هنا».
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { useTrans } from '@/Composables/useTrans';
import { sectionColor } from '@/Components/Library/Map/colors.js';
import { PrinterIcon, ArrowRightIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ shelves: Array, mapUrl: String });
const size = ref('medium'); // small: 4 per row, medium: 3, large: 2
const images = ref({});
const cols = computed(() => ({ small: 4, medium: 3, large: 2 })[size.value]);
const print = () => window.print();

onMounted(async () => {
    const out = {};
    for (const s of props.shelves) out[s.id] = await QRCode.toDataURL(s.url, { margin: 1, width: 360, errorCorrectionLevel: 'M' });
    images.value = out;
});
</script>

<template>
    <Head :title="t('library.map.qr_labels', 'ملصقات QR للرفوف')" />
    <div class="qr-page min-h-screen bg-white p-4 text-black" dir="rtl">
        <div class="no-print mb-4 flex flex-wrap items-center gap-3">
            <Link :href="route('library.manage.map')" class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-3 py-2 text-sm"><ArrowRightIcon class="h-4 w-4 ltr:rotate-180" /> {{ t('library.map.edit', 'ترتيب الرفوف') }}</Link>
            <p class="text-sm">{{ t('library.map.qr_count', ':n ملصق، يُلصق كل واحد على رفّه بمستوى النظر.', { n: shelves.length }) }}</p>
            <select v-model="size" class="rounded-md border-gray-300 py-1.5 text-sm">
                <option value="small">{{ t('library.map.qr_small', 'صغير (4 في السطر)') }}</option>
                <option value="medium">{{ t('library.map.qr_medium', 'متوسط (3 في السطر)') }}</option>
                <option value="large">{{ t('library.map.qr_large', 'كبير (2 في السطر)') }}</option>
            </select>
            <button @click="print" class="ms-auto inline-flex items-center gap-1.5 rounded-md bg-gray-900 px-4 py-2 text-sm text-white"><PrinterIcon class="h-4 w-4" /> {{ t('library.print', 'طباعة') }}</button>
        </div>
        <p v-if="!shelves.length" class="text-gray-500">{{ t('library.map.qr_none', 'لا توجد رفوف على الخريطة بعد.') }}</p>
        <div class="grid gap-3" :style="{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }">
            <div v-for="s in shelves" :key="s.id" class="qr-label flex flex-col items-center rounded-xl border-2 p-3 text-center" :style="{ borderColor: s.color || sectionColor(s.section_id) }">
                <div class="mb-1 w-full rounded-lg py-1 text-white" :style="{ background: s.color || sectionColor(s.section_id) }">
                    <p class="text-[10px] font-medium opacity-90">{{ t('library.shelf', 'الرف') }}</p>
                    <p class="text-2xl font-extrabold leading-none" dir="ltr">{{ s.code }}</p>
                </div>
                <img v-if="images[s.id]" :src="images[s.id]" alt="" class="aspect-square w-full max-w-[46mm]" />
                <p v-if="s.section" class="mt-1 line-clamp-1 text-[11px] font-semibold" dir="auto">{{ s.section }}</p>
                <p class="text-[10px] text-gray-600">{{ t('library.map.qr_hint', 'امسح لتعرف مكانك وطريقك إلى أي كتاب') }}</p>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    .no-print { display: none !important; }
    @page { size: A4; margin: 8mm; }
    .qr-page { padding: 0 !important; }
    .qr-label { break-inside: avoid; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
