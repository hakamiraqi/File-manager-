<script setup>
/**
 * A cover scanner for the book form (2026-11-14), in the manner of phone scanner apps:
 * the page's edges are found by themselves, the four corners (and sides) can be dragged
 * with a magnifier showing exactly where the corner sits, a tilted cover is straightened,
 * then light and colour filters, and the text printed on the cover can be read to fill
 * the form. Everything but the text reading happens in the browser.
 */
import { ref, reactive, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { detectQuad, defaultQuad, warp, quadSize, applyFilter, isConvex, FILTERS } from '@/lib/docscan';
import {
    XMarkIcon, ArrowPathIcon, ArrowUturnRightIcon, SparklesIcon, ArrowsPointingOutIcon, CheckIcon,
    DocumentTextIcon, ChevronRightIcon, SunIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ show: Boolean, file: { type: [Object, null], default: null } });
const emit = defineEmits(['close', 'done']);

const WORK_MAX = 2200; // the picture is worked on at this size at most
const OUT_MAX = 1600;
const PREVIEW_MAX = 900;
const LOUPE = 128;

// The book form is a <dialog> opened as a modal: only what is inside it can be seen and
// touched, so the scanner is placed in that dialog (or in the page when there is none).
const anchor = ref(null);
const target = ref(null);

const step = ref('crop'); // crop → enhance
const busy = ref(false);
const error = ref('');
const found = ref(false);

let work = null; // canvas with the (rotated) photo
const stage = ref(null);
const shown = ref(null); // the <canvas> showing the photo
const loupe = ref(null);
const quad = ref([]); // in work-canvas pixels: TL, TR, BR, BL
const box = reactive({ w: 0, h: 0, scale: 1 });
const active = ref(null); // { kind: 'corner'|'side', i }
const loupeSide = ref('left');

// enhance
const result = ref(null); // canvas shown in step 2
let flat = null; // straightened picture, preview size
const filter = ref('auto');
const brightness = ref(0);
const contrast = ref(0);
const thumbs = ref({});

// text
const ocr = reactive({ busy: false, error: '', lines: [], isbn: null, done: false });
const FIELDS = ['title', 'authors', 'publisher'];
const fieldNames = computed(() => ({
    title: t('library.field.title', 'العنوان'),
    authors: t('library.field.authors', 'المؤلف'),
    publisher: t('library.field.publisher', 'الناشر'),
}));
const filterNames = computed(() => ({
    original: t('scan.f.original', 'الأصل'),
    auto: t('scan.f.auto', 'تلقائي'),
    light: t('scan.f.light', 'إضاءة'),
    magic: t('scan.f.magic', 'ألوان زاهية'),
    gray: t('scan.f.gray', 'رمادي'),
    bw: t('scan.f.bw', 'أبيض وأسود'),
}));

watch(() => [props.show, props.file], async ([show, file]) => {
    if (!show || !file) return;
    target.value = anchor.value?.closest('dialog') || document.body;
    await nextTick();
    step.value = 'crop';
    error.value = '';
    filter.value = 'auto';
    brightness.value = 0;
    contrast.value = 0;
    Object.assign(ocr, { busy: false, error: '', lines: [], isbn: null, done: false });
    busy.value = true;
    try {
        await load(file);
        await nextTick();
        layout();
        autoDetect();
    } catch {
        error.value = t('scan.bad_image', 'تعذّر فتح الصورة.');
    } finally {
        busy.value = false;
    }
});

async function load(file) {
    const url = URL.createObjectURL(file);
    try {
        const img = new Image();
        img.src = url;
        await img.decode();
        const scale = Math.min(1, WORK_MAX / Math.max(img.naturalWidth, img.naturalHeight));
        work = document.createElement('canvas');
        work.width = Math.max(1, Math.round(img.naturalWidth * scale));
        work.height = Math.max(1, Math.round(img.naturalHeight * scale));
        work.getContext('2d', { willReadFrequently: true }).drawImage(img, 0, 0, work.width, work.height);
    } finally {
        URL.revokeObjectURL(url);
    }
}

function layout() {
    if (!work || !stage.value) return;
    const pad = 28; // room for the corner handles at the very edge
    const r = stage.value.getBoundingClientRect();
    const scale = Math.min((r.width - pad * 2) / work.width, (r.height - pad * 2) / work.height);
    box.scale = scale > 0 ? scale : 1;
    box.w = Math.round(work.width * box.scale);
    box.h = Math.round(work.height * box.scale);
    nextTick(() => {
        const c = shown.value;
        if (!c) return;
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        c.width = Math.round(box.w * dpr);
        c.height = Math.round(box.h * dpr);
        c.getContext('2d').drawImage(work, 0, 0, c.width, c.height);
    });
}

function small(max) {
    // Halved step by step: one big jump would skip pixels and keep the photo's grain,
    // which the edge finder then mistakes for edges.
    let src = work;
    while (Math.max(src.width, src.height) > max * 2) {
        const half = document.createElement('canvas');
        half.width = Math.max(1, Math.round(src.width / 2));
        half.height = Math.max(1, Math.round(src.height / 2));
        const hc = half.getContext('2d', { willReadFrequently: true });
        hc.imageSmoothingQuality = 'high';
        hc.drawImage(src, 0, 0, half.width, half.height);
        src = half;
    }
    const scale = Math.min(1, max / Math.max(src.width, src.height));
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(src.width * scale));
    c.height = Math.max(1, Math.round(src.height * scale));
    const ctx = c.getContext('2d', { willReadFrequently: true });
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(src, 0, 0, c.width, c.height);
    return { img: ctx.getImageData(0, 0, c.width, c.height), scale: c.width / work.width };
}

function autoDetect() {
    if (!work) return;
    const { img, scale } = small(320);
    const r = detectQuad(img);
    found.value = r.found;
    quad.value = r.quad.map((p) => ({ x: p.x / scale, y: p.y / scale }));
}

function wholePicture() {
    quad.value = defaultQuad(work.width, work.height, 0);
    found.value = false;
}

function rotate() {
    if (!work) return;
    const c = document.createElement('canvas');
    c.width = work.height;
    c.height = work.width;
    const ctx = c.getContext('2d', { willReadFrequently: true });
    ctx.translate(c.width, 0);
    ctx.rotate(Math.PI / 2);
    ctx.drawImage(work, 0, 0);
    work = c;
    layout();
    autoDetect();
}

// ---- dragging corners and sides, with the magnifier
const pts = computed(() => quad.value.map((p) => ({ x: p.x * box.scale, y: p.y * box.scale })));
const polygon = computed(() => pts.value.map((p) => `${p.x},${p.y}`).join(' '));
const mids = computed(() => pts.value.map((p, i) => {
    const q = pts.value[(i + 1) % 4];
    return { x: (p.x + q.x) / 2, y: (p.y + q.y) / 2 };
}));
const valid = computed(() => quad.value.length === 4 && isConvex(quad.value));

let last = null;
function grab(e, kind, i) {
    e.preventDefault();
    e.target.setPointerCapture?.(e.pointerId);
    active.value = { kind, i };
    last = { x: e.clientX, y: e.clientY };
    place(e);
    nextTick(drawLoupe);
}

function move(e) {
    if (!active.value) return;
    const dx = (e.clientX - last.x) / box.scale;
    const dy = (e.clientY - last.y) / box.scale;
    last = { x: e.clientX, y: e.clientY };
    const next = quad.value.map((p) => ({ ...p }));
    const shift = (i) => {
        next[i].x = Math.max(0, Math.min(work.width, next[i].x + dx));
        next[i].y = Math.max(0, Math.min(work.height, next[i].y + dy));
    };
    if (active.value.kind === 'corner') {
        shift(active.value.i);
    } else {
        shift(active.value.i);
        shift((active.value.i + 1) % 4);
    }
    if (isConvex(next)) quad.value = next;
    place(e);
    drawLoupe();
}

function release() {
    active.value = null;
}

// The magnifier sits in the corner away from the finger.
function place(e) {
    const r = stage.value.getBoundingClientRect();
    const nearLeft = e.clientX - r.left < LOUPE + 40 && e.clientY - r.top < LOUPE + 40;
    loupeSide.value = nearLeft ? 'right' : 'left';
}

function focus() {
    if (!active.value) return null;
    const q = quad.value;
    if (active.value.kind === 'corner') return q[active.value.i];
    const a = q[active.value.i];
    const b = q[(active.value.i + 1) % 4];
    return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
}

function drawLoupe() {
    const c = loupe.value;
    const f = focus();
    if (!c || !f || !work) return;
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    const size = LOUPE * dpr;
    if (c.width !== size) c.width = c.height = size;
    const ctx = c.getContext('2d');
    // 2.5× what is on screen, but never less than the photo's own pixels.
    const zoom = Math.max(box.scale * 2.5, 1);
    const span = LOUPE / zoom; // photo pixels across the magnifier
    ctx.fillStyle = '#111';
    ctx.fillRect(0, 0, size, size);
    ctx.imageSmoothingEnabled = true;
    ctx.drawImage(work, f.x - span / 2, f.y - span / 2, span, span, 0, 0, size, size);
    // The frame's lines, as they pass through the magnifier.
    const map = (p) => ({ x: ((p.x - f.x) / span + 0.5) * size, y: ((p.y - f.y) / span + 0.5) * size });
    ctx.lineWidth = 2 * dpr;
    ctx.strokeStyle = '#34d399';
    ctx.beginPath();
    quad.value.forEach((p, i) => {
        const m = map(p);
        if (i === 0) ctx.moveTo(m.x, m.y);
        else ctx.lineTo(m.x, m.y);
    });
    ctx.closePath();
    ctx.stroke();
    // Cross-hair on the exact point.
    ctx.strokeStyle = 'rgba(255,255,255,0.9)';
    ctx.lineWidth = dpr;
    ctx.beginPath();
    ctx.moveTo(size / 2 - 12 * dpr, size / 2);
    ctx.lineTo(size / 2 + 12 * dpr, size / 2);
    ctx.moveTo(size / 2, size / 2 - 12 * dpr);
    ctx.lineTo(size / 2, size / 2 + 12 * dpr);
    ctx.stroke();
}

// ---- straighten, then filters
function straighten(max) {
    const scale = Math.min(1, (max * 1.5) / Math.max(work.width, work.height));
    let src = work;
    if (scale < 1) {
        src = document.createElement('canvas');
        src.width = Math.round(work.width * scale);
        src.height = Math.round(work.height * scale);
        src.getContext('2d', { willReadFrequently: true }).drawImage(work, 0, 0, src.width, src.height);
    }
    const q = quad.value.map((p) => ({ x: p.x * (src.width / work.width), y: p.y * (src.height / work.height) }));
    const size = quadSize(q, max);
    const img = src.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, src.width, src.height);
    return warp(img, q, size.width, size.height);
}

function paint(canvas, img) {
    canvas.width = img.width;
    canvas.height = img.height;
    canvas.getContext('2d').putImageData(new ImageData(img.data, img.width, img.height), 0, 0);
}

async function toEnhance() {
    if (!valid.value) return;
    busy.value = true;
    await new Promise((r) => setTimeout(r, 20)); // let the spinner show
    try {
        flat = straighten(PREVIEW_MAX);
        step.value = 'enhance';
        await nextTick();
        render();
        makeThumbs();
    } finally {
        busy.value = false;
    }
}

function render() {
    if (!flat || !result.value) return;
    paint(result.value, applyFilter(flat, filter.value, { brightness: brightness.value, contrast: contrast.value }));
}
watch([filter, brightness, contrast], render);

function makeThumbs() {
    const scale = Math.min(1, 110 / Math.max(flat.width, flat.height));
    const c = document.createElement('canvas');
    paint(c, flat);
    const s = document.createElement('canvas');
    s.width = Math.max(1, Math.round(flat.width * scale));
    s.height = Math.max(1, Math.round(flat.height * scale));
    const ctx = s.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(c, 0, 0, s.width, s.height);
    const tiny = ctx.getImageData(0, 0, s.width, s.height);
    const out = {};
    for (const f of FILTERS) {
        paint(s, applyFilter(tiny, f));
        out[f] = s.toDataURL('image/jpeg', 0.7);
    }
    thumbs.value = out;
}

function resetLight() {
    brightness.value = 0;
    contrast.value = 0;
}

function finalBlob(max = OUT_MAX) {
    const img = applyFilter(straighten(max), filter.value, { brightness: brightness.value, contrast: contrast.value });
    const c = document.createElement('canvas');
    paint(c, img);
    return new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 0.9));
}

// ---- the text on the cover
async function readText() {
    ocr.busy = true;
    ocr.error = '';
    try {
        // Read from the straightened, evenly lit picture: it reads best.
        const img = applyFilter(straighten(1400), filter.value === 'bw' ? 'bw' : 'light');
        const c = document.createElement('canvas');
        paint(c, img);
        const blob = await new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 0.9));
        const data = new FormData();
        data.append('image', blob, 'cover.jpg');
        const res = await window.axios.post(route('library.manage.cover-text'), data);
        const titleWords = (res.data.title || '').trim();
        let left = titleWords;
        ocr.lines = (res.data.lines || []).map((l) => {
            // The lines that make up the suggested title start as «العنوان».
            let field = null;
            if (left && left.startsWith(l.text)) {
                field = 'title';
                left = left.slice(l.text.length).trim();
            }
            return { text: l.text, size: l.size, field };
        });
        ocr.isbn = res.data.isbn;
        ocr.done = true;
        if (!ocr.lines.length) ocr.error = t('scan.no_text', 'لم يُعثر على نص واضح في الصورة.');
    } catch (e) {
        ocr.error = e.response?.data?.message || t('scan.text_failed', 'تعذّر استخراج النص.');
    } finally {
        ocr.busy = false;
    }
}

function setField(line, field) {
    line.field = line.field === field ? null : field;
}

const chosen = computed(() => {
    const out = {};
    for (const f of FIELDS) {
        const text = ocr.lines.filter((l) => l.field === f).map((l) => l.text).join(f === 'authors' ? ', ' : ' ').trim();
        if (text) out[f] = text;
    }
    if (ocr.isbn) out.isbn = ocr.isbn;
    return out;
});

async function done() {
    busy.value = true;
    await new Promise((r) => setTimeout(r, 20));
    try {
        const blob = await finalBlob();
        emit('done', { file: new File([blob], 'cover.jpg', { type: 'image/jpeg' }), fields: chosen.value });
    } finally {
        busy.value = false;
    }
}

function useAsIs() {
    emit('done', { file: props.file, fields: {} });
}

function onResize() {
    if (props.show && step.value === 'crop') layout();
}
// Escape leaves the scanner only, not the whole book form behind it.
function onKey(e) {
    if (props.show && e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        emit('close');
    }
}
window.addEventListener('resize', onResize);
window.addEventListener('keydown', onKey, true);
onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize);
    window.removeEventListener('keydown', onKey, true);
});
</script>

<template>
    <span ref="anchor" hidden />
    <Teleport v-if="show && target" :to="target">
        <div class="fixed inset-0 z-[70] flex flex-col bg-gray-950 text-white" dir="rtl">
            <!-- top bar -->
            <div class="flex shrink-0 items-center justify-between gap-2 px-3 py-2.5">
                <button type="button" class="rounded-full p-2 hover:bg-white/10" :title="t('action.cancel', 'إلغاء')" @click="$emit('close')">
                    <XMarkIcon class="h-6 w-6" />
                </button>
                <div class="text-sm font-medium">
                    {{ step === 'crop' ? t('scan.step_crop', 'حدّد حواف الغلاف') : t('scan.step_enhance', 'الإضاءة والفلاتر') }}
                </div>
                <button v-if="step === 'crop'" type="button" class="rounded-md px-2 py-1 text-xs text-white/70 hover:bg-white/10" @click="useAsIs">
                    {{ t('scan.as_is', 'بدون تعديل') }}
                </button>
                <button v-else type="button" class="flex items-center gap-1 rounded-md px-2 py-1 text-xs text-white/80 hover:bg-white/10" @click="step = 'crop'; $nextTick(layout)">
                    <ChevronRightIcon class="h-4 w-4" /> {{ t('scan.back_crop', 'الحواف') }}
                </button>
            </div>

            <p v-if="error" class="px-4 text-center text-sm text-red-400">{{ error }}</p>

            <!-- step 1: edges -->
            <template v-if="step === 'crop'">
                <div ref="stage" class="relative min-h-0 flex-1 select-none overflow-hidden" style="touch-action: none">
                    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2" :style="{ width: box.w + 'px', height: box.h + 'px' }">
                        <canvas ref="shown" class="block h-full w-full rounded-sm" />
                        <svg
                            v-if="quad.length === 4"
                            class="absolute inset-0 overflow-visible"
                            :width="box.w" :height="box.h"
                            @pointermove="move" @pointerup="release" @pointercancel="release"
                        >
                            <!-- everything outside the frame is dimmed -->
                            <path :d="`M0 0H${box.w}V${box.h}H0Z M${polygon.replaceAll(' ', ' L')}Z`" fill="rgba(0,0,0,0.5)" fill-rule="evenodd" />
                            <polygon :points="polygon" fill="rgba(52,211,153,0.08)" :stroke="valid ? '#34d399' : '#f87171'" stroke-width="2.5" stroke-linejoin="round" />
                            <!-- sides -->
                            <g v-for="(m, i) in mids" :key="'s' + i" class="cursor-move" @pointerdown="grab($event, 'side', i)">
                                <circle :cx="m.x" :cy="m.y" r="20" fill="transparent" />
                                <rect :x="m.x - 9" :y="m.y - 9" width="18" height="18" rx="5" fill="#fff" stroke="#34d399" stroke-width="2.5" />
                            </g>
                            <!-- corners -->
                            <g v-for="(p, i) in pts" :key="'c' + i" class="cursor-grab" @pointerdown="grab($event, 'corner', i)">
                                <circle :cx="p.x" :cy="p.y" r="26" fill="transparent" />
                                <circle :cx="p.x" :cy="p.y" r="13" :fill="active?.kind === 'corner' && active.i === i ? 'rgba(52,211,153,0.35)' : 'rgba(255,255,255,0.25)'" stroke="#fff" stroke-width="3" />
                                <circle :cx="p.x" :cy="p.y" r="3" fill="#34d399" />
                            </g>
                        </svg>
                    </div>

                    <!-- the magnifier: shows exactly where the corner is while the finger covers it -->
                    <div
                        v-show="active"
                        class="pointer-events-none absolute top-3 overflow-hidden rounded-full border-[3px] border-white shadow-2xl ring-1 ring-black/40"
                        :class="loupeSide === 'left' ? 'left-3' : 'right-3'"
                        :style="{ width: LOUPE + 'px', height: LOUPE + 'px' }"
                    >
                        <canvas ref="loupe" class="h-full w-full" />
                    </div>

                    <div v-if="busy" class="absolute inset-0 flex items-center justify-center bg-black/40">
                        <ArrowPathIcon class="h-8 w-8 animate-spin" />
                    </div>
                </div>

                <p class="shrink-0 px-4 pt-1 text-center text-xs text-white/60">
                    {{ found ? t('scan.found', 'تم التعرّف على الحواف. اسحب الزوايا أو الأضلاع للتعديل.') : t('scan.not_found', 'لم تتضح الحواف. اسحب الزوايا إلى أركان الغلاف.') }}
                </p>
                <div class="flex shrink-0 items-center justify-between gap-2 px-3 pb-4 pt-2">
                    <div class="flex gap-1.5">
                        <button type="button" class="flex flex-col items-center gap-0.5 rounded-lg px-2.5 py-1.5 text-[11px] hover:bg-white/10" @click="autoDetect">
                            <SparklesIcon class="h-5 w-5" /> {{ t('scan.auto', 'تلقائي') }}
                        </button>
                        <button type="button" class="flex flex-col items-center gap-0.5 rounded-lg px-2.5 py-1.5 text-[11px] hover:bg-white/10" @click="wholePicture">
                            <ArrowsPointingOutIcon class="h-5 w-5" /> {{ t('scan.whole', 'الكل') }}
                        </button>
                        <button type="button" class="flex flex-col items-center gap-0.5 rounded-lg px-2.5 py-1.5 text-[11px] hover:bg-white/10" @click="rotate">
                            <ArrowUturnRightIcon class="h-5 w-5" /> {{ t('scan.rotate', 'تدوير') }}
                        </button>
                    </div>
                    <button type="button" :disabled="!valid || busy" class="rounded-full bg-emerald-500 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600 disabled:opacity-50" @click="toEnhance">
                        {{ t('scan.next', 'التالي') }}
                    </button>
                </div>
            </template>

            <!-- step 2: light, filters, text -->
            <template v-else>
                <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden p-3">
                    <canvas ref="result" class="max-h-full max-w-full rounded shadow-lg" />
                    <div v-if="busy" class="absolute inset-0 flex items-center justify-center bg-black/40">
                        <ArrowPathIcon class="h-8 w-8 animate-spin" />
                    </div>
                </div>

                <div class="shrink-0 space-y-2.5 px-3 pb-4">
                    <!-- filters -->
                    <div class="flex gap-2 overflow-x-auto pb-1">
                        <button
                            v-for="f in FILTERS" :key="f" type="button"
                            class="flex shrink-0 flex-col items-center gap-1 rounded-lg p-1 text-[11px]"
                            :class="filter === f ? 'bg-emerald-500/20 text-emerald-300 ring-1 ring-emerald-400' : 'text-white/70 hover:bg-white/10'"
                            @click="filter = f"
                        >
                            <img v-if="thumbs[f]" :src="thumbs[f]" class="h-14 w-11 rounded object-cover" alt="" />
                            {{ filterNames[f] }}
                        </button>
                    </div>

                    <!-- light -->
                    <div class="grid grid-cols-[auto_1fr_auto_1fr_auto] items-center gap-2 text-[11px] text-white/70">
                        <SunIcon class="h-4 w-4" :title="t('scan.brightness', 'السطوع')" />
                        <input v-model.number="brightness" type="range" min="-60" max="60" class="w-full accent-emerald-400" :aria-label="t('scan.brightness', 'السطوع')" />
                        <span>{{ t('scan.contrast', 'التباين') }}</span>
                        <input v-model.number="contrast" type="range" min="-60" max="60" class="w-full accent-emerald-400" :aria-label="t('scan.contrast', 'التباين')" />
                        <button type="button" class="rounded px-1.5 py-0.5 hover:bg-white/10 disabled:opacity-30" :disabled="!brightness && !contrast" @click="resetLight">{{ t('scan.reset', 'صفر') }}</button>
                    </div>

                    <!-- text on the cover -->
                    <div v-if="ocr.done || ocr.error" class="max-h-44 overflow-y-auto rounded-lg bg-white/5 p-2">
                        <p v-if="ocr.error" class="text-xs text-amber-300">{{ ocr.error }}</p>
                        <template v-else>
                            <p class="mb-1.5 text-[11px] text-white/60">{{ t('scan.text_hint', 'اختر لكل سطر مكانه في بيانات الكتاب (اختياري):') }}</p>
                            <p v-if="ocr.isbn" class="mb-1.5 text-xs text-emerald-300" dir="ltr">ISBN {{ ocr.isbn }}</p>
                            <div v-for="(line, i) in ocr.lines" :key="i" class="flex items-center justify-between gap-2 border-t border-white/5 py-1">
                                <span class="min-w-0 flex-1 truncate text-sm" dir="auto" :title="line.text">{{ line.text }}</span>
                                <span class="flex shrink-0 gap-1">
                                    <button
                                        v-for="f in FIELDS" :key="f" type="button"
                                        class="rounded-full px-2 py-0.5 text-[11px]"
                                        :class="line.field === f ? 'bg-emerald-500 text-white' : 'bg-white/10 text-white/70 hover:bg-white/20'"
                                        @click="setField(line, f)"
                                    >{{ fieldNames[f] }}</button>
                                </span>
                            </div>
                        </template>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <button type="button" :disabled="ocr.busy" class="flex items-center gap-1.5 rounded-full border border-white/20 px-3.5 py-2 text-sm hover:bg-white/10 disabled:opacity-50" @click="readText">
                            <ArrowPathIcon v-if="ocr.busy" class="h-4 w-4 animate-spin" />
                            <DocumentTextIcon v-else class="h-4 w-4" />
                            {{ t('scan.read_text', 'استخراج النص') }}
                        </button>
                        <button type="button" :disabled="busy" class="flex items-center gap-1.5 rounded-full bg-emerald-500 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-600 disabled:opacity-50" @click="done">
                            <CheckIcon class="h-5 w-5" /> {{ t('scan.done', 'اعتماد') }}
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </Teleport>
</template>
