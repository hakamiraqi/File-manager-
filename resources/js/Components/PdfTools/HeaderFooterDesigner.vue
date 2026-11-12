<script setup>
// Header and footer text (Arabic shaped by the browser), drawn as a transparent picture the size
// of the page and laid over every page on the server, like the watermark.
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { openPdf, pageCanvas, pageSize } from './pdfjs';

const props = defineProps({ src: { type: String, required: true } });
const { t } = useTrans();

const o = reactive({ header: '', footer: '', align: 'center', size: 10, color: '#374151', line: true, margin: 4 });
const ratio = ref(1.414);
const preview = ref('');
const pageHolder = ref(null);
let task = null;
const W = 1400;

function render() {
    const H = Math.round(W * ratio.value);
    const c = document.createElement('canvas');
    c.width = W;
    c.height = H;
    const g = c.getContext('2d');
    const fs = (W * o.size) / 600; // size in points on an A4-wide page
    const pad = (W * o.margin) / 100;
    g.fillStyle = o.color;
    g.strokeStyle = o.color;
    g.lineWidth = Math.max(1, W / 900);
    g.font = `500 ${fs}px "Noto Naskh Arabic", "Segoe UI", Tahoma, Arial, sans-serif`;
    const draw = (text, y, lineY) => {
        if (!text.trim()) return;
        const rtl = /[؀-ۿ]/.test(text);
        g.direction = rtl ? 'rtl' : 'ltr';
        const align = o.align === 'center' ? 'center' : o.align === 'start' ? (rtl ? 'right' : 'left') : rtl ? 'left' : 'right';
        g.textAlign = align;
        g.textBaseline = 'middle';
        const x = align === 'center' ? W / 2 : align === 'left' ? pad : W - pad;
        g.fillText(text, x, y);
        if (o.line) {
            g.beginPath();
            g.moveTo(pad, lineY);
            g.lineTo(W - pad, lineY);
            g.stroke();
        }
    };
    draw(o.header, pad * 0.9 + fs / 2, pad * 0.9 + fs * 1.15);
    draw(o.footer, H - pad * 0.9 - fs / 2, H - pad * 0.9 - fs * 1.15);
    return c;
}
let timer = null;
watch([o, ratio], () => {
    clearTimeout(timer);
    timer = setTimeout(() => (preview.value = render().toDataURL('image/png')), 120);
}, { deep: true, immediate: true });

onMounted(async () => {
    try {
        task = openPdf(props.src);
        const doc = await task.promise;
        const s = await pageSize(doc, 1);
        ratio.value = s.h / s.w;
        pageHolder.value?.replaceChildren(await pageCanvas(doc, 1, 360));
    } catch {
        /* preview without the page */
    }
});
onBeforeUnmount(() => task?.destroy());

defineExpose({
    ready: () => !!(o.header.trim() || o.footer.trim()),
    toBlob: () => new Promise((r) => render().toBlob(r, 'image/png')),
});
</script>

<template>
    <div class="grid gap-5 md:grid-cols-[1fr_18rem]">
        <div class="space-y-3 text-sm">
            <label class="block">{{ t('pdft.header', 'الترويسة (أعلى الصفحة)') }}
                <input v-model="o.header" dir="auto" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900" :placeholder="t('pdft.header_ph', 'مثال: كلية الطب — قسم التشريح')" />
            </label>
            <label class="block">{{ t('pdft.footer', 'التذييل (أسفل الصفحة)') }}
                <input v-model="o.footer" dir="auto" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900" :placeholder="t('pdft.footer_ph', 'مثال: العام الدراسي 2026–2027')" />
            </label>
            <div class="flex flex-wrap items-center gap-2">
                <button v-for="a in [['start', t('pdft.al_start', 'بداية السطر')], ['center', t('pdft.pos_center', 'الوسط')], ['end', t('pdft.al_end', 'نهاية السطر')]]" :key="a[0]" type="button" class="pdft-chip" :class="{ '!border-[var(--accent-600)] text-[var(--accent-600)]': o.align === a[0] }" @click="o.align = a[0]">{{ a[1] }}</button>
                <input v-model="o.color" type="color" class="h-7 w-9 cursor-pointer rounded border-0 bg-transparent p-0" />
                <label class="inline-flex items-center gap-1"><input v-model="o.line" type="checkbox" class="rounded" />{{ t('pdft.with_line', 'خط فاصل') }}</label>
            </div>
            <label class="block">{{ t('pdft.font_size', 'حجم الخط') }}<input v-model.number="o.size" type="range" min="6" max="20" class="w-full" /></label>
            <label class="block">{{ t('pdft.margin', 'البعد عن الحافة') }}<input v-model.number="o.margin" type="range" min="2" max="10" step="0.5" class="w-full" /></label>
        </div>
        <div>
            <p class="mb-1 text-xs text-gray-500">{{ t('pdft.preview', 'معاينة الصفحة الأولى') }}</p>
            <div class="relative overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5" :style="{ aspectRatio: `1 / ${ratio}` }">
                <div ref="pageHolder" class="absolute inset-0" />
                <img v-if="preview" :src="preview" class="absolute inset-0 h-full w-full" alt="" />
            </div>
        </div>
    </div>
</template>
