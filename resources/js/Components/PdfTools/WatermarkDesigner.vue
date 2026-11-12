<script setup>
// The watermark is drawn here, in the browser, so Arabic text is joined and shaped exactly as
// on screen; the server lays the picture over (or under) every page.
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { openPdf, pageCanvas, pageSize } from './pdfjs';

const props = defineProps({ src: { type: String, required: true } });
const { t } = useTrans();

const o = reactive({
    kind: 'text',
    text: 'سري',
    size: 14, // % of the page width per character height
    color: '#dc2626',
    opacity: 25,
    angle: -35,
    position: 'center', // center | tile | top | bottom
    bold: true,
    scale: 40, // image: % of page width
});
const logo = ref(null); // HTMLImageElement
const ratio = ref(1.414);
const preview = ref('');
const pageHolder = ref(null);
let task = null;

const W = 1400;
const H = computed(() => Math.round(W * ratio.value));

function render() {
    const c = document.createElement('canvas');
    c.width = W;
    c.height = H.value;
    const g = c.getContext('2d');
    g.globalAlpha = o.opacity / 100;
    const spots = [];
    if (o.position === 'tile') {
        for (let y = H.value * 0.12; y < H.value; y += H.value * 0.24) for (let x = W * 0.25; x < W; x += W * 0.5) spots.push([x, y]);
    } else {
        spots.push([W / 2, o.position === 'top' ? H.value * 0.1 : o.position === 'bottom' ? H.value * 0.9 : H.value / 2]);
    }
    for (const [x, y] of spots) {
        g.save();
        g.translate(x, y);
        g.rotate((o.angle * Math.PI) / 180);
        if (o.kind === 'text') {
            const fs = (W * o.size) / 100 / (o.position === 'tile' ? 2 : 1);
            g.font = `${o.bold ? '700' : '400'} ${fs}px "Noto Naskh Arabic", "Segoe UI", Tahoma, Arial, sans-serif`;
            g.fillStyle = o.color;
            g.textAlign = 'center';
            g.textBaseline = 'middle';
            g.direction = /[؀-ۿ]/.test(o.text) ? 'rtl' : 'ltr';
            const lines = o.text.split('\n');
            lines.forEach((line, i) => g.fillText(line, 0, (i - (lines.length - 1) / 2) * fs * 1.2));
        } else if (logo.value) {
            const w = (W * o.scale) / 100 / (o.position === 'tile' ? 2 : 1);
            const h = (w * logo.value.naturalHeight) / logo.value.naturalWidth;
            g.drawImage(logo.value, -w / 2, -h / 2, w, h);
        }
        g.restore();
    }
    return c;
}
let timer = null;
watch([o, logo, ratio], () => {
    clearTimeout(timer);
    timer = setTimeout(() => (preview.value = render().toDataURL('image/png')), 120);
}, { deep: true, immediate: true });

function pickLogo(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    const img = new Image();
    img.onload = () => (logo.value = img);
    img.src = URL.createObjectURL(file);
}

onMounted(async () => {
    try {
        task = openPdf(props.src);
        const doc = await task.promise;
        const s = await pageSize(doc, 1);
        ratio.value = s.h / s.w;
        pageHolder.value?.replaceChildren(await pageCanvas(doc, 1, 360));
    } catch {
        /* the preview without the page still works */
    }
});
onBeforeUnmount(() => task?.destroy());

defineExpose({
    ready: () => o.kind === 'text' ? o.text.trim() !== '' : !!logo.value,
    toBlob: () => new Promise((r) => render().toBlob(r, 'image/png')),
});
const COLORS = ['#dc2626', '#2563eb', '#16a34a', '#111827', '#9ca3af', '#f59e0b'];
</script>

<template>
    <div class="grid gap-5 md:grid-cols-[1fr_18rem]">
        <div class="space-y-4 text-sm">
            <div class="inline-flex rounded-xl bg-gray-100 p-1 dark:bg-gray-900">
                <button type="button" class="rounded-lg px-4 py-1.5" :class="o.kind === 'text' ? 'bg-white font-semibold shadow dark:bg-gray-700' : ''" @click="o.kind = 'text'">{{ t('pdft.wm_text', 'نص') }}</button>
                <button type="button" class="rounded-lg px-4 py-1.5" :class="o.kind === 'image' ? 'bg-white font-semibold shadow dark:bg-gray-700' : ''" @click="o.kind = 'image'">{{ t('pdft.wm_image', 'صورة / شعار') }}</button>
            </div>
            <template v-if="o.kind === 'text'">
                <textarea v-model="o.text" rows="2" dir="auto" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
                <div class="flex flex-wrap items-center gap-2">
                    <button v-for="c in COLORS" :key="c" type="button" class="h-7 w-7 rounded-full border-2" :class="o.color === c ? 'border-[var(--accent-600)]' : 'border-white'" :style="{ background: c }" @click="o.color = c" />
                    <input v-model="o.color" type="color" class="h-7 w-9 cursor-pointer rounded border-0 bg-transparent p-0" />
                    <label class="ms-2 inline-flex items-center gap-1"><input v-model="o.bold" type="checkbox" class="rounded" />{{ t('pdft.bold', 'عريض') }}</label>
                </div>
                <label class="block">{{ t('pdft.wm_size', 'الحجم') }}<input v-model.number="o.size" type="range" min="4" max="30" class="w-full" /></label>
            </template>
            <template v-else>
                <input type="file" accept="image/png,image/jpeg,image/webp" class="block w-full text-xs" @change="pickLogo" />
                <label class="block">{{ t('pdft.wm_size', 'الحجم') }}<input v-model.number="o.scale" type="range" min="10" max="100" class="w-full" /></label>
            </template>
            <label class="block">{{ t('pdft.opacity', 'الشفافية') }} <span class="text-gray-500">{{ o.opacity }}%</span><input v-model.number="o.opacity" type="range" min="5" max="100" class="w-full" /></label>
            <div>
                <p class="mb-1">{{ t('pdft.angle', 'الزاوية') }}</p>
                <div class="flex flex-wrap gap-1">
                    <button v-for="a in [0, -35, 35, -90]" :key="a" type="button" class="pdft-chip" :class="{ '!border-[var(--accent-600)] text-[var(--accent-600)]': o.angle === a }" @click="o.angle = a">{{ a }}°</button>
                </div>
            </div>
            <div>
                <p class="mb-1">{{ t('pdft.position', 'المكان') }}</p>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="p in [['center', t('pdft.pos_center', 'الوسط')], ['tile', t('pdft.pos_tile', 'متكرر')], ['top', t('pdft.pos_top', 'أعلى')], ['bottom', t('pdft.pos_bottom', 'أسفل')]]"
                        :key="p[0]"
                        type="button"
                        class="pdft-chip"
                        :class="{ '!border-[var(--accent-600)] text-[var(--accent-600)]': o.position === p[0] }"
                        @click="o.position = p[0]"
                    >
                        {{ p[1] }}
                    </button>
                </div>
            </div>
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
