<script setup>
// Margins to cut (mm), with the first page showing what stays.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { openPdf, pageCanvas, pageSize } from './pdfjs';

const props = defineProps({ src: { type: String, required: true }, modelValue: { type: Object, required: true } });
const { t } = useTrans();
const o = computed(() => props.modelValue);
const size = ref({ w: 595, h: 842 }); // points
const pageHolder = ref(null);
let task = null;

const mm = (v) => ((Number(v) || 0) * 72) / 25.4;
const keep = computed(() => ({
    left: (mm(o.value.left) / size.value.w) * 100,
    right: (mm(o.value.right) / size.value.w) * 100,
    top: (mm(o.value.top) / size.value.h) * 100,
    bottom: (mm(o.value.bottom) / size.value.h) * 100,
}));
function all(v) {
    o.value.top = o.value.right = o.value.bottom = o.value.left = v;
}

onMounted(async () => {
    try {
        task = openPdf(props.src);
        const doc = await task.promise;
        size.value = await pageSize(doc, 1);
        pageHolder.value?.replaceChildren(await pageCanvas(doc, 1, 360));
    } catch {
        /* preview without the page */
    }
});
onBeforeUnmount(() => task?.destroy());
</script>

<template>
    <div class="grid gap-5 md:grid-cols-[1fr_18rem]">
        <div class="space-y-3 text-sm">
            <label class="flex items-start gap-2 rounded-xl border-2 p-3" :class="o.auto ? 'border-[var(--accent-600)] bg-[var(--accent-600)]/5' : 'border-gray-200 dark:border-gray-700'">
                <input v-model="o.auto" type="checkbox" class="mt-0.5 rounded" />
                <span><span class="block font-semibold">{{ t('pdft.crop_auto', 'قص الهوامش البيضاء تلقائياً') }}</span><span class="text-xs text-gray-500">{{ t('pdft.crop_auto_d', 'يجد حدود المحتوى في كل صفحة؛ الأرقام أدناه تصبح مسافة تُترك حوله.') }}</span></span>
            </label>
            <div class="grid max-w-xs grid-cols-2 gap-2">
                <label v-for="side in [['top', t('pdft.top', 'أعلى')], ['bottom', t('pdft.bottom', 'أسفل')], ['right', t('pdft.right_side', 'يمين')], ['left', t('pdft.left_side', 'يسار')]]" :key="side[0]" class="block text-xs">
                    {{ side[1] }} (mm)
                    <input v-model.number="o[side[0]]" type="number" min="0" max="300" step="1" class="mt-0.5 w-full rounded-lg border-gray-300 py-1 dark:border-gray-600 dark:bg-gray-900" />
                </label>
            </div>
            <div class="flex flex-wrap gap-1">
                <button v-for="v in [0, 5, 10, 15, 20]" :key="v" type="button" class="pdft-chip text-xs" @click="all(v)">{{ v }} mm</button>
            </div>
            <select v-model="o.which" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900">
                <option value="all">{{ t('pdft.all_pages', 'كل الصفحات') }}</option>
                <option value="odd">{{ t('pdft.odd', 'الفردية') }}</option>
                <option value="even">{{ t('pdft.even', 'الزوجية') }}</option>
                <option value="first">{{ t('pdft.first_page', 'الصفحة الأولى فقط') }}</option>
            </select>
            <p class="text-xs text-gray-500">{{ t('pdft.crop_note', 'القص يخفي ما خارج الإطار عند العرض والطباعة؛ يمكن استرجاعه بأداة أخرى لأنه لا يُحذف من الملف.') }}</p>
        </div>
        <div>
            <p class="mb-1 text-xs text-gray-500">{{ t('pdft.preview', 'معاينة الصفحة الأولى') }}</p>
            <div class="relative overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5" :style="{ aspectRatio: `${size.w} / ${size.h}` }">
                <div ref="pageHolder" class="absolute inset-0" />
                <template v-if="!o.auto">
                    <div class="absolute inset-x-0 top-0 bg-black/40" :style="{ height: keep.top + '%' }" />
                    <div class="absolute inset-x-0 bottom-0 bg-black/40" :style="{ height: keep.bottom + '%' }" />
                    <div class="absolute left-0 bg-black/40" :style="{ top: keep.top + '%', bottom: keep.bottom + '%', width: keep.left + '%' }" />
                    <div class="absolute right-0 bg-black/40" :style="{ top: keep.top + '%', bottom: keep.bottom + '%', width: keep.right + '%' }" />
                </template>
            </div>
        </div>
    </div>
</template>
