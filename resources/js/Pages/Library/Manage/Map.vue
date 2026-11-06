<script setup>
// «ترتيب الرفوف» (2026-11-06, by request): lay out the reading room on a top-down plan. Drag
// bookcases, rotate them, give each a code and a section; «صفوف تلقائية» builds a whole room in
// one step. The 3D tab shows the same room as readers see it.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import LibraryMap3D from '@/Components/Library/Map/LibraryMap3D.vue';
import { frontNormal } from '@/Components/Library/Map/route.js';
import { sectionColor } from '@/Components/Library/Map/colors.js';
import { useTrans } from '@/Composables/useTrans';
import {
    PlusIcon, TrashIcon, ArrowPathRoundedSquareIcon, DocumentDuplicateIcon, Squares2X2Icon, CubeIcon,
    MapPinIcon, SparklesIcon, CheckCircleIcon, ExclamationCircleIcon, ArrowUturnRightIcon, EyeIcon, MagnifyingGlassPlusIcon, MagnifyingGlassMinusIcon, QrCodeIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ floor: Object, shelves: Array, sections: Array });
const page = usePage();

let keySeq = 0;
const withKeys = (list) => list.map((s) => ({ ...s, _key: s.id ? `id${s.id}` : `n${++keySeq}` }));
const strip = (list) => list.map(({ _key, section, ...rest }) => rest);
const floor = reactive(JSON.parse(JSON.stringify(props.floor)));
const items = ref(withKeys(props.shelves));
const snapshot = (f, list) => JSON.stringify({ f, list: strip(list) });
const original = ref(snapshot(props.floor, props.shelves));
watch(() => [props.floor, props.shelves], () => {
    Object.assign(floor, JSON.parse(JSON.stringify(props.floor)));
    items.value = withKeys(props.shelves);
    original.value = snapshot(props.floor, props.shelves);
    selectedKey.value = null;
});
const dirty = computed(() => snapshot(floor, items.value) !== original.value);

const view = ref('plan');
const selectedKey = ref(null);
const selected = computed(() => items.value.find((i) => i._key === selectedKey.value) ?? null);
const sectionPath = (id) => props.sections.find((s) => s.id === id)?.path ?? '';
const snap = ref(true);
const zoom = ref(1);
const placingEntrance = ref(false);

const KIND_DEFAULTS = {
    shelf: { width: 2, depth: 0.5, levels: 5, double_sided: false },
    double: { width: 2, depth: 0.9, levels: 5, double_sided: true },
    desk: { width: 2.4, depth: 0.8, levels: 1, double_sided: false },
    table: { width: 1.8, depth: 1, levels: 1, double_sided: false },
    wall: { width: 4, depth: 0.15, levels: 1, double_sided: false },
};
const kindLabel = (k) => ({ shelf: t('library.map.kind.shelf', 'رف كتب'), desk: t('library.map.kind.desk', 'مكتب الإعارة'), table: t('library.map.kind.table', 'طاولة قراءة'), wall: t('library.map.kind.wall', 'جدار') })[k] ?? k;

function nextCode(kind) {
    if (kind === 'desk') return uniqueCode(t('library.map.kind.desk_short', 'مكتب'));
    if (kind === 'table') return uniqueCode(t('library.map.kind.table_short', 'طاولة'));
    if (kind === 'wall') return uniqueCode(t('library.map.kind.wall', 'جدار'));
    const last = [...items.value].reverse().find((i) => i.kind === 'shelf' && /\d+$/.test(i.code));
    if (!last) return uniqueCode('A', 1);
    const [, prefix, n] = last.code.match(/^(.*?)(\d+)$/);
    return uniqueCode(prefix, Number(n) + 1);
}
function uniqueCode(prefix, start = 1) {
    const taken = new Set(items.value.map((i) => i.code.toLowerCase()));
    for (let n = start; ; n++) if (!taken.has(`${prefix}${n}`.toLowerCase())) return `${prefix}${n}`;
}

function add(type) {
    const kind = type === 'double' ? 'shelf' : type;
    const d = KIND_DEFAULTS[type];
    // Drop it in the middle of what is on screen, a little off any item already there.
    let x = floor.width / 2, y = floor.depth / 2;
    while (items.value.some((i) => Math.abs(i.x - x) < 0.5 && Math.abs(i.y - y) < 0.5)) { x += 0.5; y += 0.5; }
    const item = { id: null, _key: `n${++keySeq}`, code: nextCode(kind), name: '', kind, section_id: null, x, y, rotation: 0, color: null, ...d };
    items.value.push(item);
    selectedKey.value = item._key;
}
function duplicate() {
    const s = selected.value;
    if (!s) return;
    const n = frontNormal(s);
    // The copy goes next to the original, along its length.
    const along = { x: n.y, y: -n.x };
    const copy = { ...s, id: null, _key: `n${++keySeq}`, code: nextCode(s.kind), x: clamp(s.x - along.x * s.width, 0, floor.width), y: clamp(s.y - along.y * s.width, 0, floor.depth) };
    items.value.push(copy);
    selectedKey.value = copy._key;
}
function remove() {
    if (!selected.value) return;
    items.value = items.value.filter((i) => i._key !== selectedKey.value);
    selectedKey.value = null;
}
function rotate() {
    if (selected.value) selected.value.rotation = (selected.value.rotation + 90) % 360;
}
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
const round = (v) => (snap.value ? Math.round(v * 4) / 4 : Math.round(v * 100) / 100);

// Dragging on the plan -------------------------------------------------------------------------
const svg = ref(null);
let drag = null;
function toPlan(e) {
    const pt = svg.value.createSVGPoint();
    pt.x = e.clientX;
    pt.y = e.clientY;
    const p = pt.matrixTransform(svg.value.getScreenCTM().inverse());
    return { x: p.x, y: p.y };
}
function startDrag(e, item) {
    selectedKey.value = item._key;
    const p = toPlan(e);
    drag = { item, dx: item.x - p.x, dy: item.y - p.y, moved: false };
    e.target.setPointerCapture?.(e.pointerId);
}
function startEntranceDrag(e) {
    const p = toPlan(e);
    drag = { entrance: true, dx: floor.entrance.x - p.x, dy: floor.entrance.y - p.y };
    e.target.setPointerCapture?.(e.pointerId);
}
function onMove(e) {
    if (!drag) return;
    const p = toPlan(e);
    if (drag.entrance) {
        floor.entrance.x = clamp(round(p.x + drag.dx), 0, floor.width);
        floor.entrance.y = clamp(round(p.y + drag.dy), 0, floor.depth);
        return;
    }
    drag.item.x = clamp(round(p.x + drag.dx), 0, floor.width);
    drag.item.y = clamp(round(p.y + drag.dy), 0, floor.depth);
}
function endDrag() { drag = null; }
function onPlanClick(e) {
    if (placingEntrance.value) {
        const p = toPlan(e);
        floor.entrance.x = clamp(round(p.x), 0, floor.width);
        floor.entrance.y = clamp(round(p.y), 0, floor.depth);
        placingEntrance.value = false;
        return;
    }
    if (e.target === svg.value || e.target.dataset.bg) selectedKey.value = null;
}

function onKey(e) {
    if (!selected.value || ['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement?.tagName)) return;
    const step = e.shiftKey ? 1 : 0.25;
    const s = selected.value;
    const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    if (moves[e.key]) { e.preventDefault(); s.x = clamp(s.x + moves[e.key][0], 0, floor.width); s.y = clamp(s.y + moves[e.key][1], 0, floor.depth); }
    else if (e.key === 'r' || e.key === 'R' || e.key === 'ر') rotate();
    else if (e.key === 'Delete') remove();
    else if (e.key === 'Escape') selectedKey.value = null;
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const corners = (s) => `translate(${s.x} ${s.y}) rotate(${s.rotation})`;
const fill = (s) => (s.kind === 'shelf' ? s.color || sectionColor(s.section_id) : s.kind === 'wall' ? '#94a3b8' : s.kind === 'desk' ? '#0f766e' : '#a16207');
const gridLines = computed(() => {
    const out = [];
    for (let x = 0; x <= floor.width; x++) out.push({ x1: x, y1: 0, x2: x, y2: floor.depth, major: x % 5 === 0 });
    for (let y = 0; y <= floor.depth; y++) out.push({ x1: 0, y1: y, x2: floor.width, y2: y, major: y % 5 === 0 });
    return out;
});
const fontSize = computed(() => Math.max(0.28, Math.min(0.5, Math.max(floor.width, floor.depth) / 45)));

// «صفوف تلقائية» ------------------------------------------------------------------------------
const gen = reactive({ open: false, rows: 3, perRow: 4, width: 2, aisle: 1.5, double: true, levels: 5, startLetter: 'A', sectionsByRow: true, replace: true });
function generate() {
    const depth = gen.double ? 0.9 : 0.5;
    const runLength = gen.perRow * gen.width;
    const total = gen.rows * depth + (gen.rows - 1) * gen.aisle;
    const x0 = Math.max(0.5, (floor.width - runLength) / 2) + gen.width / 2;
    const y0 = Math.max(1, (floor.depth - total) / 2 - 0.75) + depth / 2;
    const roots = props.sections.filter((s) => !s.parent_id);
    const pool = roots.length ? roots : props.sections;
    const fresh = [];
    if (gen.replace) items.value = items.value.filter((i) => i.kind !== 'shelf');
    for (let r = 0; r < gen.rows; r++) {
        const letter = String.fromCharCode(gen.startLetter.toUpperCase().charCodeAt(0) + r);
        for (let c = 0; c < gen.perRow; c++) {
            fresh.push({
                id: null, _key: `n${++keySeq}`, code: `${letter}${c + 1}`, name: '', kind: 'shelf',
                section_id: gen.sectionsByRow && pool.length ? pool[r % pool.length].id : null,
                x: round(x0 + c * gen.width), y: round(y0 + r * (depth + gen.aisle)), rotation: 0,
                width: gen.width, depth, levels: gen.levels, double_sided: gen.double, color: null,
            });
        }
    }
    const taken = new Set(items.value.map((i) => i.code.toLowerCase()));
    for (const f of fresh) if (taken.has(f.code.toLowerCase())) f.code = uniqueCode(f.code + '-');
    items.value.push(...fresh);
    // Make the room big enough for what was built.
    floor.width = Math.max(floor.width, Math.ceil(runLength + 2));
    floor.depth = Math.max(floor.depth, Math.ceil(total + 4));
    gen.open = false;
    selectedKey.value = null;
}

// Saving --------------------------------------------------------------------------------------
const saving = ref(false);
const errors = computed(() => page.props.errors ?? {});
const errorList = computed(() => Object.entries(errors.value).map(([k, v]) => {
    const m = k.match(/^shelves\.(\d+)\./);
    return m ? `${items.value[Number(m[1])]?.code ?? ''}: ${v}` : v;
}));
const justSaved = ref(false);
function save() {
    saving.value = true;
    router.put(route('library.manage.map.update'), { floor: { ...floor }, shelves: strip(items.value) }, {
        preserveScroll: true,
        onSuccess: () => { justSaved.value = true; setTimeout(() => (justSaved.value = false), 2500); },
        onFinish: () => (saving.value = false),
    });
}
function reset() {
    Object.assign(floor, JSON.parse(JSON.stringify(props.floor)));
    items.value = withKeys(props.shelves);
    selectedKey.value = null;
}
const shelfCount = computed(() => items.value.filter((i) => i.kind === 'shelf').length);
const preview = computed(() => items.value.map((i) => ({ ...i, id: i.id ?? i._key, section: sectionPath(i.section_id) })));
</script>

<template>
    <Head :title="t('library.map.edit', 'ترتيب الرفوف')" />

    <AppLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('library.map.edit', 'ترتيب الرفوف') }}</h2>
                <span class="text-sm text-gray-500">{{ t('library.map.count', ':n رف', { n: shelfCount }) }}</span>
                <a :href="route('library.manage.map.labels')" target="_blank" class="ms-auto inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                    <QrCodeIcon class="h-4 w-4" /> {{ t('library.map.qr_labels', 'ملصقات QR للرفوف') }}
                </a>
                <Link :href="route('library.map')" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                    <EyeIcon class="h-4 w-4" /> {{ t('library.map.as_reader', 'كما يراها القارئ') }}
                </Link>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <LibraryTabs />

            <!-- Toolbar -->
            <div class="flex flex-wrap items-center gap-2 rounded-2xl border border-gray-200 bg-white p-2 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex rounded-xl bg-gray-100 p-1 dark:bg-gray-900">
                    <button @click="view = 'plan'" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium" :class="view === 'plan' ? 'bg-white shadow dark:bg-gray-700' : 'text-gray-500'"><Squares2X2Icon class="h-4 w-4" /> {{ t('library.map.plan', 'المخطط') }}</button>
                    <button @click="view = '3d'" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium" :class="view === '3d' ? 'bg-white shadow dark:bg-gray-700' : 'text-gray-500'"><CubeIcon class="h-4 w-4" /> {{ t('library.map.view3d', 'ثلاثي الأبعاد') }}</button>
                </div>
                <span class="mx-1 h-6 w-px bg-gray-200 dark:bg-gray-700" />
                <button @click="add('shelf')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PlusIcon class="h-4 w-4" /> {{ t('library.map.add_shelf', 'رف') }}</button>
                <button @click="add('double')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PlusIcon class="h-4 w-4" /> {{ t('library.map.add_double', 'رف بوجهين') }}</button>
                <button @click="add('desk')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PlusIcon class="h-4 w-4" /> {{ t('library.map.kind.desk', 'مكتب الإعارة') }}</button>
                <button @click="add('table')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PlusIcon class="h-4 w-4" /> {{ t('library.map.kind.table', 'طاولة قراءة') }}</button>
                <button @click="add('wall')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PlusIcon class="h-4 w-4" /> {{ t('library.map.kind.wall', 'جدار') }}</button>
                <button @click="placingEntrance = !placingEntrance; view = 'plan'" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm" :class="placingEntrance ? 'bg-emerald-600 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"><MapPinIcon class="h-4 w-4" /> {{ t('library.map.entrance', 'المدخل') }}</button>
                <button @click="gen.open = true" class="inline-flex items-center gap-1.5 rounded-lg bg-[var(--accent-50)] px-3 py-1.5 text-sm font-medium text-[var(--accent-700)] hover:brightness-95 dark:bg-gray-700 dark:text-gray-100"><SparklesIcon class="h-4 w-4" /> {{ t('library.map.generate', 'صفوف تلقائية') }}</button>
            </div>

            <p v-if="placingEntrance" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">{{ t('library.map.place_entrance', 'اضغط على المخطط في مكان باب المكتبة.') }}</p>

            <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
                <!-- Plan or 3D -->
                <div class="relative h-[62dvh] min-h-[380px] overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <div v-show="view === 'plan'" class="h-full w-full overflow-auto">
                        <svg ref="svg" :viewBox="`-0.6 -0.6 ${floor.width + 1.2} ${floor.depth + 1.2}`" class="block touch-none select-none" :class="placingEntrance ? 'cursor-crosshair' : ''"
                            :style="{ width: `${zoom * 100}%`, height: `${zoom * 100}%` }" preserveAspectRatio="xMidYMid meet"
                            @pointermove="onMove" @pointerup="endDrag" @pointercancel="endDrag" @click="onPlanClick">
                            <rect data-bg="1" x="0" y="0" :width="floor.width" :height="floor.depth" class="fill-[#f8f5ef] dark:fill-gray-900" />
                            <line v-for="(g, i) in gridLines" :key="i" v-bind="g" data-bg="1" :stroke-width="g.major ? 0.03 : 0.012" class="stroke-gray-300 dark:stroke-gray-700" />
                            <rect x="0" y="0" :width="floor.width" :height="floor.depth" fill="none" stroke-width="0.12" class="stroke-gray-400 dark:stroke-gray-500" />

                            <g v-for="s in items" :key="s._key" :transform="corners(s)" class="cursor-grab active:cursor-grabbing" @pointerdown.stop="startDrag($event, s)">
                                <rect :x="-s.width / 2" :y="-s.depth / 2" :width="s.width" :height="s.depth" :rx="s.kind === 'table' ? 0.1 : 0.04"
                                    :fill="fill(s)" :fill-opacity="s.kind === 'shelf' ? 0.85 : 0.7"
                                    :stroke="s._key === selectedKey ? '#f59e0b' : 'rgba(0,0,0,0.25)'" :stroke-width="s._key === selectedKey ? 0.09 : 0.025" />
                                <!-- Front face: where readers stand. -->
                                <line v-if="s.kind === 'shelf'" :x1="-s.width / 2 + 0.05" :y1="s.depth / 2" :x2="s.width / 2 - 0.05" :y2="s.depth / 2" stroke="#fff" stroke-width="0.07" stroke-dasharray="0.15 0.08" />
                                <line v-if="s.kind === 'shelf' && s.double_sided" :x1="-s.width / 2 + 0.05" :y1="-s.depth / 2" :x2="s.width / 2 - 0.05" :y2="-s.depth / 2" stroke="#fff" stroke-width="0.07" stroke-dasharray="0.15 0.08" />
                                <text v-if="s.kind !== 'wall'" :transform="`rotate(${-s.rotation})`" text-anchor="middle" dominant-baseline="central" :font-size="fontSize" font-weight="700" fill="#fff" style="paint-order: stroke" stroke="rgba(0,0,0,0.35)" :stroke-width="fontSize / 8">{{ s.code }}</text>
                            </g>

                            <g :transform="`translate(${floor.entrance.x} ${floor.entrance.y})`" class="cursor-grab" @pointerdown.stop="startEntranceDrag">
                                <circle r="0.45" fill="#10b981" stroke="#fff" stroke-width="0.08" />
                                <text text-anchor="middle" dominant-baseline="central" font-size="0.32" font-weight="700" fill="#fff">{{ t('library.map.door', 'باب') }}</text>
                            </g>
                        </svg>
                    </div>
                    <LibraryMap3D v-if="view === '3d'" :floor="floor" :shelves="preview" :highlight="selected ? { id: selected.id ?? selected._key } : null" @pick="(id) => (selectedKey = items.find((i) => (i.id ?? i._key) === id)?._key ?? null)" />

                    <div v-if="view === 'plan'" class="absolute bottom-3 end-3 flex gap-1.5">
                        <button @click="zoom = Math.max(1, zoom - 0.5)" class="rounded-lg bg-white/95 p-2 shadow ring-1 ring-black/5 dark:bg-gray-700"><MagnifyingGlassMinusIcon class="h-5 w-5" /></button>
                        <button @click="zoom = Math.min(4, zoom + 0.5)" class="rounded-lg bg-white/95 p-2 shadow ring-1 ring-black/5 dark:bg-gray-700"><MagnifyingGlassPlusIcon class="h-5 w-5" /></button>
                    </div>
                    <p v-if="view === 'plan' && !items.length" class="pointer-events-none absolute inset-x-0 top-1/3 text-center text-sm text-gray-500">{{ t('library.map.empty_plan', 'ابدأ بـ «صفوف تلقائية» أو أضف رفاً من الشريط أعلاه.') }}</p>
                </div>

                <!-- Properties -->
                <aside class="space-y-4">
                    <section v-if="selected" class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded-full" :style="{ background: fill(selected) }" />
                            <h3 class="font-semibold">{{ kindLabel(selected.kind) }}</h3>
                            <div class="ms-auto flex gap-1">
                                <button @click="rotate" class="rounded-lg p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('library.map.rotate', 'تدوير 90°')"><ArrowPathRoundedSquareIcon class="h-5 w-5" /></button>
                                <button @click="duplicate" class="rounded-lg p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('library.map.duplicate', 'تكرار بجانبه')"><DocumentDuplicateIcon class="h-5 w-5" /></button>
                                <button @click="remove" class="rounded-lg p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" :title="t('common.delete', 'حذف')"><TrashIcon class="h-5 w-5" /></button>
                            </div>
                        </div>
                        <label class="block text-sm">
                            <span class="text-gray-600 dark:text-gray-300">{{ selected.kind === 'shelf' ? t('library.map.code', 'رمز الرف (يُكتب في حقل «الرف» للنسخة)') : t('library.map.label', 'الاسم المختصر') }}</span>
                            <input v-model.trim="selected.code" maxlength="30" dir="auto" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        </label>
                        <label v-if="selected.kind === 'shelf'" class="block text-sm">
                            <span class="text-gray-600 dark:text-gray-300">{{ t('library.field.section', 'القسم') }}</span>
                            <select v-model="selected.section_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                                <option :value="null">{{ t('library.map.no_section', 'بدون قسم') }}</option>
                                <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.path }}</option>
                            </select>
                            <span class="mt-1 block text-xs text-gray-500">{{ t('library.map.section_hint', 'كتب هذا القسم التي لم يُكتب رفها تظهر على هذا الرف.') }}</span>
                        </label>
                        <label class="block text-sm">
                            <span class="text-gray-600 dark:text-gray-300">{{ t('library.map.name', 'وصف (اختياري)') }}</span>
                            <input v-model="selected.name" maxlength="255" dir="auto" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        </label>
                        <div class="grid grid-cols-2 gap-2 text-sm">
                            <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.length', 'الطول (م)') }}</span><input v-model.number="selected.width" type="number" step="0.1" min="0.3" max="30" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                            <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.depth', 'العمق (م)') }}</span><input v-model.number="selected.depth" type="number" step="0.05" min="0.1" max="10" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                            <label v-if="selected.kind === 'shelf'"><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.levels', 'عدد الطبقات') }}</span><input v-model.number="selected.levels" type="number" min="1" max="10" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                            <label class="flex items-end gap-2 pb-2" v-if="selected.kind === 'shelf'"><input v-model="selected.double_sided" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.double', 'بوجهين') }}</label>
                        </div>
                        <div v-if="selected.kind === 'shelf'" class="flex items-center gap-2 text-sm">
                            <span class="text-gray-600 dark:text-gray-300">{{ t('library.map.color', 'اللون') }}</span>
                            <input type="color" :value="selected.color || sectionColor(selected.section_id)" @input="selected.color = $event.target.value" class="h-8 w-10 cursor-pointer rounded border border-gray-300 dark:border-gray-600" />
                            <button v-if="selected.color" @click="selected.color = null" class="text-xs text-gray-500 underline">{{ t('library.map.section_color', 'لون القسم') }}</button>
                        </div>
                        <p class="text-xs leading-5 text-gray-500">{{ t('library.map.keys_hint', 'اسحب للتحريك · الأسهم للتحريك الدقيق · R للتدوير · Delete للحذف. الخط المتقطع هو وجه الرف الذي يقف أمامه القارئ.') }}</p>
                    </section>

                    <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="font-semibold">{{ t('library.map.room', 'القاعة') }}</h3>
                        <div class="grid grid-cols-2 gap-2">
                            <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.room_width', 'العرض (م)') }}</span><input v-model.number="floor.width" type="number" min="4" max="200" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                            <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.room_depth', 'الطول (م)') }}</span><input v-model.number="floor.depth" type="number" min="4" max="200" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                        </div>
                        <label class="flex items-center gap-2"><input v-model="snap" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.snap', 'محاذاة كل 25 سم') }}</label>
                        <p class="text-xs leading-5 text-gray-500">{{ t('library.map.copy_hint', 'لربط كتاب برف: اكتب رمز الرف في حقل «الرف» للنسخة، مثل A3، أو A3-2 للطبقة الثانية من الأسفل.') }}</p>
                    </section>

                    <section v-if="items.length" class="rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <h3 class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold dark:border-gray-700">{{ t('library.map.all', 'كل العناصر') }}</h3>
                        <div class="max-h-60 overflow-y-auto">
                            <button v-for="s in items" :key="s._key" @click="selectedKey = s._key" class="flex w-full items-center gap-2 px-4 py-1.5 text-start text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50" :class="s._key === selectedKey ? 'bg-amber-50 dark:bg-amber-900/20' : ''">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: fill(s) }" />
                                <span class="font-medium" dir="auto">{{ s.code }}</span>
                                <span class="truncate text-xs text-gray-500">{{ s.kind === 'shelf' ? sectionPath(s.section_id) : kindLabel(s.kind) }}</span>
                            </button>
                        </div>
                    </section>
                </aside>
            </div>

            <!-- Save bar -->
            <div class="sticky bottom-0 z-20 -mx-4 border-t border-gray-200 bg-white/95 backdrop-blur sm:-mx-6 dark:border-gray-700 dark:bg-gray-900/95">
                <div class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
                    <p class="flex min-w-0 flex-1 items-center gap-1.5 text-sm">
                        <template v-if="errorList.length"><ExclamationCircleIcon class="h-5 w-5 shrink-0 text-red-500" /><span class="truncate text-red-600">{{ errorList[0] }}</span></template>
                        <template v-else-if="dirty"><span class="h-2 w-2 rounded-full bg-amber-500" /><span class="font-medium text-amber-600">{{ t('settings.unsaved', 'تغييرات غير محفوظة') }}</span></template>
                        <template v-else-if="justSaved"><CheckCircleIcon class="h-5 w-5 text-emerald-500" /><span class="text-emerald-600">{{ t('settings.saved', 'تم الحفظ') }}</span></template>
                    </p>
                    <button v-if="dirty" @click="reset" class="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"><ArrowUturnRightIcon class="h-4 w-4" /> {{ t('settings.discard', 'تراجع') }}</button>
                    <button @click="save" :disabled="saving || !dirty" class="rounded-lg bg-[var(--accent-600)] px-5 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50">{{ t('library.map.save', 'حفظ الخريطة') }}</button>
                </div>
            </div>
        </div>

        <Modal :show="gen.open" @close="gen.open = false" max-width="lg">
            <div class="space-y-4 p-6">
                <div>
                    <h3 class="flex items-center gap-2 text-lg font-semibold"><SparklesIcon class="h-5 w-5 text-[var(--accent-600)]" /> {{ t('library.map.generate', 'صفوف تلقائية') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ t('library.map.generate_text', 'تُبنى صفوف رفوف متوازية بممرات بينها، وترقّم A1، A2… B1… وبعدها تقدر تسحب أي رف لمكانه.') }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_rows', 'عدد الصفوف') }}</span><input v-model.number="gen.rows" type="number" min="1" max="26" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_per_row', 'رفوف في كل صف') }}</span><input v-model.number="gen.perRow" type="number" min="1" max="30" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_width', 'طول الرف (م)') }}</span><input v-model.number="gen.width" type="number" step="0.1" min="0.5" max="5" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_aisle', 'عرض الممر (م)') }}</span><input v-model.number="gen.aisle" type="number" step="0.1" min="0.8" max="5" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.levels', 'عدد الطبقات') }}</span><input v-model.number="gen.levels" type="number" min="1" max="10" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_letter', 'أول حرف') }}</span><input v-model="gen.startLetter" maxlength="1" dir="ltr" class="mt-1 w-full rounded-lg border-gray-300 uppercase dark:border-gray-600 dark:bg-gray-700" /></label>
                </div>
                <div class="space-y-2 text-sm">
                    <label class="flex items-center gap-2"><input v-model="gen.double" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.gen_double', 'رفوف بوجهين (كتب من الجهتين)') }}</label>
                    <label class="flex items-center gap-2"><input v-model="gen.sectionsByRow" type="checkbox" class="rounded border-gray-300" :disabled="!sections.length" /> {{ t('library.map.gen_sections', 'خصص كل صف لقسم بالترتيب') }}</label>
                    <label class="flex items-center gap-2"><input v-model="gen.replace" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.gen_replace', 'استبدل الرفوف الحالية (المكاتب والجدران تبقى)') }}</label>
                </div>
                <div class="flex justify-end gap-2">
                    <button @click="gen.open = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('common.cancel', 'إلغاء') }}</button>
                    <button @click="generate" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm font-medium text-white">{{ t('library.map.gen_build', 'ابنِ الصفوف') }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
