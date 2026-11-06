<script setup>
// «خريطة مسطحة» (2026-11-06, by request): a light 2D plan of the library for old or weak phones,
// and for anyone who finds it easier than 3D. Same props and events as LibraryMap3D.
// Drag to move, pinch or Ctrl+wheel to zoom, tap a bookcase to choose it.
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { outline, isHall } from './route.js';
import { stickerStand } from './stickers.js';
import { sectionColor } from './colors.js';

const props = defineProps({
    floor: { type: Object, required: true },
    shelves: { type: Array, required: true },
    highlight: { type: Object, default: null },
    route: { type: Object, default: null },
    avatar: { type: Object, default: null },
    follow: { type: Boolean, default: false },
    beacons: { type: Object, default: null },
});
const emit = defineEmits(['pick', 'ready']);

const el = ref(null);
const size = reactive({ w: 1, h: 1 });
let ro = null;

// Phones held upright see a long library end-on, like the 3D overview.
const W = computed(() => props.floor.width), D = computed(() => props.floor.depth);
const turned = computed(() => size.h / size.w > 1.1 && W.value > D.value * 1.2);
const frame = computed(() => (turned.value ? { w: D.value, h: W.value } : { w: W.value, h: D.value }));
const turnT = computed(() => (turned.value ? `translate(${D.value} 0) rotate(90)` : ''));
/** A plan point in the turned frame the view box uses. */
const vp = (p) => (turned.value ? { x: D.value - p.y, y: p.x } : { x: p.x, y: p.y });
const upright = (rot = 0) => -(rot || 0) - (turned.value ? 90 : 0);

// View box: the whole plan, or zoomed in. -------------------------------------------------------
const M = 0.8;
const vb = reactive({ x: 0, y: 0, w: 1, h: 1 });
function fit(x, y, w, h) {
    const a = size.w / size.h;
    if (w / h > a) { const nh = w / a; y -= (nh - h) / 2; h = nh; } else { const nw = h * a; x -= (nw - w) / 2; w = nw; }
    Object.assign(vb, { x, y, w, h });
}
function overview() { fit(-M, -M, frame.value.w + 2 * M, frame.value.h + 2 * M); }
function centreOn(p, span) {
    const q = vp(p), w = Math.min(span, frame.value.w + 2 * M), h = Math.min(span, frame.value.h + 2 * M);
    fit(q.x - w / 2, q.y - h / 2, w, h);
}
function focusShelf(id) {
    const s = props.shelves.find((i) => i.id === id);
    if (!s) return;
    // The bookcase and the way to it when there is one, else the bookcase and what is round it.
    const pts = (props.route?.points ?? []).map(vp);
    pts.push(vp(s));
    let x0 = Math.min(...pts.map((p) => p.x)), x1 = Math.max(...pts.map((p) => p.x)), y0 = Math.min(...pts.map((p) => p.y)), y1 = Math.max(...pts.map((p) => p.y));
    const pad = 3;
    if (x1 - x0 < 10) { const c = (x0 + x1) / 2; x0 = c - 5; x1 = c + 5; }
    if (y1 - y0 < 10) { const c = (y0 + y1) / 2; y0 = c - 5; y1 = c + 5; }
    fit(x0 - pad, y0 - pad, x1 - x0 + 2 * pad, y1 - y0 + 2 * pad);
}
const viewBox = computed(() => `${vb.x} ${vb.y} ${vb.w} ${vb.h}`);
const scale = computed(() => vb.w / size.w); // metres per screen pixel

// Gestures. ----------------------------------------------------------------------------------
const pointers = new Map();
let pan = null, pinch = null, moved = 0;
function toView(e) {
    const r = el.value.getBoundingClientRect();
    return { x: vb.x + ((e.clientX - r.left) / r.width) * vb.w, y: vb.y + ((e.clientY - r.top) / r.height) * vb.h };
}
function zoomAround(f, at) {
    const maxW = (frame.value.w + 2 * M) * 1.5, minW = 4;
    const nw = Math.min(maxW, Math.max(minW, vb.w * f));
    const k = nw / vb.w;
    Object.assign(vb, { x: at.x - (at.x - vb.x) * k, y: at.y - (at.y - vb.y) * k, w: nw, h: vb.h * k });
}
function down(e) {
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    el.value.setPointerCapture?.(e.pointerId);
    if (pointers.size === 1) { pan = { x: e.clientX, y: e.clientY, vx: vb.x, vy: vb.y }; moved = 0; }
    if (pointers.size === 2) {
        const [a, b] = [...pointers.values()];
        pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), w: vb.w };
        pan = null;
        moved = 99;
    }
}
function move(e) {
    if (!pointers.has(e.pointerId)) return;
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pinch && pointers.size >= 2) {
        const [a, b] = [...pointers.values()];
        const mid = toView({ clientX: (a.x + b.x) / 2, clientY: (a.y + b.y) / 2 });
        zoomAround((pinch.w * pinch.d) / Math.max(10, Math.hypot(a.x - b.x, a.y - b.y)) / vb.w, mid);
    } else if (pan) {
        const dx = e.clientX - pan.x, dy = e.clientY - pan.y;
        moved = Math.max(moved, Math.hypot(dx, dy));
        vb.x = pan.vx - dx * scale.value;
        vb.y = pan.vy - dy * scale.value;
    }
}
function up(e) {
    pointers.delete(e.pointerId);
    if (pointers.size < 2) pinch = null;
    if (!pointers.size) pan = null;
}
function wheel(e) {
    e.preventDefault();
    zoomAround(Math.exp(e.deltaY * 0.0015), toView(e));
}
function pick(id) {
    if (moved < 6) emit('pick', id);
}

// What is drawn. ------------------------------------------------------------------------------
const halls = computed(() => props.shelves.filter(isHall));
const things = computed(() => props.shelves.filter((s) => !isHall(s)));
const poly = (pts) => pts.map((p) => `${p.x},${p.y}`).join(' ');
const fillOf = (s) => (s.kind === 'shelf' ? s.color || sectionColor(s.section_id) : s.kind === 'stairs' ? '#7c3aed' : s.kind === 'door' ? '#10b981' : s.kind === 'gate' ? '#dc2626' : s.kind === 'desk' ? '#0f766e' : s.kind === 'qr' ? '#111827' : s.kind === 'obstacle' ? s.color || '#6b7280' : s.kind === 'wall' ? '#475569' : '#a16207');
const font = computed(() => Math.max(0.28, Math.min(0.6, scale.value * 11)));
const routePts = computed(() => (props.route?.points?.length >= 2 ? poly(props.route.points) : ''));
const target = computed(() => props.shelves.find((s) => s.id === props.highlight?.id) ?? null);
const beaconSpots = computed(() => (props.beacons?.ids ?? []).map((id) => props.shelves.find((s) => s.id === id)).filter(Boolean).map((s) => ({ id: s.id, ...stickerStand(s), near: s.id === props.beacons.nearest })));
const heading = computed(() => ((props.avatar?.heading ?? 0) * 180) / Math.PI);

watch(() => [props.avatar, props.follow], () => {
    if (props.follow && props.avatar) centreOn(props.avatar, 14);
}, { deep: true });
watch(turned, () => overview());
watch(() => [props.floor.width, props.floor.depth], () => overview());

onMounted(() => {
    const measure = () => {
        const r = el.value?.getBoundingClientRect();
        if (!r?.width) return;
        const first = size.w === 1;
        size.w = r.width; size.h = r.height;
        if (first) overview();
    };
    measure();
    ro = new ResizeObserver(measure);
    ro.observe(el.value);
    emit('ready', api);
});
onBeforeUnmount(() => ro?.disconnect());

const api = { overview, focusShelf };
defineExpose(api);
</script>

<template>
    <div ref="el" class="absolute inset-0 touch-none select-none bg-gray-100 dark:bg-gray-900" dir="ltr"
        @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up" @wheel="wheel">
        <svg class="h-full w-full" :viewBox="viewBox" preserveAspectRatio="xMidYMid meet">
            <g :transform="turnT">
                <rect x="0" y="0" :width="W" :height="D" class="fill-white stroke-gray-300 dark:fill-gray-800 dark:stroke-gray-600" stroke-width="0.08" />
                <polygon v-for="h in halls" :key="h.id" :points="poly(outline(h))" :class="h.kind === 'corridor' ? 'fill-slate-100 dark:fill-slate-700/60' : 'fill-amber-50/70 dark:fill-gray-700/60'" stroke="#475569" stroke-width="0.12" stroke-linejoin="round" />
                <g v-for="s in things" :key="s.id" :transform="`translate(${s.x} ${s.y}) rotate(${s.rotation || 0})`" @click="pick(s.id)" class="cursor-pointer">
                    <polygon v-if="s.kind === 'obstacle' && s.points?.length >= 3" :points="poly(outline(s).map((p) => ({ x: p.x - s.x, y: p.y - s.y })))" :transform="`rotate(${-(s.rotation || 0)})`" :fill="fillOf(s)" fill-opacity="0.7" />
                    <rect v-else-if="s.kind === 'door'" :x="-s.width / 2" y="-0.1" :width="s.width" height="0.2" fill="#10b981" />
                    <template v-else>
                        <rect :x="-s.width / 2" :y="-s.depth / 2" :width="s.width" :height="s.depth" rx="0.04"
                            :fill="s.kind === 'stairs' ? '#ede9fe' : fillOf(s)" :stroke="s.kind === 'stairs' ? '#7c3aed' : 'rgba(0,0,0,.25)'" :stroke-width="s.kind === 'stairs' ? 0.06 : 0.03" />
                        <template v-if="s.kind === 'stairs'">
                            <template v-if="s.double_sided">
                                <line :x1="-s.width / 2" :y1="-s.depth / 2" :x2="s.width / 2" :y2="s.depth / 2" stroke="#7c3aed" stroke-width="0.05" />
                                <line :x1="s.width / 2" :y1="-s.depth / 2" :x2="-s.width / 2" :y2="s.depth / 2" stroke="#7c3aed" stroke-width="0.05" />
                            </template>
                            <line v-else v-for="k in 7" :key="k" :x1="-s.width / 2" :x2="s.width / 2" :y1="-s.depth / 2 + (s.depth / 8) * k" :y2="-s.depth / 2 + (s.depth / 8) * k" stroke="#7c3aed" stroke-width="0.03" />
                        </template>
                    </template>
                    <text v-if="!['door', 'qr', 'wall', 'obstacle'].includes(s.kind)" :transform="`rotate(${upright(s.rotation)})`" text-anchor="middle" dominant-baseline="central"
                        :font-size="font" font-weight="800" :fill="s.kind === 'stairs' ? '#5b21b6' : '#fff'" style="paint-order: stroke" :stroke="s.kind === 'stairs' ? '#fff' : 'rgba(0,0,0,.35)'" :stroke-width="font / 6" class="pointer-events-none">{{ s.code }}</text>
                </g>
                <!-- Hall names -->
                <text v-for="h in halls.filter((x) => x.kind === 'hall' && x.code)" :key="'n' + h.id" :transform="`translate(${h.x} ${h.y}) rotate(${turned ? -90 : 0})`" text-anchor="middle" dominant-baseline="central"
                    :font-size="font * 1.3" font-weight="700" class="pointer-events-none fill-slate-400/80 dark:fill-slate-400/60">{{ h.code }}</text>

                <!-- The bookcase looked for -->
                <g v-if="target" :transform="`translate(${target.x} ${target.y}) rotate(${target.rotation || 0})`" class="pointer-events-none">
                    <rect :x="-target.width / 2 - 0.15" :y="-target.depth / 2 - 0.15" :width="target.width + 0.3" :height="target.depth + 0.3" rx="0.1" fill="none" stroke="#f59e0b" stroke-width="0.14">
                        <animate attributeName="stroke-opacity" values="1;0.3;1" dur="1.4s" repeatCount="indefinite" />
                    </rect>
                </g>

                <!-- Route -->
                <template v-if="routePts">
                    <polyline :points="routePts" fill="none" stroke="#fff" stroke-width="0.42" stroke-linecap="round" stroke-linejoin="round" />
                    <polyline :points="routePts" fill="none" stroke="#2563eb" stroke-width="0.26" stroke-linecap="round" stroke-linejoin="round" />
                    <polyline :points="routePts" fill="none" stroke="#bfdbfe" stroke-width="0.08" stroke-dasharray="0.3 0.5" stroke-linecap="round">
                        <animate attributeName="stroke-dashoffset" from="0.8" to="0" dur="0.8s" repeatCount="indefinite" />
                    </polyline>
                </template>

                <!-- Nearest stickers -->
                <g v-for="b in beaconSpots" :key="'b' + b.id" :transform="`translate(${b.x} ${b.y})`" class="pointer-events-none">
                    <circle r="0.5" :fill="b.near ? '#f97316' : '#06b6d4'" fill-opacity="0.25" :stroke="b.near ? '#f97316' : '#06b6d4'" stroke-width="0.07">
                        <animate attributeName="r" values="0.35;0.75;0.35" dur="1.4s" repeatCount="indefinite" />
                    </circle>
                    <circle r="0.15" :fill="b.near ? '#f97316' : '#06b6d4'" />
                </g>

                <!-- Entrance -->
                <g v-if="floor.entrance" :transform="`translate(${floor.entrance.x} ${floor.entrance.y})`" class="pointer-events-none">
                    <circle r="0.3" fill="#10b981" stroke="#fff" stroke-width="0.06" />
                </g>

                <!-- You -->
                <g v-if="avatar" :transform="`translate(${avatar.x} ${avatar.y}) rotate(${heading})`" class="pointer-events-none">
                    <circle r="0.9" fill="#2563eb" fill-opacity="0.15" />
                    <circle r="0.34" fill="#2563eb" stroke="#fff" stroke-width="0.08" />
                    <path d="M0.62 0 L0.18 0.28 L0.18 -0.28 Z" fill="#2563eb" stroke="#fff" stroke-width="0.04" />
                </g>
            </g>
        </svg>
    </div>
</template>
