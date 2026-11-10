<script setup>
// «ترتيب الرفوف» (2026-11-06, by request): lay out the reading room on a top-down plan. Drag
// bookcases, rotate them, give each a code and a section; «صفوف تلقائية» builds a whole room in
// one step. The 3D tab shows the same room as readers see it.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import LibraryMap3D from '@/Components/Library/Map/LibraryMap3D.vue';
import { frontNormal, snapToWall, outline, footprint, findRoute, linkedStairs, HALL_KINDS, storeyOf } from '@/Components/Library/Map/route.js';
import { sectionColor } from '@/Components/Library/Map/colors.js';
import { wallPolygons, wallSpot, autoStickers } from '@/Components/Library/Map/stickers.js';
import { useTrans } from '@/Composables/useTrans';
import {
    PlusIcon, TrashIcon, ArrowPathRoundedSquareIcon, DocumentDuplicateIcon, Squares2X2Icon, CubeIcon,
    MapPinIcon, SparklesIcon, CheckCircleIcon, ExclamationCircleIcon, ArrowUturnRightIcon, EyeIcon, MagnifyingGlassPlusIcon, MagnifyingGlassMinusIcon, QrCodeIcon, ShieldCheckIcon, PencilIcon, HomeModernIcon, BuildingOffice2Icon, ArrowsRightLeftIcon, ChevronDoubleUpIcon, RectangleGroupIcon, Square2StackIcon, RectangleStackIcon,
    PhotoIcon, ClipboardDocumentCheckIcon, ArrowUturnLeftIcon, ArrowsPointingOutIcon, XMarkIcon, InformationCircleIcon,
    LockClosedIcon, LockOpenIcon, ArrowsPointingInIcon, PencilSquareIcon, PrinterIcon, WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ floor: Object, shelves: Array, sections: Array });
const page = usePage();

let keySeq = 0;
const withKeys = (list) => list.map((s) => ({ ...s, _key: s.id ? `id${s.id}` : `n${++keySeq}` }));
const strip = (list) => list.map(({ _key, section, ...rest }) => rest);
const withFloorDefaults = (f) => ({ storeys: ['الطابق الأرضي'], separate_halls: false, backgrounds: [], ...JSON.parse(JSON.stringify(f)) });
const floor = reactive(withFloorDefaults(props.floor));
const items = ref(withKeys(props.shelves));
const snapshot = (f, list) => JSON.stringify({ f, list: strip(list) });
const original = ref(snapshot(withFloorDefaults(props.floor), props.shelves));
watch(() => [props.floor, props.shelves], () => {
    // Saved: the history starts again (undoing past a save would lose the saved ids, which the
    // printed QR stickers point to).
    restoring = true;
    clearTimeout(pending);
    pending = null;
    undoStack.value = [];
    redoStack.value = [];
    nextTick(() => { lastState = state(); restoring = false; });
    Object.assign(floor, withFloorDefaults(props.floor));
    items.value = withKeys(props.shelves);
    original.value = snapshot(withFloorDefaults(props.floor), props.shelves);
    selectedKey.value = null;
});
const dirty = computed(() => snapshot(floor, items.value) !== original.value);

// «تراجع / إعادة» (2026-11-06, by request): every change can be undone with Ctrl+Z (or the
// buttons in the save bar) and redone with Ctrl+Y. A drag counts as one change.
const state = () => JSON.stringify({ f: floor, list: items.value, sel: selectedKey.value });
const undoStack = ref([]);
const redoStack = ref([]);
let lastState = null;
let restoring = false;
let pending = null;
function commit() {
    clearTimeout(pending);
    pending = null;
    const now = state();
    if (lastState !== null && now !== lastState) {
        undoStack.value.push(lastState);
        if (undoStack.value.length > 100) undoStack.value.shift();
        redoStack.value = [];
    }
    lastState = now;
}
watch(() => JSON.stringify({ f: floor, list: items.value }), () => {
    if (restoring) return;
    clearTimeout(pending);
    pending = setTimeout(commit, drag ? 600 : 300);
});
function restore(json) {
    restoring = true;
    const { f, list, sel } = JSON.parse(json);
    Object.keys(floor).forEach((k) => delete floor[k]);
    Object.assign(floor, f);
    items.value = list;
    selectedKey.value = list.some((i) => i._key === sel) ? sel : null;
    lastState = json;
    nextTick(() => (restoring = false));
}
function undo() {
    if (pending) commit();
    if (!undoStack.value.length) return;
    redoStack.value.push(state());
    restore(undoStack.value.pop());
}
function redo() {
    if (!redoStack.value.length) return;
    undoStack.value.push(state());
    restore(redoStack.value.pop());
}

const view = ref('plan');
const selectedKey = ref(null);
const selected = computed(() => items.value.find((i) => i._key === selectedKey.value) ?? null);
const sectionPath = (id) => props.sections.find((s) => s.id === id)?.path ?? '';
const snap = ref(true);
const zoom = ref(1);
// «المحرر على الهاتف» (2026-11-06, by request): pinch with two fingers to zoom, drag the empty
// floor (one finger or the mouse) or two fingers to move round the plan, Ctrl + wheel to zoom.
const planBox = ref(null);
const ZOOM_MAX = 6;
/** Zooms to `z`, keeping the plan point under (mx, my) (pixels in the box) where it is. */
function zoomAt(z, mx = null, my = null) {
    const box = planBox.value;
    const next = Math.min(ZOOM_MAX, Math.max(1, z));
    if (!box || next === zoom.value) { zoom.value = next; return; }
    mx ??= box.clientWidth / 2;
    my ??= box.clientHeight / 2;
    const k = next / zoom.value, sl = box.scrollLeft, st = box.scrollTop;
    zoom.value = next;
    nextTick(() => { box.scrollLeft = (sl + mx) * k - mx; box.scrollTop = (st + my) * k - my; });
}
// «تحديد عدة رفوف» (2026-11-06, by request): tap several bookcases (or drag a box round them)
// and give them a section, levels or codes all at once, move or delete them together.
const multiMode = ref(false);
const multi = ref(new Set());
const multiKind = (i) => !isHallKind(i) && i.kind !== 'door';
const multiItems = computed(() => items.value.filter((i) => multi.value.has(i._key)));
const multiShelves = computed(() => multiItems.value.filter((i) => i.kind === 'shelf'));
function toggleMulti(item) {
    const next = new Set(multi.value);
    next.has(item._key) ? next.delete(item._key) : next.add(item._key);
    multi.value = next;
    selectedKey.value = null;
}
function selectHallShelves(hall) {
    multi.value = new Set(onStorey.value.filter((i) => i.kind === 'shelf' && inOutline(i, hall)).map((i) => i._key));
    multiMode.value = true;
    selectedKey.value = null;
}
const bulk = reactive({ section: '', levels: '', prefix: 'A', start: 1 });
function bulkSet(field, value) {
    for (const s of multiShelves.value) s[field] = value;
}
/** Codes in reading order: hall by hall, then row by row (front row first) along each, A1, A2… */
function bulkNumber() {
    const list = [...multiShelves.value];
    if (!list.length) return;
    const halls = onStorey.value.filter(isHallKind);
    const hallOf = (i) => halls.findIndex((h) => inOutline(i, h));
    const groups = new Map();
    for (const s of list) { const h = hallOf(s); groups.has(h) || groups.set(h, []); groups.get(h).push(s); }
    const mid = (g) => ({ x: g.reduce((t, i) => t + i.x, 0) / g.length, y: g.reduce((t, i) => t + i.y, 0) / g.length });
    const ordered = [...groups.values()].sort((A, B) => { const a = mid(A), b = mid(B); return Math.abs(a.y - b.y) > 2 ? a.y - b.y : a.x - b.x; });
    const others = new Set(items.value.filter((i) => !multi.value.has(i._key)).map((i) => i.code.toLowerCase()));
    let n = Math.max(1, Number(bulk.start) || 1);
    for (const g of ordered) {
        const a = ((g[0].rotation || 0) * Math.PI) / 180, c = Math.cos(a), sn = Math.sin(a);
        const pts = g.map((i) => ({ i, u: i.x * c + i.y * sn, v: -i.x * sn + i.y * c })).sort((p, q) => p.v - q.v);
        const rows = [];
        for (const pt of pts) (rows.length && pt.v - rows.at(-1).at(-1).v < 0.4 ? rows.at(-1) : rows[rows.push([]) - 1]).push(pt);
        for (const row of rows) for (const { i } of row.sort((p, q) => p.u - q.u)) {
            while (others.has(`${bulk.prefix}${n}`.toLowerCase())) n++;
            i.code = `${bulk.prefix}${n++}`;
        }
    }
}
function bulkRemove() {
    items.value = items.value.filter((i) => !multi.value.has(i._key));
    multi.value = new Set();
}
function bulkRotate(deg) {
    // Turned together round their middle.
    const list = multiItems.value;
    if (!list.length) return;
    const cx = list.reduce((t, i) => t + i.x, 0) / list.length, cy = list.reduce((t, i) => t + i.y, 0) / list.length;
    const a = (deg * Math.PI) / 180, c = Math.cos(a), sn = Math.sin(a);
    for (const i of list) {
        const dx = i.x - cx, dy = i.y - cy;
        i.x = +(cx + dx * c - dy * sn).toFixed(2);
        i.y = +(cy + dx * sn + dy * c).toFixed(2);
        i.rotation = ((((i.rotation || 0) + deg) % 360) + 360) % 360;
    }
}

// «مجموعة» (2026-11-10, by request, as on the exam hall map): bookcases grouped together are chosen
// with one tap and moved, turned, copied or deleted together; the group is saved with the map.
// Alt + click picks one of them alone.
const newGroupKey = () => `g${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
const groupOf = (it) => (it?.group_key ? onStorey.value.filter((o) => o.group_key === it.group_key) : []);
const multiGroupKeys = computed(() => new Set(multiItems.value.map((i) => i.group_key ?? null)));
const multiIsOneGroup = computed(() => multiItems.value.length > 1 && multiGroupKeys.value.size === 1 && !multiGroupKeys.value.has(null));
const multiHasGroup = computed(() => multiItems.value.some((i) => i.group_key));
function selectGroup(it) {
    multi.value = new Set(groupOf(it).map((i) => i._key));
    selectedKey.value = null;
}
function groupSelected() {
    if (multiItems.value.length < 2) return;
    const key = newGroupKey();
    multiItems.value.forEach((i) => (i.group_key = key));
}
function ungroupSelected() {
    multiItems.value.forEach((i) => (i.group_key = null));
}
// «تدوير المجموعة»: a round handle above the selection turns all of it round its middle, 15° at a
// time (Shift: 1°).
const multiBox = computed(() => {
    const list = multiItems.value;
    if (list.length < 2 || tool.value || drawing.value || calib.value) return null;
    let x0 = Infinity, x1 = -Infinity, y0 = Infinity, y1 = -Infinity;
    for (const i of list) {
        const a = ((i.rotation || 0) * Math.PI) / 180, c = Math.abs(Math.cos(a)), sn = Math.abs(Math.sin(a));
        const hw = (i.width * c + i.depth * sn) / 2, hd = (i.width * sn + i.depth * c) / 2;
        x0 = Math.min(x0, i.x - hw); x1 = Math.max(x1, i.x + hw); y0 = Math.min(y0, i.y - hd); y1 = Math.max(y1, i.y + hd);
    }
    const pad = 0.3;
    return { x0: x0 - pad, x1: x1 + pad, y0: y0 - pad, y1: y1 + pad, cx: (x0 + x1) / 2, cy: (y0 + y1) / 2, locked: list.some((i) => !canMove(i)) };
});
const multiTurn = ref(null); // the angle shown while turning
function startMultiRotate(e) {
    const b = multiBox.value;
    if (!b || b.locked) return;
    const p = toPlan(e);
    drag = { multiRotate: true, cx: b.cx, cy: b.cy, from: Math.atan2(p.y - b.cy, p.x - b.cx), base: multiItems.value.map((i) => ({ i, x: i.x, y: i.y, r: i.rotation || 0 })) };
    multiTurn.value = 0;
    e.target.setPointerCapture?.(e.pointerId);
}
function multiRotateTo(p, fine) {
    const step = fine ? 1 : 15;
    let deg = ((Math.atan2(p.y - drag.cy, p.x - drag.cx) - drag.from) * 180) / Math.PI;
    deg = Math.round(deg / step) * step;
    deg = ((deg % 360) + 540) % 360 - 180;
    const a = (deg * Math.PI) / 180, c = Math.cos(a), sn = Math.sin(a);
    for (const { i, x, y, r } of drag.base) {
        const dx = x - drag.cx, dy = y - drag.cy;
        i.x = +clamp(drag.cx + dx * c - dy * sn, 0, floor.width).toFixed(2);
        i.y = +clamp(drag.cy + dx * sn + dy * c, 0, floor.depth).toFixed(2);
        i.rotation = (((r + deg) % 360) + 360) % 360;
    }
    multiTurn.value = deg;
}
/** Each selected bookcase turned round where it stands: its books to the other side. */
function bulkFlip() {
    for (const i of multiItems.value.filter(canMove)) i.rotation = ((((i.rotation || 0) + 180) % 360) + 360) % 360;
}
/** A copy of the selection beside it (or below it), with new codes. */
function bulkDuplicate() {
    const list = multiItems.value;
    if (!list.length) return;
    const xs = list.map((i) => i.x), ys = list.map((i) => i.y);
    const x0 = Math.min(...xs), x1 = Math.max(...xs), y0 = Math.min(...ys), y1 = Math.max(...ys);
    const w = x1 - x0 + 1.6, d = y1 - y0 + 1.6;
    const [dx, dy] = x1 + w <= floor.width ? [w, 0] : y1 + d <= floor.depth ? [0, d] : y0 - d >= 0 ? [0, -d] : [-w, 0];
    const keys = new Map();
    const copies = list.map((i) => {
        const { _key, section, ...rest } = i;
        const g = i.group_key ? (keys.get(i.group_key) ?? keys.set(i.group_key, newGroupKey()).get(i.group_key)) : null;
        const copy = { ...JSON.parse(JSON.stringify(rest)), id: null, _key: `n${++keySeq}`, locked: false, group_key: g,
            x: clamp(+(i.x + dx).toFixed(2), 0, floor.width), y: clamp(+(i.y + dy).toFixed(2), 0, floor.depth) };
        copy.code = uniqueCode(`${String(i.code).replace(/\d+$/, '')}`, 1);
        items.value.push(copy);
        return copy;
    });
    multi.value = new Set(copies.map((c) => c._key));
}

// «مثل كانفا» (2026-11-07, by request): handles round the selected hall, bookcase or wall to make it
// bigger or smaller (corners and sides) and a round handle above it to turn it. «قفل» stops a
// hall, bookcase or the whole plan from being moved by mistake.
const boxSize = reactive({ w: 0, h: 0 });
let boxObs = null;
onMounted(() => {
    boxObs = new ResizeObserver(([en]) => { boxSize.w = en.contentRect.width; boxSize.h = en.contentRect.height; });
    if (planBox.value) boxObs.observe(planBox.value);
});
onBeforeUnmount(() => boxObs?.disconnect());
/** Metres that one screen pixel covers now, so handles stay the same size on screen. */
const mpp = computed(() => {
    const k = Math.min(boxSize.w / (floor.width + 1.2), boxSize.h / (floor.depth + 1.2)) * zoom.value;
    return k > 0 ? 1 / k : 0.05;
});
const coarse = typeof window !== 'undefined' && window.matchMedia?.('(pointer: coarse)').matches;
const hR = computed(() => mpp.value * (coarse ? 11 : 6.5));
const lockAll = ref(false);
try { lockAll.value = localStorage.getItem('library.map.lock_all') === '1'; } catch {}
watch(lockAll, (v) => { try { localStorage.setItem('library.map.lock_all', v ? '1' : '0'); } catch {} });
const canMove = (it) => !lockAll.value && !it?.locked;
const lockedNote = ref('');
let lockedTimer = null;
function flashLocked(it) {
    lockedNote.value = lockAll.value ? t('library.map.locked_all_note', 'التحريك مقفل. افتح القفل من شريط الأدوات للتعديل.') : t('library.map.locked_note', ':code مقفل. افتح القفل من لوحته للتحريك.', { code: it.code });
    clearTimeout(lockedTimer);
    lockedTimer = setTimeout(() => (lockedNote.value = ''), 2600);
}
/** The selected item's box, in its own (unturned) frame. */
const handleBox = computed(() => {
    const it = selected.value;
    if (!it || multi.value.size || ['door', 'qr'].includes(it.kind) || tool.value || drawing.value || calib.value) return null;
    if (it.points?.length >= 3) {
        const xs = it.points.map((q) => q[0]), ys = it.points.map((q) => q[1]);
        return { x0: Math.min(...xs), x1: Math.max(...xs), y0: Math.min(...ys), y1: Math.max(...ys), locked: !canMove(it) };
    }
    return { x0: -it.width / 2, x1: it.width / 2, y0: -it.depth / 2, y1: it.depth / 2, locked: !canMove(it) };
});
const HANDLES = [[-1, -1], [0, -1], [1, -1], [1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0]];
const shownHandles = computed(() => {
    const b = handleBox.value;
    if (!b) return [];
    const wide = b.x1 - b.x0 > hR.value * 5, deep = b.y1 - b.y0 > hR.value * 5;
    return HANDLES.filter(([hx, hy]) => (hx || wide) && (hy || deep)).map(([hx, hy]) => ({
        hx, hy,
        x: hx < 0 ? b.x0 : hx > 0 ? b.x1 : (b.x0 + b.x1) / 2,
        y: hy < 0 ? b.y0 : hy > 0 ? b.y1 : (b.y0 + b.y1) / 2,
        cursor: hx && hy ? (hx === hy ? 'nwse-resize' : 'nesw-resize') : hx ? 'ew-resize' : 'ns-resize',
    }));
});
const rotating = ref(false);
function startResize(e, h) {
    const it = selected.value;
    if (!it || !canMove(it)) return;
    drag = { resize: [h.hx, h.hy], item: it, start: { x: it.x, y: it.y, points: it.points?.length >= 3 ? it.points.map((q) => [...q]) : null, box: { ...handleBox.value } } };
    e.target.setPointerCapture?.(e.pointerId);
}
function startRotate(e) {
    const it = selected.value;
    if (!it || !canMove(it)) return;
    drag = { rotate: true, item: it };
    rotating.value = true;
    e.target.setPointerCapture?.(e.pointerId);
}
function resizeTo(p, keepShape) {
    const { item: it, start: st, resize: [hx, hy] } = drag;
    const a = ((it.rotation || 0) * Math.PI) / 180, c = Math.cos(a), sn = Math.sin(a);
    const dx = p.x - st.x, dy = p.y - st.y;
    const lx = dx * c + dy * sn, ly = -dx * sn + dy * c;
    const b = st.box, bw = b.x1 - b.x0, bd = b.y1 - b.y0;
    const minW = st.points ? 0.5 : 0.3, minD = st.points ? 0.5 : 0.1;
    const sl = (v) => (snap.value ? Math.round(v * 20) / 20 : Math.round(v * 100) / 100); // 5 cm steps
    let { x0, x1, y0, y1 } = b;
    if (hx > 0) x1 = Math.max(x0 + minW, sl(lx));
    if (hx < 0) x0 = Math.min(x1 - minW, sl(lx));
    if (hy > 0) y1 = Math.max(y0 + minD, sl(ly));
    if (hy < 0) y0 = Math.min(y1 - minD, sl(ly));
    if (keepShape && hx && hy) {
        // Shift on a corner keeps the shape, like Canva.
        const k = Math.max((x1 - x0) / bw, (y1 - y0) / bd);
        if (hx > 0) x1 = x0 + bw * k; else x0 = x1 - bw * k;
        if (hy > 0) y1 = y0 + bd * k; else y0 = y1 - bd * k;
    }
    const cx = (x0 + x1) / 2, cy = (y0 + y1) / 2;
    if (st.points) {
        const sx = (x1 - x0) / bw, sy = (y1 - y0) / bd;
        it.points = st.points.map(([px, py]) => [+(x0 + (px - b.x0) * sx - cx).toFixed(2), +(y0 + (py - b.y0) * sy - cy).toFixed(2)]);
    }
    it.width = +(x1 - x0).toFixed(2);
    it.depth = +(y1 - y0).toFixed(2);
    it.x = +(st.x + cx * c - cy * sn).toFixed(2);
    it.y = +(st.y + cx * sn + cy * c).toFixed(2);
}
function rotateTo(p, fine) {
    const it = drag.item;
    const ang = (Math.atan2(p.y - it.y, p.x - it.x) * 180) / Math.PI + 90;
    const step = fine ? 1 : 15;
    setRotation(it, Math.round(ang / step) * step);
}
function toggleLock(it) {
    it.locked = !it.locked;
}

// «ملء الشاشة» (2026-11-07, by request): the editor takes the whole screen, without the side bar
// and the top bar, so the plan has all the room there is.
const full = ref(false);

// Toolbar groups (2026-11-07, by request): fewer buttons at once; the last group is remembered.
const toolGroups = [
    { key: 'build', label: t('library.map.group_build', 'البناء'), icon: BuildingOffice2Icon },
    { key: 'furniture', label: t('library.map.group_furniture', 'الرفوف'), icon: RectangleStackIcon },
    { key: 'stickers', label: t('library.map.group_stickers', 'الملصقات'), icon: QrCodeIcon },
    { key: 'tools', label: t('library.map.group_tools', 'أدوات'), icon: WrenchScrewdriverIcon },
];
const toolGroup = ref((() => { try { return localStorage.getItem('library.map.toolGroup') || 'furniture'; } catch { return 'furniture'; } })());
watch(toolGroup, (v) => { try { localStorage.setItem('library.map.toolGroup', v); } catch {} });
function toggleFull() {
    full.value = !full.value;
    try {
        if (full.value && !document.fullscreenElement) document.documentElement.requestFullscreen?.()?.catch?.(() => {});
        else if (!full.value && document.fullscreenElement) document.exitFullscreen?.()?.catch?.(() => {});
    } catch {}
}
function onFullChange() {
    if (!document.fullscreenElement && full.value) full.value = false;
}
onMounted(() => document.addEventListener('fullscreenchange', onFullChange));
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', onFullChange));

// «المقاس عند المؤشر» (2026-11-07, by request): while resizing, turning or dragging, the size,
// angle or place shows beside the pointer.
const tip = ref(null); // { cx, cy, text }
const m2 = (v) => (+v).toFixed(2);
// The 3D view in the editor (2026-11-07, by request): drag furniture there too.
const byPreviewId = (id) => items.value.find((i) => (i.id ?? i._key) === id) ?? null;
function on3dMove(id, x, y) {
    const it = byPreviewId(id);
    if (!it || !canMove(it)) return;
    it.x = x;
    it.y = y;
    selectedKey.value = it._key;
}
function on3dDragging(d) {
    tip.value = d ? { cx: d.cx, cy: d.cy, text: `x ${m2(d.x)} · y ${m2(d.y)} م` } : null;
}

// «قائمة الزر الأيمن» (2026-11-07, by request): right click (or a long press on a phone) on any
// item opens its own options.
const ctx = ref(null); // { x, y, key }
const ctxItem = computed(() => (ctx.value ? items.value.find((i) => i._key === ctx.value.key) ?? null : null));
const ctxMulti = computed(() => !!ctxItem.value && multi.value.size > 1 && multi.value.has(ctxItem.value._key));
function openCtx(e, item) {
    e.preventDefault?.();
    if (!item) return;
    if (!(multi.value.size && multi.value.has(item._key))) {
        if (item.group_key) selectGroup(item);
        else { multi.value = new Set(); selectedKey.value = item._key; }
    }
    const W = window.innerWidth, H = window.innerHeight;
    ctx.value = { x: Math.min(e.clientX, W - 236), y: Math.min(e.clientY, H - 340), key: item._key };
}
function on3dContext(id, cx, cy) {
    openCtx({ clientX: cx, clientY: cy }, byPreviewId(id));
}
// «إعادة التسمية» from the menu: the name is typed right there.
const renaming = reactive({ on: false, text: '', error: '' });
function startRename() {
    renaming.on = true;
    renaming.text = ctxItem.value?.code ?? '';
    renaming.error = '';
    nextTick(() => document.querySelector('[data-ctx] input')?.select());
}
function saveRename() {
    const it = ctxItem.value, text = renaming.text.trim();
    if (!it) return;
    if (!text) { renaming.error = t('library.map.rename_empty', 'اكتب اسماً.'); return; }
    if (text.length > 30) { renaming.error = t('library.map.rename_long', 'الاسم أطول من 30 حرفاً.'); return; }
    if (items.value.some((o) => o !== it && o.code.toLowerCase() === text.toLowerCase())) { renaming.error = t('library.map.rename_taken', 'هذا الاسم مستعمل لعنصر آخر.'); return; }
    it.code = text;
    renaming.on = false;
    ctx.value = null;
}
watch(ctx, () => (renaming.on = false));
function ctxDo(fn, keep = false) {
    const it = ctxItem.value;
    if (keep) { if (it) fn(it); return; }
    ctx.value = null;
    if (it) fn(it);
}
const needsMove = (fn) => (it) => (canMove(it) ? fn(it) : flashLocked(it));
const ctxActions = computed(() => {
    const it = ctxItem.value;
    if (!it) return [];
    if (ctxMulti.value) {
        return [
            multiIsOneGroup.value
                ? { label: t('library.map.ungroup', 'فك التجميع'), icon: RectangleGroupIcon, run: () => ungroupSelected() }
                : { label: t('library.map.group', 'جمّعها (تتحرك معاً دائماً)'), icon: RectangleGroupIcon, run: () => groupSelected() },
            { label: t('library.map.ctx_rotate_all', 'تدوير المحدد 90°'), icon: ArrowPathRoundedSquareIcon, run: () => bulkRotate(90) },
            { label: t('library.map.flip', 'اقلب اتجاهها (الكتب للجهة الثانية)'), icon: ArrowsRightLeftIcon, run: () => bulkFlip() },
            { label: t('library.map.duplicate_sel', 'تكرار المحدد بجانبه'), icon: DocumentDuplicateIcon, run: () => bulkDuplicate() },
            { label: multiItems.value.every((x) => x.locked) ? t('library.map.unlock_all', 'فتح قفل المحدد') : t('library.map.lock_all_sel', 'قفل المحدد'), icon: LockClosedIcon, run: () => { const v = !multiItems.value.every((x) => x.locked); multiItems.value.forEach((x) => (x.locked = v)); } },
            { label: t('library.map.unselect', 'إلغاء التحديد'), icon: XMarkIcon, run: () => (multi.value = new Set()) },
            { label: t('library.map.ctx_delete_all', 'حذف المحدد (:n)', { n: multi.value.size }), icon: TrashIcon, danger: true, run: () => bulkRemove() },
        ];
    }
    const out = [{ label: t('library.map.rename', 'إعادة التسمية'), icon: PencilSquareIcon, keep: true, run: () => startRename() }, { label: t('library.map.ctx_edit', 'تعديل البيانات'), icon: PencilIcon, run: (i) => { selectedKey.value = i._key; nextTick(() => panelEl.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })); } }];
    if (!['door', 'qr'].includes(it.kind)) {
        out.push({ label: t('library.map.rotate', 'تدوير 90°'), icon: ArrowPathRoundedSquareIcon, run: needsMove((i) => setRotation(i, (i.rotation || 0) + 90)) });
        out.push({ label: t('library.map.ctx_rotate_45', 'تدوير 45°'), icon: ArrowPathRoundedSquareIcon, run: needsMove((i) => setRotation(i, (i.rotation || 0) + 45)) });
    }
    if (it.kind === 'shelf') out.push({ label: it.double_sided ? t('library.map.ctx_one_face', 'اجعله بوجه واحد') : t('library.map.ctx_two_faces', 'اجعله بوجهين'), icon: Square2StackIcon, run: (i) => { i.double_sided = !i.double_sided; i.depth = i.double_sided ? Math.max(i.depth, 0.9) : Math.min(i.depth, 0.5); } });
    if (isHallKind(it)) {
        out.push({ label: t('library.map.select_hall_shelves', 'حدد كل رفوفها'), icon: RectangleStackIcon, run: (i) => selectHallShelves(i) });
        if (it.kind === 'hall') out.push({ label: t('library.map.rows_here', 'صفوف رفوف داخل القاعة'), icon: SparklesIcon, run: () => openGenerate() });
    }
    if (it.kind === 'stairs') out.push({ label: it.double_sided ? t('library.map.ctx_is_stairs', 'اجعله درجاً') : t('library.map.ctx_is_lift', 'اجعله مصعداً'), icon: ChevronDoubleUpIcon, run: (i) => (i.double_sided = !i.double_sided) });
    if (!['door'].includes(it.kind)) out.push({ label: t('library.map.duplicate', 'تكرار بجانبه'), icon: DocumentDuplicateIcon, run: () => duplicate() });
    out.push({ label: it.locked ? t('library.map.unlock', 'فتح القفل') : t('library.map.lock', 'قفل (لا يتحرك بالخطأ)'), icon: it.locked ? LockOpenIcon : LockClosedIcon, run: (i) => toggleLock(i) });
    out.push({ label: t('common.delete', 'حذف'), icon: TrashIcon, danger: true, run: needsMove(() => remove()) });
    return out;
});
const panelEl = ref(null);
function closeCtx() { ctx.value = null; }
onMounted(() => { window.addEventListener('pointerdown', closeCtxOutside, true); window.addEventListener('scroll', closeCtx, true); });
onBeforeUnmount(() => { window.removeEventListener('pointerdown', closeCtxOutside, true); window.removeEventListener('scroll', closeCtx, true); });
function closeCtxOutside(e) { if (ctx.value && !e.target.closest?.('[data-ctx]')) ctx.value = null; }
// A long press on a phone opens the menu too.
let pressTimer = null;
function pressStart(e, item) {
    if (e.pointerType !== 'touch') return;
    clearTimeout(pressTimer);
    const at = { clientX: e.clientX, clientY: e.clientY };
    pressTimer = setTimeout(() => { drag = null; skipClick = true; navigator.vibrate?.(30); openCtx(at, item); }, 550);
}
const pressEnd = () => clearTimeout(pressTimer);

const touches = new Map();
let pinch = null;
let pan = null;
function boxPoint(e) {
    const r = planBox.value.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
}
function gestureDown(e) {
    touches.set(e.pointerId, boxPoint(e));
    if (touches.size === 2) {
        // A second finger: whatever the first one started becomes a pinch.
        drag = null;
        rect.value = null;
        pan = null;
        const [a, b] = [...touches.values()];
        pinch = { dist: Math.hypot(a.x - b.x, a.y - b.y) || 1, zoom: zoom.value, mid: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 } };
        skipClick = true;
    }
}
function gestureMove(e) {
    if (!touches.has(e.pointerId)) return;
    touches.set(e.pointerId, boxPoint(e));
    const box = planBox.value;
    if (pinch && touches.size >= 2) {
        e.stopPropagation();
        const [a, b] = [...touches.values()];
        const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
        box.scrollLeft -= mid.x - pinch.mid.x;
        box.scrollTop -= mid.y - pinch.mid.y;
        pinch.mid = mid;
        const z = pinch.zoom * (Math.hypot(a.x - b.x, a.y - b.y) / pinch.dist);
        if (Math.abs(z - zoom.value) > 0.02) zoomAt(z, mid.x, mid.y);
        return;
    }
    if (pan) {
        const p = touches.get(e.pointerId);
        if (!pan.moved && Math.hypot(p.x - pan.x, p.y - pan.y) < 5) return;
        if (!pan.moved && pan.locked) flashLocked(pan.locked);
        pan.moved = true;
        box.scrollLeft = pan.sl - (p.x - pan.x);
        box.scrollTop = pan.st - (p.y - pan.y);
    }
}
function gestureUp(e) {
    touches.delete(e.pointerId);
    if (touches.size < 2) pinch = null;
    if (pan?.moved) skipClick = true;
    if (!touches.size) { pan = null; setTimeout(() => (skipClick = false), 50); }
}
function onWheel(e) {
    if (!e.ctrlKey && !e.metaKey) return;
    e.preventDefault();
    const p = boxPoint(e);
    zoomAt(zoom.value * (e.deltaY < 0 ? 1.15 : 1 / 1.15), p.x, p.y);
}
const placingEntrance = ref(false);

// «قاعات وممرات وطوابق» (2026-11-06, by request): a library of several halls, rooms and
// corridors in any direction, on one floor or several. The plan shows one floor at a time.
const storey = ref(0);
watch(storey, () => (multi.value = new Set()));
const onStorey = computed(() => items.value.filter((i) => storeyOf(i) === storey.value));
const isHallKind = (i) => HALL_KINDS.includes(i.kind) && i.points?.length >= 3;
const storeyHalls = computed(() => onStorey.value.filter(isHallKind));
// Halls and corridors underneath, everything else on top.
const planItems = computed(() => [...onStorey.value].sort((a, b) => isHallKind(b) - isHallKind(a) || (a.kind === 'corridor') - (b.kind === 'corridor')));
const storeyLabel = (n) => floor.storeys[n] || t('library.map.storey_n', 'الطابق :n', { n });
function inOutline(p, hall) {
    const poly = outline(hall);
    let hit = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const a = poly[i], b = poly[j];
        if ((a.y > p.y) !== (b.y > p.y) && p.x < ((b.x - a.x) * (p.y - a.y)) / (b.y - a.y) + a.x) hit = !hit;
    }
    return hit;
}
/** The smallest hall or corridor a point is in, on the floor shown. */
function hallAtPoint(p) {
    return storeyHalls.value.filter((h) => inOutline(p, h)).sort((a, b) => a.width * a.depth - b.width * b.depth)[0] ?? null;
}
/** What moves and turns with a hall: what stands inside it and the doors on its walls. */
function contents(hall) {
    return onStorey.value.filter((i) => i !== hall && (i.kind === 'door' ? (snapToWall([hall], i)?.distance ?? 9) < 0.4 : inOutline(i, hall)));
}
function addStorey() {
    floor.storeys.push(t('library.map.storey_n', 'الطابق :n', { n: floor.storeys.length }));
    storey.value = floor.storeys.length - 1;
}
function removeStorey(n) {
    if (floor.storeys.length < 2) return;
    if (items.value.some((i) => storeyOf(i) === n) && !confirm(t('library.map.remove_storey_confirm', 'سيُحذف كل ما في هذا الطابق من المخطط. متابعة؟'))) return;
    items.value = items.value.filter((i) => storeyOf(i) !== n).map((i) => (storeyOf(i) > n ? { ...i, storey: storeyOf(i) - 1 } : i));
    floor.storeys.splice(n, 1);
    storey.value = Math.min(storey.value, floor.storeys.length - 1);
    selectedKey.value = null;
}
/** «درج فوق درج»: the same stairs on every other floor, so the floors are joined. */
function stairsToAllFloors() {
    const s = selected.value;
    floor.storeys.forEach((_, n) => {
        if (n === storeyOf(s) || items.value.some((i) => i.kind === 'stairs' && storeyOf(i) === n && Math.hypot(i.x - s.x, i.y - s.y) < 2.5)) return;
        items.value.push({ ...s, id: null, _key: `n${++keySeq}`, storey: n, code: uniqueCode(t('library.map.kind.stairs_short', 'درج') + ' ') });
    });
}

// Tools: «قاعة» / «ممر» by dragging a rectangle (or an L), «باب» and «ملصق QR» by clicking a wall.
const tool = ref(null); // 'hall' | 'corridor' | 'door' | 'qr' | null
const rect = ref(null); // { kind, a: {x, y}, b: {x, y} } while dragging
// «غرفة على شكل حرف L» (2026-11-06, by request): one drag draws the L; which corner is cut out
// can be changed afterwards, and dragging its corners keeps the walls straight.
const rectShape = ref('rect'); // 'rect' | 'L'
const L_CORNERS = ['tr', 'tl', 'bl', 'br'];
/** An L the size of the box w × d (centred), with a cut-out at `corner` (fractions of w and d). */
function lPoints(w, d, corner = 'tr', cutX = 0.5, cutY = 0.5) {
    const hw = w / 2, hd = d / 2, cx = hw - w * cutX, cy = -hd + d * cutY;
    // Cut at top-right, then mirrored to the corner asked for.
    const base = [[-hw, -hd], [cx, -hd], [cx, cy], [hw, cy], [hw, hd], [-hw, hd]];
    const sx = corner.includes('l') ? -1 : 1, sy = corner.includes('b') ? -1 : 1;
    const pts = base.map(([x, y]) => [+(x * sx).toFixed(2), +(y * sy).toFixed(2)]);
    return sx * sy < 0 ? pts.reverse() : pts;
}
function pickTool(name) {
    placingEntrance.value = false;
    drawing.value = null;
    view.value = 'plan';
    tool.value = tool.value === name ? null : name;
    if (tool.value) selectedKey.value = null;
}
/** Corners stick to the corners and walls of halls already drawn, so rooms share a wall. */
function snapToHalls(p) {
    let { x, y } = p;
    for (const h of storeyHalls.value) {
        for (const q of outline(h)) {
            if (Math.abs(q.x - x) < 0.4) x = +q.x.toFixed(2);
            if (Math.abs(q.y - y) < 0.4) y = +q.y.toFixed(2);
        }
    }
    return { x, y };
}
function onPlanDown(e) {
    if (pinch) return;
    // The empty floor, with no tool in hand: drag to move round the plan.
    const free = !tool.value && !drawing.value && !calib.value && !bgMove.value && !placingEntrance.value;
    if (free && multiMode.value && (e.target === svg.value || e.target.dataset?.bg)) {
        // Drag a box round the bookcases to select.
        const p = snapPoint(toPlan(e));
        rect.value = { kind: 'select', a: p, b: p };
        e.target.setPointerCapture?.(e.pointerId);
        return;
    }
    if (free && (e.target === svg.value || e.target.dataset?.bg)) {
        const p = boxPoint(e);
        pan = { x: p.x, y: p.y, sl: planBox.value.scrollLeft, st: planBox.value.scrollTop, moved: false };
        return;
    }
    if (bgMove.value && bg.value) {
        const p = toPlan(e);
        drag = { bg: true, dx: bg.value.x - p.x, dy: bg.value.y - p.y };
        e.target.setPointerCapture?.(e.pointerId);
        return;
    }
    if (tool.value !== 'hall' && tool.value !== 'corridor') return;
    const p = snapToHalls(snapPoint(toPlan(e)));
    rect.value = { kind: tool.value, a: p, b: p };
    e.target.setPointerCapture?.(e.pointerId);
}
function finishRect() {
    const r = rect.value;
    rect.value = null;
    if (!r) return;
    const x0 = Math.min(r.a.x, r.b.x), x1 = Math.max(r.a.x, r.b.x), y0 = Math.min(r.a.y, r.b.y), y1 = Math.max(r.a.y, r.b.y);
    if (r.kind === 'select') {
        for (const i of onStorey.value) if (multiKind(i) && i.x >= x0 && i.x <= x1 && i.y >= y0 && i.y <= y1) multi.value.add(i._key);
        multi.value = new Set(multi.value);
        skipClick = true;
        setTimeout(() => (skipClick = false), 50);
        return;
    }
    if (x1 - x0 < 1 || y1 - y0 < 1) return; // a click, not a drag
    const hall = addHall(r.kind, x0, y0, x1, y1);
    if (rectShape.value === 'L') { hall.points = lPoints(hall.width, hall.depth, dragCorner(r)); recentre(hall); }
    tool.value = null;
    skipClick = true; // the click that ends the drag must not unselect the new hall
    setTimeout(() => (skipClick = false), 50);
}
function addHall(kind, x0, y0, x1, y1, code = null) {
    const w = +(x1 - x0).toFixed(2), d = +(y1 - y0).toFixed(2);
    const item = {
        id: null, _key: `n${++keySeq}`, code: code ?? nextCode(kind), name: '', kind, storey: storey.value, section_id: null,
        x: +((x0 + x1) / 2).toFixed(2), y: +((y0 + y1) / 2).toFixed(2), rotation: 0, color: null, levels: 1, double_sided: false, height: null,
        width: w, depth: d, points: [[-w / 2, -d / 2], [w / 2, -d / 2], [w / 2, d / 2], [-w / 2, d / 2]].map(([a, b]) => [+a.toFixed(2), +b.toFixed(2)]),
    };
    items.value.push(item);
    selectedKey.value = item._key;
    return item;
}
const rectBox = computed(() => {
    const r = rect.value;
    return r ? { x: Math.min(r.a.x, r.b.x), y: Math.min(r.a.y, r.b.y), width: Math.abs(r.b.x - r.a.x), height: Math.abs(r.b.y - r.a.y) } : null;
});
/** The L's cut-out corner: dragging from the top-left down to the bottom-right cuts the
 * top-right, and so on (the two corners dragged between are corners of the room). */
const dragCorner = (r) => `${r.a.y <= r.b.y ? 't' : 'b'}${r.a.x <= r.b.x ? 'r' : 'l'}`;
/** While dragging an L: its outline. */
const rectL = computed(() => {
    const r = rect.value, b = rectBox.value;
    if (!r || !b || rectShape.value !== 'L' || b.width < 0.5 || b.height < 0.5) return null;
    return lPoints(b.width, b.height, dragCorner(r)).map(([x, y]) => `${b.x + b.width / 2 + x},${b.y + b.height / 2 + y}`).join(' ');
});
/** Turns the selected L so the next corner is the cut-out one, keeping the size of its arms. */
function turnL() {
    const s = selected.value, pts = s.points;
    const xs = pts.map((p) => p[0]), ys = pts.map((p) => p[1]);
    const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
    const w = maxX - minX, d = maxY - minY;
    const inner = pts.find(([x, y]) => x > minX + 0.01 && x < maxX - 0.01 && y > minY + 0.01 && y < maxY - 0.01);
    if (!inner || !w || !d) return;
    // The cut-out corner is the corner of the box with no point on it.
    const has = (x, y) => pts.some((p) => Math.abs(p[0] - x) < 0.01 && Math.abs(p[1] - y) < 0.01);
    const now = L_CORNERS.find((c) => !has(c.includes('r') ? maxX : minX, c.includes('t') ? minY : maxY));
    if (!now) return;
    const cutX = (now.includes('r') ? maxX - inner[0] : inner[0] - minX) / w;
    const cutY = (now.includes('t') ? inner[1] - minY : maxY - inner[1]) / d;
    s.points = lPoints(w, d, L_CORNERS[(L_CORNERS.indexOf(now) + 1) % 4], cutX, cutY);
    recentre(s);
}
const isL = (s) => s?.points?.length === 6 && s.points.every((p, i) => { const q = s.points[(i + 1) % 6]; return Math.abs(p[0] - q[0]) < 0.01 || Math.abs(p[1] - q[1]) < 0.01; });

/** «ملصق QR» on the wall nearest the click, facing the side clicked. */
function placeSticker(p) {
    const at = wallSpot(wallPolygons(floor, items.value, storey.value), p);
    if (!at || at.distance > 1.5) return false;
    const item = { id: null, _key: `n${++keySeq}`, code: nextCode('qr'), name: '', kind: 'qr', storey: storey.value, section_id: null, color: null, ...KIND_DEFAULTS.qr, x: at.x, y: at.y, rotation: at.rotation };
    items.value.push(item);
    selectedKey.value = item._key;
    return true;
}
// «ملصقات QR تلقائية» (2026-11-06, by request): stickers on the walls of every floor, by the
// doors, the stairs and along corridors, wherever there isn't one yet. Bookcases have theirs.
const qrNote = ref('');
function autoQr() {
    let added = 0;
    floor.storeys.forEach((_, n) => {
        for (const s of autoStickers(floor, items.value, n)) {
            const near = s.near === 'entrance' ? t('library.map.qr_by_entrance', 'بجانب المدخل') : s.near ? t('library.map.qr_by', 'بجانب :name', { name: s.near }) : '';
            items.value.push({ id: null, _key: `n${++keySeq}`, code: nextCode('qr'), name: near, kind: 'qr', storey: n, section_id: null, color: null, ...KIND_DEFAULTS.qr, x: s.x, y: s.y, rotation: s.rotation });
            added++;
        }
    });
    selectedKey.value = null;
    view.value = 'plan';
    qrNote.value = added
        ? t('library.map.qr_added', 'أُضيف :n ملصقاً على الجدران (بجانب الأبواب والدرج وعلى طول الممرات). كل رف له ملصقه أصلاً. احفظ ثم اطبعها من «ملصقات QR».', { n: added })
        : t('library.map.qr_none_added', 'كل الأماكن المناسبة فيها ملصقات. تقدر تضيف ملصقاً بيدك بالضغط على أي جدار.');
    setTimeout(() => (qrNote.value = ''), 9000);
}
/** A door goes on the nearest wall, along it. */
function placeDoor(p) {
    const at = snapToWall(storeyHalls.value, p);
    if (!at || at.distance > 1.5) return false;
    const item = { id: null, _key: `n${++keySeq}`, code: nextCode('door'), name: '', kind: 'door', storey: storey.value, section_id: null, color: null, ...KIND_DEFAULTS.door, x: at.x, y: at.y, rotation: at.rotation };
    items.value.push(item);
    selectedKey.value = item._key;
    return true;
}
const doorHint = ref(false);

const KIND_DEFAULTS = {
    shelf: { width: 2, depth: 0.5, levels: 5, double_sided: false },
    double: { width: 2, depth: 0.9, levels: 5, double_sided: true },
    desk: { width: 2.4, depth: 0.8, levels: 1, double_sided: false },
    table: { width: 1.8, depth: 1, levels: 1, double_sided: false },
    wall: { width: 4, depth: 0.15, levels: 1, double_sided: false },
    gate: { width: 1.4, depth: 0.5, levels: 1, double_sided: false },
    door: { width: 1.2, depth: 0.2, levels: 1, double_sided: false },
    stairs: { width: 2, depth: 3, levels: 1, double_sided: false },
    qr: { width: 0.3, depth: 0.1, levels: 1, double_sided: false },
};
const kindLabel = (k) => ({ shelf: t('library.map.kind.shelf', 'رف كتب'), desk: t('library.map.kind.desk', 'مكتب الإعارة'), table: t('library.map.kind.table', 'طاولة قراءة'), wall: t('library.map.kind.wall', 'جدار'), gate: t('library.map.kind.gate', 'بوابة مانعة للسرقة'), obstacle: t('library.map.kind.obstacle', 'عائق'), hall: t('library.map.kind.hall', 'قاعة / غرفة'), corridor: t('library.map.kind.corridor', 'ممر'), door: t('library.map.kind.door', 'باب'), stairs: t('library.map.kind.stairs', 'درج / مصعد'), qr: t('library.map.kind.qr', 'ملصق QR') })[k] ?? k;

function nextCode(kind) {
    if (kind === 'desk') return uniqueCode(t('library.map.kind.desk_short', 'مكتب'));
    if (kind === 'table') return uniqueCode(t('library.map.kind.table_short', 'طاولة'));
    if (kind === 'wall') return uniqueCode(t('library.map.kind.wall', 'جدار'));
    if (kind === 'gate') return uniqueCode(t('library.map.kind.gate_short', 'بوابة'));
    if (kind === 'obstacle') return uniqueCode(t('library.map.kind.obstacle', 'عائق'));
    if (kind === 'hall') return uniqueCode(t('library.map.kind.hall_short', 'قاعة') + ' ', storey.value ? storey.value * 100 + 1 : 1);
    if (kind === 'corridor') return uniqueCode(t('library.map.kind.corridor', 'ممر') + ' ');
    if (kind === 'door') return uniqueCode(t('library.map.kind.door', 'باب') + ' ');
    if (kind === 'stairs') return uniqueCode(t('library.map.kind.stairs_short', 'درج') + ' ');
    if (kind === 'qr') return uniqueCode('QR');
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
    // Drop it in the middle of the selected hall (turned like it), or of the plan, a little off
    // any item already there.
    const hall = selected.value && isHallKind(selected.value) ? selected.value : (selected.value ? hallAtPoint(selected.value) : null);
    let x = hall ? hall.x : floor.width / 2, y = hall ? hall.y : floor.depth / 2;
    while (onStorey.value.some((i) => !isHallKind(i) && Math.abs(i.x - x) < 0.5 && Math.abs(i.y - y) < 0.5)) { x += 0.5; y += 0.5; }
    const item = { id: null, _key: `n${++keySeq}`, code: nextCode(kind), name: '', kind, storey: storey.value, section_id: null, x, y, rotation: hall?.rotation ?? 0, color: null, ...d };
    items.value.push(item);
    selectedKey.value = item._key;
}
function duplicate() {
    const s = selected.value;
    if (!s) return;
    const n = frontNormal(s);
    // The copy goes next to the original, along its length.
    const along = { x: n.y, y: -n.x };
    const copy = { ...s, points: s.points?.map((p) => [...p]) ?? null, id: null, _key: `n${++keySeq}`, code: nextCode(s.kind), x: clamp(s.x - along.x * s.width, 0, floor.width), y: clamp(s.y - along.y * s.width, 0, floor.depth) };
    items.value.push(copy);
    selectedKey.value = copy._key;
}
function remove() {
    if (!selected.value) return;
    // A hall goes with its doors (what stands inside it stays, ready to be moved).
    const doors = isHallKind(selected.value) ? new Set(contents(selected.value).filter((o) => o.kind === 'door')) : new Set();
    items.value = items.value.filter((i) => i._key !== selectedKey.value && !doors.has(i));
    selectedKey.value = null;
}
function rotate() {
    if (selected.value) setRotation(selected.value, (selected.value.rotation || 0) + 90);
}
/** Any angle. A hall turns round its middle with everything in it. */
function setRotation(it, value) {
    const next = ((Math.round(Number(value) || 0) % 360) + 360) % 360;
    const delta = next - (it.rotation || 0);
    if (!delta) return;
    if (isHallKind(it)) {
        const a = (delta * Math.PI) / 180, c = Math.cos(a), s = Math.sin(a);
        for (const o of contents(it)) {
            const dx = o.x - it.x, dy = o.y - it.y;
            o.x = +(it.x + dx * c - dy * s).toFixed(2);
            o.y = +(it.y + dx * s + dy * c).toFixed(2);
            o.rotation = ((((o.rotation || 0) + delta) % 360) + 360) % 360;
        }
    }
    it.rotation = next;
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
    if (tool.value === 'hall' || tool.value === 'corridor') return onPlanDown(e);
    if (tool.value === 'door' || tool.value === 'qr') return;
    if (e.altKey && multi.value.size) multi.value = new Set();
    else if (item.group_key && !multiMode.value && !e.shiftKey && !multi.value.has(item._key)) selectGroup(item);
    if ((multiMode.value || e.shiftKey) && multiKind(item)) {
        // Tap to add to (or take out of) the selection; a selected one can still be dragged
        // and the others move with it.
        if (!multi.value.has(item._key) || !multiMode.value) { toggleMulti(item); if (!multi.value.has(item._key)) return; }
    }
    if (multi.value.size && !multi.value.has(item._key)) multi.value = new Set();
    selectedKey.value = multi.value.size ? null : item._key;
    if (!canMove(item)) {
        // Locked: it is chosen, and dragging it moves round the plan instead.
        const b = boxPoint(e);
        pan = { x: b.x, y: b.y, sl: planBox.value.scrollLeft, st: planBox.value.scrollTop, moved: false, locked: item };
        return;
    }
    const p = toPlan(e);
    const group = multi.value.size ? items.value.filter((o) => o !== item && multi.value.has(o._key) && !o.locked) : isHallKind(item) ? contents(item) : null;
    drag = { item, dx: item.x - p.x, dy: item.y - p.y, moved: false, children: group?.map((o) => ({ o, dx: o.x - item.x, dy: o.y - item.y })) ?? null };
    e.target.setPointerCapture?.(e.pointerId);
}
function startEntranceDrag(e) {
    const p = toPlan(e);
    drag = { entrance: true, dx: floor.entrance.x - p.x, dy: floor.entrance.y - p.y };
    e.target.setPointerCapture?.(e.pointerId);
}
function onMove(e) {
    if (calib.value) { calib.value.hover = toPlan(e); return; }
    if (drawing.value) { drawing.value.hover = snapPoint(toPlan(e)); return; }
    if (rect.value) { rect.value.b = rect.value.kind === 'select' ? snapPoint(toPlan(e)) : snapToHalls(snapPoint(toPlan(e))); return; }
    if (!drag) return;
    const p = toPlan(e);
    pressEnd();
    if (drag.resize) { resizeTo(p, e.shiftKey); tip.value = { cx: e.clientX, cy: e.clientY, text: `${m2(drag.item.width)} × ${m2(drag.item.depth)} م` }; return; }
    if (drag.rotate) { rotateTo(p, e.shiftKey || e.altKey); tip.value = { cx: e.clientX, cy: e.clientY, text: `${drag.item.rotation}°` }; return; }
    if (drag.multiRotate) { multiRotateTo(p, e.shiftKey || e.altKey); tip.value = { cx: e.clientX, cy: e.clientY, text: `${multiTurn.value}°` }; return; }
    if (drag.vertex !== undefined) {
        // Plan point → the obstacle's own (unrotated) coordinates.
        const it = drag.item, a = (-(it.rotation || 0) * Math.PI) / 180;
        const q = snapPoint(p), dx = q.x - it.x, dy = q.y - it.y;
        const v = [+(dx * Math.cos(a) - dy * Math.sin(a)).toFixed(2), +(dx * Math.sin(a) + dy * Math.cos(a)).toFixed(2)];
        const pts = it.points, i = drag.vertex, n = pts.length;
        if (drag.square) {
            // Square corners stay square: the two neighbours move along with this corner, so an L
            // or a room keeps straight walls. (A middle point moves its whole wall.)
            for (const j of [(i - 1 + n) % n, (i + 1) % n]) {
                const vertical = Math.abs(pts[j][0] - pts[i][0]) < 0.01;
                pts[j] = vertical ? [v[0], pts[j][1]] : [pts[j][0], v[1]];
            }
        }
        pts[i] = v;
        return;
    }
    if (drag.bg) {
        bg.value.x = +(p.x + drag.dx).toFixed(2);
        bg.value.y = +(p.y + drag.dy).toFixed(2);
        return;
    }
    if (drag.entrance) {
        floor.entrance.x = clamp(round(p.x + drag.dx), 0, floor.width);
        floor.entrance.y = clamp(round(p.y + drag.dy), 0, floor.depth);
        return;
    }
    if (drag.item.kind === 'qr') {
        // A sticker slides along the walls too, and turns to face the side it is dragged to.
        const at = wallSpot(wallPolygons(floor, items.value, storey.value), p);
        if (at && at.distance < 2) Object.assign(drag.item, { x: at.x, y: at.y, rotation: at.rotation });
        return;
    }
    if (drag.item.kind === 'door') {
        // A door slides along the walls and never leaves them.
        const at = snapToWall(storeyHalls.value, p);
        if (at && at.distance < 2) Object.assign(drag.item, { x: at.x, y: at.y, rotation: at.rotation });
        return;
    }
    drag.item.x = clamp(round(p.x + drag.dx), 0, floor.width);
    drag.item.y = clamp(round(p.y + drag.dy), 0, floor.depth);
    for (const c of drag.children ?? []) { c.o.x = +(drag.item.x + c.dx).toFixed(2); c.o.y = +(drag.item.y + c.dy).toFixed(2); }
}
function endDrag() {
    if (rect.value) finishRect();
    if (drag?.vertex !== undefined) recentre(drag.item);
    if (drag?.resize || drag?.rotate || drag?.multiRotate) { skipClick = true; setTimeout(() => (skipClick = false), 50); }
    rotating.value = false;
    multiTurn.value = null;
    tip.value = null;
    pressEnd();
    drag = null;
    if (pending) commit();
}
let skipClick = false;
function onPlanClick(e) {
    if (skipClick) { skipClick = false; return; }
    if (calib.value) { addCalibPoint(toPlan(e)); return; }
    if (drawing.value) { addDrawPoint(toPlan(e)); return; }
    if (tool.value === 'door') {
        doorHint.value = !placeDoor(toPlan(e));
        return;
    }
    if (tool.value === 'qr') {
        doorHint.value = !placeSticker(toPlan(e));
        return;
    }
    if (placingEntrance.value) {
        const p = toPlan(e);
        floor.entrance.x = clamp(round(p.x), 0, floor.width);
        floor.entrance.y = clamp(round(p.y), 0, floor.depth);
        placingEntrance.value = false;
        return;
    }
    if (e.target === svg.value || e.target.dataset.bg) selectedKey.value = null;
}

// «عائق» (2026-11-06, by request): drawn corner by corner in the shape of the real obstacle
// (a pillar, a counter, an odd-shaped corner...). Its corners can be dragged afterwards, and a
// faint dot in the middle of each side adds a corner there.
const drawing = ref(null); // { points: [{x, y}], hover }
const snapPoint = (p) => ({ x: clamp(round(p.x), 0, floor.width), y: clamp(round(p.y), 0, floor.depth) });
function startDrawing(kind = 'obstacle') {
    placingEntrance.value = false;
    tool.value = null;
    view.value = 'plan';
    selectedKey.value = null;
    drawing.value = { kind, points: [], hover: null };
}
function addDrawPoint(p) {
    const d = drawing.value, q = d.kind === 'obstacle' ? snapPoint(p) : snapToHalls(snapPoint(p)), first = d.points[0], last = d.points[d.points.length - 1];
    if (first && d.points.length >= 3 && Math.hypot(q.x - first.x, q.y - first.y) < 0.3) return finishDrawing();
    if (last && Math.hypot(q.x - last.x, q.y - last.y) < 0.05) return; // second click of a double-click
    d.points.push(q);
}
function finishDrawing() {
    const pts = drawing.value?.points ?? [];
    if (pts.length < 3) return;
    const kind = drawing.value.kind ?? 'obstacle';
    const item = { id: null, _key: `n${++keySeq}`, code: nextCode(kind), name: '', kind, storey: storey.value, section_id: null, x: 0, y: 0, rotation: 0, color: null, levels: 1, double_sided: false, height: kind === 'obstacle' ? 1 : null, width: 1, depth: 1, points: [] };
    item.points = pts.map((p) => [p.x, p.y]);
    recentre(item, true);
    items.value.push(item);
    drawing.value = null;
    selectedKey.value = item._key;
}
/** Puts x/y at the middle of the outline and keeps width/depth as its size. */
function recentre(it, absolute = false) {
    let pts = it.points;
    if (!absolute) {
        const a = ((it.rotation || 0) * Math.PI) / 180, c = Math.cos(a), s = Math.sin(a);
        pts = pts.map(([px, py]) => [it.x + px * c - py * s, it.y + px * s + py * c]);
        it.rotation = 0;
    }
    const xs = pts.map((p) => p[0]), ys = pts.map((p) => p[1]);
    const cx = (Math.min(...xs) + Math.max(...xs)) / 2, cy = (Math.min(...ys) + Math.max(...ys)) / 2;
    it.x = +cx.toFixed(2);
    it.y = +cy.toFixed(2);
    it.points = pts.map(([px, py]) => [+(px - it.x).toFixed(2), +(py - it.y).toFixed(2)]);
    it.width = Math.max(0.3, +(Math.max(...xs) - Math.min(...xs)).toFixed(2));
    it.depth = Math.max(0.1, +(Math.max(...ys) - Math.min(...ys)).toFixed(2));
}
function startVertexDrag(e, item, index, insert = false) {
    selectedKey.value = item._key;
    if (!canMove(item)) { flashLocked(item); return; }
    if (insert) {
        const a = item.points[index - 1], b = item.points[index % item.points.length];
        item.points.splice(index, 0, [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2]);
    }
    // Halls and corridors whose walls are all straight (rooms, Ls) keep them straight.
    const square = item.kind !== 'obstacle' && item.points.every((p, i) => { const q = item.points[(i + 1) % item.points.length]; return Math.abs(p[0] - q[0]) < 0.01 || Math.abs(p[1] - q[1]) < 0.01; });
    drag = { item, vertex: index, square };
    e.target.setPointerCapture?.(e.pointerId);
}
function removeVertex(item, index) {
    if (item.points.length > 3) { item.points.splice(index, 1); recentre(item); }
}
/** Ready shapes for the selected obstacle, the size of what it is now. */
function presetShape(kind) {
    const s = selected.value, w = s.width / 2, d = s.depth / 2;
    if (kind === 'rect') s.points = [[-w, -d], [w, -d], [w, d], [-w, d]];
    else if (kind === 'circle') { const r = Math.min(w, d) || 0.3; s.points = Array.from({ length: 20 }, (_, i) => [+(r * Math.cos((i / 20) * 2 * Math.PI)).toFixed(2), +(r * Math.sin((i / 20) * 2 * Math.PI)).toFixed(2)]); }
    else if (kind === 'L') s.points = lPoints(s.width, s.depth);
    recentre(s);
}
function scaleObstacle(axis, value) {
    const s = selected.value, old = axis === 'x' ? s.width : s.depth;
    if (!(value > 0) || !old) return;
    const k = value / old;
    s.points = s.points.map(([px, py]) => (axis === 'x' ? [+(px * k).toFixed(2), py] : [px, +(py * k).toFixed(2)]));
    recentre(s);
}
const drawPath = computed(() => {
    const d = drawing.value;
    if (!d) return '';
    return [...d.points, ...(d.hover ? [d.hover] : [])].map((p) => `${p.x},${p.y}`).join(' ');
});
const polyPoints = (s) => s.points.map((p) => p.join(',')).join(' ');
const midPoints = (s) => s.points.map((p, i) => { const q = s.points[(i + 1) % s.points.length]; return [(p[0] + q[0]) / 2, (p[1] + q[1]) / 2, i + 1]; });

function onKey(e) {
    if (ctx.value && e.key === 'Escape') { ctx.value = null; return; }
    const typing = ['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement?.tagName);
    if ((e.ctrlKey || e.metaKey) && !typing && !drawing.value) {
        const k = e.key.toLowerCase();
        if ((k === 'z' || k === 'ئ') && !e.shiftKey) { e.preventDefault(); undo(); return; }
        if (k === 'y' || k === 'غ' || ((k === 'z' || k === 'ئ') && e.shiftKey)) { e.preventDefault(); redo(); return; }
        if ((k === 'd' || k === 'ي') && multi.value.size) { e.preventDefault(); bulkDuplicate(); return; }
    }
    if (calib.value && e.key === 'Escape') { calib.value = null; return; }
    if (tool.value && e.key === 'Escape') { tool.value = null; rect.value = null; return; }
    if (drawing.value) {
        if (e.key === 'Escape') drawing.value = null;
        else if (e.key === 'Enter') finishDrawing();
        else if (e.key === 'Backspace') drawing.value.points.pop();
        return;
    }
    if (multi.value.size && !typing) {
        const step = e.shiftKey ? 1 : 0.25;
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (moves[e.key]) { e.preventDefault(); for (const i of multiItems.value.filter(canMove)) { i.x = clamp(+(i.x + moves[e.key][0]).toFixed(2), 0, floor.width); i.y = clamp(+(i.y + moves[e.key][1]).toFixed(2), 0, floor.depth); } }
        else if (e.key === 'Delete') bulkRemove();
        else if (e.key === 'Escape') multi.value = new Set();
        else if (e.key === 'g' || e.key === 'G' || e.key === 'ل') e.shiftKey ? ungroupSelected() : groupSelected();
        else if (e.key === 'r' || e.key === 'R' || e.key === 'ر') bulkRotate(e.shiftKey ? -15 : 15);
        return;
    }
    if (!selected.value || ['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement?.tagName)) return;
    const step = e.shiftKey ? 1 : 0.25;
    const s = selected.value;
    const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    if (!canMove(s) && (moves[e.key] || e.key === 'Delete' || e.key === 'r' || e.key === 'R' || e.key === 'ر')) { flashLocked(s); return; }
    if (moves[e.key]) { e.preventDefault(); s.x = clamp(s.x + moves[e.key][0], 0, floor.width); s.y = clamp(s.y + moves[e.key][1], 0, floor.depth); }
    else if (e.key === 'r' || e.key === 'R' || e.key === 'ر') rotate();
    else if (e.key === 'Delete') remove();
    else if (e.key === 'Escape') selectedKey.value = null;
}
onMounted(() => { window.addEventListener('keydown', onKey); lastState = state(); });
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const corners = (s) => `translate(${s.x} ${s.y}) rotate(${s.rotation})`;
const fill = (s) => (s.kind === 'qr' ? '#111827' : s.kind === 'hall' ? '#64748b' : s.kind === 'corridor' ? '#94a3b8' : s.kind === 'door' ? '#10b981' : s.kind === 'stairs' ? '#7c3aed' : s.kind === 'shelf' ? s.color || sectionColor(s.section_id) : s.kind === 'wall' ? '#94a3b8' : s.kind === 'gate' ? '#dc2626' : s.kind === 'obstacle' ? s.color || '#6b7280' : s.kind === 'desk' ? '#0f766e' : '#a16207');
const gridLines = computed(() => {
    const out = [];
    for (let x = 0; x <= floor.width; x++) out.push({ x1: x, y1: 0, x2: x, y2: floor.depth, major: x % 5 === 0 });
    for (let y = 0; y <= floor.depth; y++) out.push({ x1: 0, y1: y, x2: floor.width, y2: y, major: y % 5 === 0 });
    return out;
});
const fontSize = computed(() => Math.max(0.28, Math.min(0.5, Math.max(floor.width, floor.depth) / 45)));

// «صفوف تلقائية»: rows of bookcases in the room, or (since 2026-11-06) in the selected hall,
// turned the way the hall is turned. -----------------------------------------------------------
const gen = reactive({ open: false, layout: 'rows', rows: 3, perRow: 4, width: 2, aisle: 1.5, double: true, levels: 5, startLetter: 'A', sectionsByRow: true, replace: true, hall: null,
    walls: { top: true, right: true, bottom: true, left: true }, groupCenter: true });
// «أشكال التوزيع» (2026-11-10, by request, as on the exam hall map): besides parallel rows, a
// library is mostly bookcases along the walls (one face, the books facing the room) with rows of
// two-faced bookcases in the middle. The middle rows are made a group, moved as one. Every
// bookcase stays inside the hall (an L hall too), off the doors, desks, tables and walls there.
const LAYOUTS = [
    { key: 'rows', label: t('library.map.layout_rows', 'صفوف متوازية') },
    { key: 'walls', label: t('library.map.layout_walls', 'على الجدران') },
    { key: 'walls_rows', label: t('library.map.layout_walls_rows', 'الجدران + صفوف بالوسط') },
];
const SINGLE = 0.5, DOUBLE = 0.9, WALL_GAP = 0.05;
const genNote = ref('');
let genNoteTimer = null;
/** The hall to fill: its size from its real outline (in its own turned frame) and where that outline's middle is. */
const genArea = () => {
    const hall = gen.hall && items.value.includes(gen.hall) ? gen.hall : null;
    if (!hall) return { hall: null, W: floor.width, D: floor.depth, ox: 0, oy: 0 };
    const pts = hall.points?.length >= 3 ? hall.points : [[-hall.width / 2, -hall.depth / 2], [hall.width / 2, hall.depth / 2]];
    const xs = pts.map((q) => q[0]), ys = pts.map((q) => q[1]);
    const x0 = Math.min(...xs), x1 = Math.max(...xs), y0 = Math.min(...ys), y1 = Math.max(...ys);
    return { hall, W: x1 - x0, D: y1 - y0, ox: (x0 + x1) / 2, oy: (y0 + y1) / 2 };
};
const genHalls = computed(() => storeyHalls.value.filter((h) => h.kind === 'hall'));
function inPoly(p, poly) {
    let hit = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const a = poly[i], b = poly[j];
        if ((a.y > p.y) !== (b.y > p.y) && p.x < ((b.x - a.x) * (p.y - a.y)) / (b.y - a.y) + a.x) hit = !hit;
    }
    return hit;
}
/** Fills the hall with as many rows as fit, a metre clear of its walls. */
function fitGen() {
    const { hall, W, D } = genArea();
    if (!hall) return;
    const depth = gen.double ? DOUBLE : SINGLE;
    gen.perRow = Math.max(1, Math.floor((W - 2) / gen.width));
    gen.rows = Math.max(1, Math.min(26, Math.floor((D - 2 + gen.aisle) / (depth + gen.aisle))));
}
watch(() => [gen.layout, gen.width, gen.aisle, gen.hall, gen.double], () => { if (gen.open) fitGen(); });
function openGenerate() {
    const s = selected.value;
    // The selected hall, or the hall the selected bookcases are in, or the first one on this floor.
    const pick = s && s.kind === 'hall' ? s : s ? genHalls.value.find((h) => inOutline(s, h)) : null;
    gen.hall = pick ?? genHalls.value.find((h) => multiItems.value.some((i) => inOutline(i, h))) ?? genHalls.value[0] ?? null;
    if (gen.hall) fitGen();
    gen.open = true;
}
/** The places for the chosen layout, round the hall's middle: x, y (metres), r (the turn; 0 = books facing +y), depth, two faces, and the run (row) each is in. */
function layoutSpots(W, D, inHall = true) {
    const w = gen.width, out = [];
    let run = 0;
    const along = (from, to, at, r, vertical, depth, double, group = null) => {
        const n = Math.floor((to - from + 1e-6) / w);
        if (n < 1) return;
        const start = (from + to) / 2 - (n * w) / 2 + w / 2;
        for (let k = 0; k < n; k++) out.push({ ...(vertical ? { x: at, y: start + k * w } : { x: start + k * w, y: at }), r, run, k, depth, double, group });
        run++;
    };
    if (gen.layout === 'rows') {
        const depth = gen.double ? DOUBLE : SINGLE;
        if (inHall) {
            gen.rows = Math.max(1, Math.min(gen.rows, Math.floor((D - 0.2 + gen.aisle) / (depth + gen.aisle))));
            gen.perRow = Math.max(1, Math.min(gen.perRow, Math.floor((W - 0.2) / w)));
        }
        const total = gen.rows * depth + (gen.rows - 1) * gen.aisle;
        const len = gen.perRow * w;
        for (let r = 0; r < gen.rows; r++) along(-len / 2, len / 2, -total / 2 + depth / 2 + r * (depth + gen.aisle), 0, false, depth, gen.double);
        return out;
    }
    // Along the walls: one face, the back to the wall and the books facing the room.
    const wl = gen.walls, band = WALL_GAP + SINGLE;
    if (wl.top) along(-W / 2 + WALL_GAP, W / 2 - WALL_GAP, -D / 2 + band - SINGLE / 2, 0, false, SINGLE, false);
    const yA = -D / 2 + (wl.top ? band + 0.05 : WALL_GAP), yB = D / 2 - (wl.bottom ? band + 0.05 : WALL_GAP);
    if (wl.right) along(yA, yB, W / 2 - band + SINGLE / 2, 90, true, SINGLE, false);
    if (wl.bottom) along(-W / 2 + WALL_GAP, W / 2 - WALL_GAP, D / 2 - band + SINGLE / 2, 180, false, SINGLE, false);
    if (wl.left) along(yA, yB, -W / 2 + band - SINGLE / 2, 270, true, SINGLE, false);
    if (gen.layout === 'walls_rows') {
        // Two-faced rows in the middle, an aisle clear of the wall bookcases all round.
        const endX = W / 2 - (wl.left || wl.right ? band + gen.aisle : 1), endY = D / 2 - (wl.top || wl.bottom ? band + gen.aisle : 1);
        const g = gen.groupCenter ? newGroupKey() : null;
        const rows = Math.max(0, Math.floor((2 * endY + gen.aisle) / (DOUBLE + gen.aisle)));
        const total = rows * DOUBLE + Math.max(0, rows - 1) * gen.aisle;
        for (let r = 0; r < rows; r++) along(-endX, endX, -total / 2 + DOUBLE / 2 + r * (DOUBLE + gen.aisle), 0, false, DOUBLE, true, g);
    }
    return out;
}
function generate() {
    const { hall, W, D, ox, oy } = genArea();
    const rowsLike = gen.layout === 'rows';
    const depth = gen.double ? DOUBLE : SINGLE;
    const runLength = gen.perRow * gen.width;
    const total = gen.rows * depth + (gen.rows - 1) * gen.aisle;
    // Positions are worked out round the middle, then turned and moved into place.
    const cx = hall ? hall.x : floor.width / 2;
    const cy = hall ? hall.y : rowsLike ? Math.max(1 + total / 2, floor.depth / 2 - 0.75) : floor.depth / 2;
    const turn = hall?.rotation ?? 0;
    const a = (turn * Math.PI) / 180, c = Math.cos(a), sn = Math.sin(a);
    const place = (lx, ly) => { lx += ox; ly += oy; return { x: +(cx + lx * c - ly * sn).toFixed(2), y: +(cy + lx * sn + ly * c).toFixed(2) }; };
    if (gen.replace) {
        const gone = new Set(items.value.filter((i) => i.kind === 'shelf' && storeyOf(i) === storey.value && (!hall || inOutline(i, hall))));
        items.value = items.value.filter((i) => !gone.has(i));
    }
    const doors = onStorey.value.filter((i) => i.kind === 'door');
    const hallPoly = hall ? outline(hall) : [{ x: 0, y: 0 }, { x: floor.width, y: 0 }, { x: floor.width, y: floor.depth }, { x: 0, y: floor.depth }];
    const solid = onStorey.value.filter((i) => ['desk', 'table', 'wall', 'gate', 'obstacle', 'stairs'].includes(i.kind) || (i.kind === 'shelf' && !gen.replace))
        .map((i) => (i.points?.length >= 3 ? outline(i) : footprint(i)));
    const kept = [];
    let skipped = 0;
    const spots = layoutSpots(W, D, !!hall).map((p) => ({ ...p, at: place(p.x, p.y) })).filter((p) => {
        const it = { x: p.at.x, y: p.at.y, rotation: turn + p.r, width: gen.width, depth: p.depth };
        const pts = footprint(it, -0.02);
        const ok = !doors.some((o) => Math.hypot(o.x - p.at.x, o.y - p.at.y) < gen.width / 2 + Math.max(o.width ?? 0.9, 0.9) / 2 + 0.3)
            && pts.every((q) => inPoly(q, hallPoly))
            && !solid.some((poly) => pts.some((q) => inPoly(q, poly)) || poly.some((q) => inPoly(q, pts)))
            && !kept.some((k) => pts.some((q) => inPoly(q, footprint(k, -0.03))) || footprint(k, -0.03).some((q) => inPoly(q, pts)));
        if (ok) kept.push(it); else skipped++;
        return ok;
    });
    const roots = props.sections.filter((x) => !x.parent_id);
    const pool = roots.length ? roots : props.sections;
    const first = gen.startLetter.toUpperCase().charCodeAt(0) || 65;
    const runs = [...new Set(spots.map((p) => p.run))];
    const fresh = spots.map((p) => {
        const r = runs.indexOf(p.run);
        return {
            id: null, _key: `n${++keySeq}`, code: `${String.fromCharCode(first + r)}${p.k + 1}`, name: '', kind: 'shelf', storey: storey.value,
            section_id: gen.sectionsByRow && pool.length ? pool[r % pool.length].id : null, group_key: p.group ?? null,
            x: p.at.x, y: p.at.y, rotation: (((turn + p.r) % 360) + 360) % 360,
            width: gen.width, depth: p.depth, levels: gen.levels, double_sided: p.double, color: null,
        };
    });
    const taken = new Set(items.value.map((i) => i.code.toLowerCase()));
    for (const f of fresh) if (taken.has(f.code.toLowerCase())) f.code = uniqueCode(f.code + '-');
    items.value.push(...fresh);
    // Make the room big enough for what was built.
    if (!hall && rowsLike) {
        floor.width = Math.max(floor.width, Math.ceil(runLength + 2));
        floor.depth = Math.max(floor.depth, Math.ceil(total + 4));
    }
    gen.open = false;
    selectedKey.value = null;
    multi.value = new Set();
    genNote.value = t('library.map.gen_done', 'وُضع :n رف.', { n: fresh.length })
        + (skipped ? ' ' + t('library.map.gen_skipped', 'تُرك :m لأنها لا تتسع داخل القاعة.', { m: skipped }) : '')
        + (fresh.some((f) => f.group_key) ? ' ' + t('library.map.gen_group_hint', 'رفوف الوسط مجمّعة: اضغط على أي واحد منها لتحريكها كلها.') : '');
    clearTimeout(genNoteTimer);
    genNoteTimer = setTimeout(() => (genNote.value = ''), 6000);
}

// «معالج البناء» (2026-11-06, by request): start from a room, or a building of one floor or
// more: halls on both sides of a corridor, a door from each hall to the corridor, stairs at the
// end of the corridor on every floor, and the entrance. Everything can be changed afterwards.
const wiz = reactive({ open: false, type: 'building', storeys: 2, perSide: 3, sides: 2, hallWidth: 10, hallDepth: 8, corridor: 3, roomWidth: 20, roomDepth: 14, keepShelves: true });
function openWizard() {
    if (floor.storeys.length > 1) wiz.storeys = floor.storeys.length;
    wiz.open = true;
}
const wizSize = computed(() => (wiz.type === 'room'
    ? { w: wiz.roomWidth, d: wiz.roomDepth }
    : { w: wiz.perSide * wiz.hallWidth + 4, d: wiz.corridor + wiz.sides * wiz.hallDepth }));
function runWizard() {
    const m = 1; // margin round the building
    const structure = new Set(['hall', 'corridor', 'door', 'stairs', 'wall']);
    items.value = items.value.filter((i) => !structure.has(i.kind) && (wiz.keepShelves || i.kind !== 'shelf'));
    if (wiz.type === 'room') {
        floor.storeys = [floor.storeys[0] || t('library.map.ground', 'الطابق الأرضي')];
        storey.value = 0;
        floor.width = Math.ceil(wiz.roomWidth + 2 * m);
        floor.depth = Math.ceil(wiz.roomDepth + 2 * m);
        addHall('hall', m, m, m + wiz.roomWidth, m + wiz.roomDepth, t('library.map.reading_room', 'قاعة المطالعة'));
        floor.entrance = { x: +(m + wiz.roomWidth / 2).toFixed(2), y: m + wiz.roomDepth };
        placeDoor({ ...floor.entrance });
        items.value = items.value.filter((i) => storeyOf(i) === 0);
    } else {
        const n = Math.max(1, Math.min(20, wiz.storeys));
        const names = [t('library.map.ground', 'الطابق الأرضي'), t('library.map.first', 'الطابق الأول'), t('library.map.second', 'الطابق الثاني'), t('library.map.third', 'الطابق الثالث'), t('library.map.fourth', 'الطابق الرابع')];
        floor.storeys = Array.from({ length: n }, (_, k) => floor.storeys[k] && wiz.storeys === floor.storeys.length ? floor.storeys[k] : names[k] ?? t('library.map.storey_n', 'الطابق :n', { n: k }));
        const W = wiz.perSide * wiz.hallWidth + 4; // 4 m at the end of the corridor for the stairs
        const top = wiz.sides === 2 ? wiz.hallDepth : 0;
        floor.width = Math.ceil(W + 2 * m);
        floor.depth = Math.ceil(top + wiz.corridor + wiz.hallDepth + 2 * m);
        const cy0 = m + top, cy1 = cy0 + wiz.corridor;
        for (let k = 0; k < n; k++) {
            storey.value = k;
            addHall('corridor', m, cy0, m + W, cy1, uniqueCode(`${t('library.map.kind.corridor', 'ممر')} `, k + 1));
            let num = k * 100 + 1;
            for (let side = 0; side < wiz.sides; side++) {
                for (let j = 0; j < wiz.perSide; j++) {
                    const x0 = m + j * wiz.hallWidth, x1 = x0 + wiz.hallWidth;
                    const [y0, y1] = side === 0 ? [cy1, cy1 + wiz.hallDepth] : [cy0 - wiz.hallDepth, cy0];
                    addHall('hall', x0, y0, x1, y1, uniqueCode(`${t('library.map.kind.hall_short', 'قاعة')} `, num++));
                    placeDoor({ x: (x0 + x1) / 2, y: side === 0 ? cy1 : cy0 });
                }
            }
            const stairs = { id: null, _key: `n${++keySeq}`, code: uniqueCode(`${t('library.map.kind.stairs_short', 'درج')} `, k + 1), name: '', kind: 'stairs', storey: k, section_id: null, color: null, ...KIND_DEFAULTS.stairs, width: 2, depth: Math.min(3, wiz.corridor - 0.6), x: +(m + W - 1.5).toFixed(2), y: +((cy0 + cy1) / 2).toFixed(2), rotation: 90 };
            items.value.push(stairs);
            // The way in: the near end of the ground-floor corridor.
            if (k === 0) { floor.entrance = { x: m, y: +((cy0 + cy1) / 2).toFixed(2) }; placeDoor({ ...floor.entrance }); }
        }
        // Shelves kept from before stay on floors that still exist.
        items.value = items.value.filter((i) => storeyOf(i) < n);
    }
    storey.value = 0;
    selectedKey.value = null;
    wiz.open = false;
    view.value = 'plan';
}

// «مخطط البناية» (2026-11-06, by request): a picture or PDF of each floor's plan under the
// drawing, so halls are drawn over it at the right size. «معايرة» sets its scale from one known
// distance: click both ends of a wall, type its length. ---------------------------------------
const bg = computed(() => floor.backgrounds.find((b) => b.storey === storey.value) ?? null);
const bgPanel = ref(false);
const bgMove = ref(false);
const bgUploading = ref(false);
const bgError = ref('');
const calib = ref(null); // { points: [] } while measuring
const bgInput = ref(null);
async function uploadPlan(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    bgUploading.value = true;
    bgError.value = '';
    try {
        const form = new FormData();
        form.append('plan', file);
        const { data } = await axios.post(route('library.manage.map.background'), form);
        // Fitted to the plan's width; the scale is set by «معايرة» afterwards.
        const width = Math.max(4, floor.width);
        const next = { storey: storey.value, path: data.path, url: data.url, x: 0, y: 0, width, aspect: data.aspect, opacity: 0.55, hidden: false };
        floor.backgrounds = [...floor.backgrounds.filter((b) => b.storey !== storey.value), next];
        floor.depth = Math.min(200, Math.max(floor.depth, Math.ceil(width * data.aspect)));
        bgPanel.value = true;
        startCalibration();
    } catch (err) {
        bgError.value = err.response?.data?.message || err.response?.data?.errors?.plan?.[0] || t('library.map.bg_failed', 'تعذّر رفع الملف.');
    } finally {
        bgUploading.value = false;
    }
}
function removePlan() {
    floor.backgrounds = floor.backgrounds.filter((b) => b.storey !== storey.value);
    bgMove.value = false;
    calib.value = null;
}
function startCalibration() {
    tool.value = null;
    drawing.value = null;
    bgMove.value = false;
    view.value = 'plan';
    selectedKey.value = null;
    calib.value = { points: [] };
}
function addCalibPoint(p) {
    const c = calib.value;
    c.points.push({ x: p.x, y: p.y });
    if (c.points.length < 2) return;
    const [a, b] = c.points;
    const measured = Math.hypot(b.x - a.x, b.y - a.y);
    calib.value = null;
    if (measured < 0.05) return;
    const answer = window.prompt(t('library.map.calib_ask', 'كم طول هذه المسافة في الحقيقة (بالمتر)؟'), measured.toFixed(2));
    const real = parseFloat(String(answer ?? '').replace(',', '.').replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
    if (!(real > 0)) return;
    const k = real / measured, b0 = bg.value;
    // Scaled round the first point, so it stays where it was clicked; then moved to the corner.
    b0.width = +(b0.width * k).toFixed(3);
    b0.x = +(a.x - (a.x - b0.x) * k).toFixed(2);
    b0.y = +(a.y - (a.y - b0.y) * k).toFixed(2);
    fitPlan();
}
/** The plan area grows (or shrinks) to the picture, its top-left corner at 0,0. */
function fitPlan() {
    const b = bg.value;
    if (!b) return;
    const dx = -b.x, dy = -b.y;
    if (dx || dy) {
        b.x = 0;
        b.y = 0;
        for (const i of items.value) if (storeyOf(i) === storey.value) { i.x = +(i.x + dx).toFixed(2); i.y = +(i.y + dy).toFixed(2); }
        if (storey.value === 0) { floor.entrance.x = +(floor.entrance.x + dx).toFixed(2); floor.entrance.y = +(floor.entrance.y + dy).toFixed(2); }
    }
    floor.width = Math.min(200, Math.max(4, Math.ceil(b.width)));
    floor.depth = Math.min(200, Math.max(4, Math.ceil(b.width * b.aspect)));
}
const calibLine = computed(() => calib.value?.points.length === 1 && calib.value.hover ? { x1: calib.value.points[0].x, y1: calib.value.points[0].y, x2: calib.value.hover.x, y2: calib.value.hover.y } : null);

// «فحص الخريطة» (2026-11-06, by request): what would stop readers finding their way. -------
const check = reactive({ open: false, running: false, issues: [], unplaced: null });
function overlaps(a, b) {
    // Separating axis test of two turned rectangles.
    const pa = footprint(a), pb = footprint(b);
    for (const poly of [pa, pb]) {
        for (let i = 0; i < 4; i++) {
            const p = poly[i], q = poly[(i + 1) % 4], nx = q.y - p.y, ny = p.x - q.x;
            const proj = (pts) => pts.map((r) => r.x * nx + r.y * ny);
            const A = proj(pa), B = proj(pb);
            if (Math.max(...A) <= Math.min(...B) + 0.02 || Math.max(...B) <= Math.min(...A) + 0.02) return false;
        }
    }
    return true;
}
async function runCheck() {
    check.open = true;
    check.running = true;
    check.issues = [];
    await nextTick();
    await new Promise((r) => setTimeout(r, 30)); // let the dialog paint before the work
    const all = items.value.map((i) => ({ ...i, id: i.id ?? i._key }));
    const out = [];
    const add = (level, text, it = null) => out.push({ level, text, key: it?._key ?? null, storey: it ? storeyOf(it) : null });
    const label = (i) => `${kindLabel(i.kind)} ${i.code}${floor.storeys.length > 1 ? ' · ' + storeyLabel(storeyOf(i)) : ''}`;
    const shelves = all.filter((i) => i.kind === 'shelf');
    if (!shelves.length) add('error', t('library.map.chk_no_shelves', 'لا توجد رفوف على الخريطة بعد.'));
    // Codes
    const seen = new Map();
    for (const i of all) {
        const c = (i.code || '').trim().toLowerCase();
        if (!c) add('error', t('library.map.chk_no_code', ':item بلا رمز.', { item: kindLabel(i.kind) }), i);
        else if (seen.has(c)) add('error', t('library.map.chk_dup', 'الرمز «:code» مكرر.', { code: i.code }), i);
        else seen.set(c, i);
    }
    // Floors joined by stairs
    floor.storeys.forEach((_, n) => {
        if (!n) return;
        const has = all.some((i) => storeyOf(i) === n && i.kind !== 'stairs');
        const joined = all.some((s) => s.kind === 'stairs' && storeyOf(s) === n && (linkedStairs(all, s, n - 1) || linkedStairs(all, s, n + 1)));
        if (has && !joined) add('error', t('library.map.chk_no_stairs', ':storey غير مربوط بدرج مع الطابق الذي تحته (ضع درجاً في نفس المكان بالطابقين).', { storey: storeyLabel(n) }), all.find((i) => storeyOf(i) === n));
    });
    // Halls with no door
    for (const h of all.filter((i) => isHallKind(i))) {
        const n = storeyOf(h);
        const others = all.filter((i) => storeyOf(i) === n && isHallKind(i)).length;
        const doors = all.filter((d) => d.kind === 'door' && storeyOf(d) === n && (snapToWall([h], d)?.distance ?? 9) < 0.4);
        if (others > 1 && !doors.length) add('error', t('library.map.chk_no_door', ':item بلا باب.', { item: label(h) }), h);
    }
    // Things outside every hall, and bookcases standing in each other
    for (const i of all.filter((x) => !isHallKind(x) && !['door', 'qr', 'stairs'].includes(x.kind))) {
        const hs = all.filter((h) => storeyOf(h) === storeyOf(i) && isHallKind(h));
        if (hs.length && !hs.some((h) => inOutline(i, h))) add('warn', t('library.map.chk_outside', ':item خارج كل القاعات.', { item: label(i) }), i);
    }
    const solid = all.filter((i) => ['shelf', 'desk', 'table', 'wall'].includes(i.kind));
    for (let a = 0; a < solid.length; a++) {
        for (let b = a + 1; b < solid.length; b++) {
            if (storeyOf(solid[a]) === storeyOf(solid[b]) && Math.hypot(solid[a].x - solid[b].x, solid[a].y - solid[b].y) < (solid[a].width + solid[b].width) / 2 + 1 && overlaps(solid[a], solid[b])) {
                add('warn', t('library.map.chk_overlap', ':a متداخل مع :b.', { a: label(solid[a]), b: solid[b].code }), solid[a]);
            }
        }
    }
    // Every bookcase reachable from the entrance
    const from = { ...floor.entrance, storey: 0 };
    // (grouped by hall: a missing door cuts off a whole hall at once)
    const cut = new Map();
    for (const s of shelves) {
        await new Promise((r) => setTimeout(r, 0));
        if (findRoute(floor, all, from, s)) continue;
        const hall = all.filter((h) => isHallKind(h) && storeyOf(h) === storeyOf(s) && inOutline(s, h)).sort((a, b) => a.width * a.depth - b.width * b.depth)[0];
        const k = `${storeyOf(s)}|${hall?._key ?? ''}`;
        if (!cut.has(k)) cut.set(k, { hall, list: [] });
        cut.get(k).list.push(s);
    }
    for (const { hall, list } of cut.values()) {
        const where = [hall?.code, floor.storeys.length > 1 ? storeyLabel(storeyOf(list[0])) : ''].filter(Boolean).join(' · ');
        const codes = list.slice(0, 8).map((s) => s.code).join('، ') + (list.length > 8 ? '…' : '');
        add('error', list.length === 1
            ? t('library.map.chk_unreachable', 'لا يوجد طريق من المدخل إلى :item (أغلقه رف أو جدار، أو ينقصه باب أو درج).', { item: label(list[0]) })
            : t('library.map.chk_unreachable_n', 'لا يوجد طريق من المدخل إلى :n رفوف:where (:codes). ينقصها باب أو درج، أو يغلق الطريق رف أو جدار.', { n: list.length, where: where ? ` في ${where}` : '', codes }), list[0]);
    }
    for (const s of shelves) if (!s.section_id) add('info', t('library.map.chk_no_section', ':item بلا قسم: كتبه تظهر فقط إذا كُتب رمزه في حقل «الرف».', { item: label(s) }), s);
    if (!all.some((i) => i.kind === 'qr') && all.some((i) => isHallKind(i))) add('info', t('library.map.chk_no_qr', 'لا توجد ملصقات QR على الجدران. زر «QR تلقائي» يضيفها بجانب الأبواب والدرج.'));
    const order = { error: 0, warn: 1, info: 2 };
    check.issues = out.sort((a, b) => order[a.level] - order[b.level]);
    check.running = false;
    try { const { data } = await axios.get(route('library.manage.map.unplaced')); check.unplaced = typeof data?.count === 'number' ? data : null; } catch { check.unplaced = null; }
}
function goToIssue(issue) {
    check.open = false;
    view.value = 'plan';
    if (issue.storey !== null) storey.value = issue.storey;
    if (issue.key) selectedKey.value = issue.key;
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
    // The plan grows to hold every hall and corridor, so the walk can reach all of them.
    const far = items.value.filter(isHallKind).flatMap(outline);
    if (far.length) {
        floor.width = Math.min(200, Math.max(floor.width, Math.ceil(Math.max(...far.map((p) => p.x)) + 0.5)));
        floor.depth = Math.min(200, Math.max(floor.depth, Math.ceil(Math.max(...far.map((p) => p.y)) + 0.5)));
    }
    saving.value = true;
    router.put(route('library.manage.map.update'), { floor: { ...floor }, shelves: strip(items.value) }, {
        preserveScroll: true,
        onSuccess: () => { justSaved.value = true; setTimeout(() => (justSaved.value = false), 2500); },
        onFinish: () => (saving.value = false),
    });
}
function reset() {
    if (pending) commit();
    Object.assign(floor, withFloorDefaults(props.floor));
    items.value = withKeys(props.shelves);
    selectedKey.value = null;
}
const shelfCount = computed(() => items.value.filter((i) => i.kind === 'shelf').length);
const preview = computed(() => onStorey.value.map((i) => ({ ...i, id: i.id ?? i._key, section: sectionPath(i.section_id) })));
</script>

<template>
    <Head :title="t('library.map.edit', 'ترتيب الرفوف')" />

    <AppLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('library.map.edit', 'ترتيب الرفوف') }}</h2>
                <span class="text-sm text-gray-500">{{ t('library.map.count', ':n رف', { n: shelfCount }) }}</span>
                <a :href="route('library.manage.map.labels')" target="_blank" class="ms-auto inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                    <QrCodeIcon class="h-4 w-4" /> {{ t('library.map.qr_labels_all', 'ملصقات QR') }}
                </a>
                <Link :href="route('library.map')" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                    <EyeIcon class="h-4 w-4" /> {{ t('library.map.as_reader', 'كما يراها القارئ') }}
                </Link>
            </div>
        </template>

        <div :class="full ? 'fixed inset-0 z-40 flex flex-col gap-2 bg-gray-50 p-2 dark:bg-gray-900' : 'space-y-4 p-4 sm:p-6'">
            <LibraryTabs v-if="!full" />
            <Teleport to="body">
                <div v-if="ctx && ctxItem" data-ctx class="fixed z-[70] w-56 overflow-hidden rounded-xl bg-white py-1 text-sm shadow-xl ring-1 ring-black/10 dark:bg-gray-800 dark:ring-white/10" :style="{ left: ctx.x + 'px', top: ctx.y + 'px' }" dir="rtl" @contextmenu.prevent>
                    <p class="truncate border-b border-gray-100 px-3 pb-1.5 pt-1 text-xs font-semibold text-gray-500 dark:border-gray-700" dir="auto">{{ ctxMulti ? t('library.map.multi_count', ':n محدد (:s رف)', { n: multi.size, s: multiShelves.length }) : `${kindLabel(ctxItem.kind)} · ${ctxItem.code}` }}<LockClosedIcon v-if="ctxItem.locked && !ctxMulti" class="ms-1 inline h-3.5 w-3.5" /></p>
                    <form v-if="renaming.on" class="space-y-1.5 px-3 py-2" @submit.prevent="saveRename">
                        <input v-model="renaming.text" maxlength="30" dir="auto" class="w-full rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" @keydown.esc.stop.prevent="renaming.on = false" />
                        <p v-if="renaming.error" class="text-xs text-red-600">{{ renaming.error }}</p>
                        <div class="flex gap-1.5">
                            <button type="submit" class="flex-1 rounded-lg bg-[var(--accent-600)] px-2 py-1 text-xs font-medium text-white">{{ t('common.save', 'حفظ') }}</button>
                            <button type="button" @click="renaming.on = false" class="rounded-lg px-2 py-1 text-xs">{{ t('common.cancel', 'إلغاء') }}</button>
                        </div>
                    </form>
                    <template v-else>
                        <button v-for="(a, i) in ctxActions" :key="i" @click="ctxDo(a.run, a.keep)" class="flex w-full items-center gap-2 px-3 py-1.5 text-start hover:bg-gray-100 dark:hover:bg-gray-700" :class="a.danger ? 'text-red-600' : ''">
                            <component :is="a.icon" class="h-4 w-4 shrink-0 opacity-70" /> {{ a.label }}
                        </button>
                    </template>
                </div>
                <div v-if="tip" class="pointer-events-none fixed z-[70] rounded-md bg-gray-900/90 px-2 py-1 text-xs font-semibold tabular-nums text-white shadow" :style="{ left: tip.cx + 16 + 'px', top: tip.cy + 18 + 'px' }" dir="ltr">{{ tip.text }}</div>
            </Teleport>

            <!-- Toolbar (2026-11-07, by request: split into groups): the groups on top, the tools of the
                 chosen group below, and what is always needed (view, undo, lock, full screen) on the side. -->
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center gap-2 overflow-x-auto whitespace-nowrap border-b border-gray-100 px-2 pt-2 max-sm:gap-1 [scrollbar-width:none] dark:border-gray-700 [&>*]:shrink-0">
                <div class="flex rounded-xl bg-gray-100 p-1 max-sm:mb-1.5 dark:bg-gray-900">
                    <button @click="view = 'plan'" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium" :class="view === 'plan' ? 'bg-white shadow dark:bg-gray-700' : 'text-gray-500'"><Squares2X2Icon class="h-4 w-4" /> <span class="max-sm:sr-only">{{ t('library.map.plan', 'المخطط') }}</span></button>
                    <button @click="view = '3d'" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium" :class="view === '3d' ? 'bg-white shadow dark:bg-gray-700' : 'text-gray-500'"><CubeIcon class="h-4 w-4" /> <span class="max-sm:sr-only">{{ t('library.map.view3d', 'ثلاثي الأبعاد') }}</span></button>
                </div>
                    <span class="mx-0.5 h-6 w-px bg-gray-200 max-sm:hidden dark:bg-gray-700" />
                    <button v-for="g in toolGroups" :key="g.key" @click="toolGroup = g.key" class="-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 pb-2 pt-1.5 text-sm font-medium max-sm:flex-col max-sm:gap-0.5 max-sm:px-1.5 max-sm:pb-1.5 max-sm:pt-0 max-sm:text-[11px]"
                        :class="toolGroup === g.key ? 'border-[var(--accent-600)] text-[var(--accent-700)] dark:text-[var(--accent-400)]' : 'border-transparent text-gray-500 hover:text-gray-800 dark:hover:text-gray-200'">
                        <component :is="g.icon" class="h-4 w-4" /> {{ g.label }}
                    </button>
                    <span class="ms-auto max-sm:hidden" />
                    <button @click="lockAll = !lockAll" class="rounded-lg p-2 max-sm:hidden" :class="lockAll ? 'bg-slate-700 text-white' : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700'" :title="lockAll ? t('library.map.locked_all', 'التحريك مقفل') : t('library.map.lock_all_hint', 'يمنع تحريك أو تغيير حجم أي شيء بالخطأ. الاختيار وتعديل البيانات يبقى ممكناً.')"><component :is="lockAll ? LockClosedIcon : LockOpenIcon" class="h-4 w-4" /></button>
                    <button @click="toggleFull" class="rounded-lg p-2 max-sm:hidden" :class="full ? 'bg-[#1f2a44] text-white' : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700'" :title="full ? t('library.map.exit_full', 'خروج من ملء الشاشة') : t('library.map.full', 'ملء الشاشة')"><component :is="full ? ArrowsPointingInIcon : ArrowsPointingOutIcon" class="h-4 w-4" /></button>
                </div>
                <div class="flex flex-wrap items-center gap-1.5 p-2">
                    <template v-if="toolGroup === 'build'">
                    <button @click="openWizard" class="inline-flex items-center gap-1.5 rounded-lg bg-[#1f2a44] px-3 py-1.5 text-sm font-medium text-white hover:brightness-110"><BuildingOffice2Icon class="h-4 w-4" /> {{ t('library.map.wizard', 'معالج البناء') }}</button>
                    <button @click="pickTool('hall')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm" :class="tool === 'hall' ? 'bg-slate-600 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"><HomeModernIcon class="h-4 w-4" /> {{ t('library.map.kind.hall', 'قاعة / غرفة') }}</button>
                    <button @click="pickTool('corridor')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm" :class="tool === 'corridor' ? 'bg-slate-500 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"><ArrowsRightLeftIcon class="h-4 w-4" /> {{ t('library.map.kind.corridor', 'ممر') }}</button>
                    <button @click="pickTool('door')" :disabled="!storeyHalls.length" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm disabled:opacity-40" :class="tool === 'door' ? 'bg-emerald-600 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"><RectangleGroupIcon class="h-4 w-4" /> {{ t('library.map.kind.door', 'باب') }}</button>
                    <button @click="add('stairs')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><ChevronDoubleUpIcon class="h-4 w-4" /> {{ t('library.map.kind.stairs', 'درج / مصعد') }}</button>
                    <button @click="placingEntrance = !placingEntrance; view = 'plan'" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm" :class="placingEntrance ? 'bg-emerald-600 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"><MapPinIcon class="h-4 w-4" /> {{ t('library.map.entrance', 'المدخل') }}</button>
                    <button @click="bgPanel = !bgPanel; view = 'plan'" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm" :class="bgPanel ? 'bg-sky-600 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"><PhotoIcon class="h-4 w-4" /> {{ t('library.map.bg', 'مخطط البناية') }}</button>
                    </template>
                    <template v-else-if="toolGroup === 'furniture'">
                    <button @click="add('shelf')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PlusIcon class="h-4 w-4" /> {{ t('library.map.add_shelf', 'رف') }}</button>
                    <button @click="add('double')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PlusIcon class="h-4 w-4" /> {{ t('library.map.add_double', 'رف بوجهين') }}</button>
                    <button @click="openGenerate" class="inline-flex items-center gap-1.5 rounded-lg bg-[var(--accent-50)] px-3 py-1.5 text-sm font-medium text-[var(--accent-700)] hover:brightness-95 dark:bg-gray-700 dark:text-gray-100"><SparklesIcon class="h-4 w-4" /> {{ t('library.map.generate', 'صفوف تلقائية') }}</button>
                    <button @click="add('desk')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PlusIcon class="h-4 w-4" /> {{ t('library.map.kind.desk', 'مكتب الإعارة') }}</button>
                    <button @click="add('table')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PlusIcon class="h-4 w-4" /> {{ t('library.map.kind.table', 'طاولة قراءة') }}</button>
                    <button @click="add('wall')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PlusIcon class="h-4 w-4" /> {{ t('library.map.kind.wall', 'جدار') }}</button>
                    <button @click="add('gate')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><ShieldCheckIcon class="h-4 w-4" /> {{ t('library.map.kind.gate', 'بوابة مانعة للسرقة') }}</button>
                    <button @click="startDrawing()" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm" :class="drawing ? 'bg-amber-500 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"><PencilIcon class="h-4 w-4" /> {{ t('library.map.draw_obstacle', 'ارسم عائقاً') }}</button>
                    </template>
                    <template v-else-if="toolGroup === 'stickers'">
                    <button @click="pickTool('qr')" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm" :class="tool === 'qr' ? 'bg-gray-900 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"><QrCodeIcon class="h-4 w-4" /> {{ t('library.map.kind.qr', 'ملصق QR') }}</button>
                    <button @click="autoQr" class="inline-flex items-center gap-1 rounded-lg bg-gray-900 px-2.5 py-1.5 text-sm font-medium text-white hover:brightness-125 dark:bg-gray-600"><SparklesIcon class="h-4 w-4" /> {{ t('library.map.auto_qr', 'QR تلقائي') }}</button>
                <a :href="route('library.manage.map.labels')" target="_blank" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><PrinterIcon class="h-4 w-4" /> {{ t('library.map.print_labels', 'اطبع الملصقات') }}</a>
                    </template>
                    <template v-else>
                    <button @click="multiMode = !multiMode; if (!multiMode) multi = new Set(); else selectedKey = null" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm" :class="multiMode ? 'bg-blue-600 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"><RectangleStackIcon class="h-4 w-4" /> {{ t('library.map.multi', 'تحديد عدة رفوف') }}</button>
                    <button @click="runCheck" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700"><ClipboardDocumentCheckIcon class="h-4 w-4" /> {{ t('library.map.check', 'فحص الخريطة') }}</button>
                    <button @click="lockAll = !lockAll" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm" :class="lockAll ? 'bg-slate-700 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'" :title="t('library.map.lock_all_hint', 'يمنع تحريك أو تغيير حجم أي شيء بالخطأ. الاختيار وتعديل البيانات يبقى ممكناً.')"><component :is="lockAll ? LockClosedIcon : LockOpenIcon" class="h-4 w-4" /> {{ lockAll ? t('library.map.locked_all', 'التحريك مقفل') : t('library.map.lock_all', 'قفل التحريك') }}</button>
                    <button @click="toggleFull" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm" :class="full ? 'bg-[#1f2a44] text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"><component :is="full ? ArrowsPointingInIcon : ArrowsPointingOutIcon" class="h-4 w-4" /> {{ full ? t('library.map.exit_full', 'خروج من ملء الشاشة') : t('library.map.full', 'ملء الشاشة') }}</button>
                    </template>
                </div>
            </div>

            <!-- «مخطط البناية» -->
            <div v-if="bgPanel" class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-2xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-950 dark:border-sky-800 dark:bg-sky-900/30 dark:text-sky-100">
                <input ref="bgInput" type="file" accept="image/png,image/jpeg,image/webp,application/pdf,.pdf" class="hidden" @change="uploadPlan" />
                <button @click="bgInput.click()" :disabled="bgUploading" class="inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-3 py-1.5 font-medium text-white disabled:opacity-50">
                    <ArrowPathRoundedSquareIcon v-if="bgUploading" class="h-4 w-4 animate-spin" /><PhotoIcon v-else class="h-4 w-4" />
                    {{ bg ? t('library.map.bg_replace', 'استبدل الصورة') : t('library.map.bg_upload', 'ارفع صورة أو PDF للمخطط') }}
                </button>
                <template v-if="bg">
                    <button @click="startCalibration" class="inline-flex items-center gap-1 rounded-lg border border-sky-300 bg-white px-2.5 py-1.5 font-medium dark:border-sky-700 dark:bg-sky-950"><ArrowsPointingOutIcon class="h-4 w-4" /> {{ t('library.map.calib', 'معايرة القياس') }}</button>
                    <button @click="bgMove = !bgMove; calib = null" class="rounded-lg px-2.5 py-1.5" :class="bgMove ? 'bg-sky-700 text-white' : 'border border-sky-300 bg-white dark:border-sky-700 dark:bg-sky-950'">{{ bgMove ? t('common.done', 'تم') : t('library.map.bg_move', 'حرّك الصورة') }}</button>
                    <label class="flex items-center gap-1.5">{{ t('library.map.bg_width', 'عرضها') }} <input type="number" min="1" max="1000" step="0.5" :value="bg.width" @change="bg.width = Math.max(1, +$event.target.value || bg.width)" class="w-20 rounded-md border-sky-300 py-1 text-sm dark:border-sky-700 dark:bg-sky-950" /> {{ t('library.map.m', 'م') }}</label>
                    <label class="flex items-center gap-1.5">{{ t('library.map.bg_opacity', 'الوضوح') }} <input type="range" min="0.1" max="1" step="0.05" v-model.number="bg.opacity" class="w-24 accent-sky-600" /></label>
                    <label class="flex items-center gap-1.5"><input type="checkbox" :checked="!bg.hidden" @change="bg.hidden = !$event.target.checked" class="rounded border-sky-400" /> {{ t('library.map.bg_show', 'ظاهرة') }}</label>
                    <button @click="removePlan" class="ms-auto rounded-lg p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" :title="t('common.delete', 'حذف')"><TrashIcon class="h-4 w-4" /></button>
                </template>
                <p class="w-full text-xs leading-5 opacity-80">{{ bg ? t('library.map.bg_hint', 'ارسم القاعات والممرات فوق الصورة. «معايرة القياس»: اضغط على طرفي جدار تعرف طوله ثم اكتب طوله بالمتر، فتأخذ الصورة قياسها الصحيح. الصورة لك فقط ولا تظهر للقراء.') : t('library.map.bg_intro', 'صورة لمخطط البناية (من الهاتف أو ملف PDF من المهندس) تحت الرسم، لكل طابق صورته. ترسم فوقها القاعات بسرعة وبالقياس الصحيح.') }}</p>
                <p v-if="bgError" class="w-full text-xs font-medium text-red-600">{{ bgError }}</p>
            </div>
            <div v-if="calib" class="flex flex-wrap items-center gap-2 rounded-lg bg-sky-600 px-3 py-2 text-sm text-white">
                <ArrowsPointingOutIcon class="h-5 w-5 shrink-0" />
                <span class="flex-1">{{ calib.points.length ? t('library.map.calib_2', 'الآن اضغط على الطرف الثاني.') : t('library.map.calib_1', 'اضغط على طرف جدار (أو باب) تعرف طوله في الصورة، ثم على طرفه الآخر.') }}</span>
                <button @click="calib = null" class="rounded-md px-2.5 py-1 underline">{{ t('common.cancel', 'إلغاء') }}</button>
            </div>
            <p v-if="multiMode && !multi.size" class="rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-900 dark:bg-blue-900/30 dark:text-blue-100">{{ t('library.map.multi_hint', 'اضغط على الرفوف لتحديدها، أو اسحب مربعاً حولها. (على الحاسوب: Shift + نقر يعمل دائماً)') }}</p>
            <p v-if="bgMove" class="rounded-lg bg-sky-100 px-3 py-2 text-sm text-sky-900 dark:bg-sky-900/40 dark:text-sky-100">{{ t('library.map.bg_move_hint', 'اسحب الصورة لتضعها في مكانها، ثم اضغط «تم».') }}</p>

            <div v-if="drawing" class="flex flex-wrap items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
                <span class="flex-1">{{ t('library.map.draw_hint', 'اضغط على المخطط عند كل زاوية من زوايا العائق بالترتيب، ثم اضغط على النقطة الأولى أو «إنهاء الشكل». (رجوع يحذف آخر نقطة)') }}</span>
                <span class="font-semibold">{{ t('library.map.points', ':n نقاط', { n: drawing.points.length }) }}</span>
                <button @click="drawing.points.pop()" :disabled="!drawing.points.length" class="rounded-md border border-amber-300 px-2.5 py-1 disabled:opacity-40">{{ t('library.map.undo_point', 'تراجع') }}</button>
                <button @click="finishDrawing" :disabled="drawing.points.length < 3" class="rounded-md bg-amber-600 px-3 py-1 font-medium text-white disabled:opacity-40">{{ t('library.map.finish_shape', 'إنهاء الشكل') }}</button>
                <button @click="drawing = null" class="rounded-md px-2.5 py-1 underline">{{ t('common.cancel', 'إلغاء') }}</button>
            </div>
            <div v-if="tool" class="flex flex-wrap items-center gap-2 rounded-lg px-3 py-2 text-sm" :class="tool === 'door' ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200' : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200'">
                <div v-if="tool === 'hall' || tool === 'corridor'" class="flex rounded-lg bg-white p-0.5 ring-1 ring-slate-300 dark:bg-slate-900 dark:ring-slate-600">
                    <button @click="rectShape = 'rect'" class="flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" :class="rectShape === 'rect' ? 'bg-slate-700 text-white' : ''">
                        <svg viewBox="0 0 16 16" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2" y="3" width="12" height="10" /></svg> {{ t('library.map.shape_rect', 'مستطيل') }}
                    </button>
                    <button @click="rectShape = 'L'" class="flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" :class="rectShape === 'L' ? 'bg-slate-700 text-white' : ''">
                        <svg viewBox="0 0 16 16" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 2h6v6h6v6H2z" /></svg> {{ t('library.map.shape_l_room', 'حرف L') }}
                    </button>
                </div>
                <span class="flex-1">
                    <template v-if="tool === 'door'">{{ doorHint ? t('library.map.door_far', 'اضغط على جدار قاعة أو ممر (قريباً منه).') : t('library.map.door_hint', 'اضغط على أي جدار ليوضع الباب عليه، وكرر لكل باب. اسحب الباب لتحريكه على الجدار.') }}</template>
                    <template v-else-if="tool === 'qr'">{{ doorHint ? t('library.map.qr_far', 'اضغط داخل القاعة قريباً من الجدار.') : t('library.map.qr_hint_tool', 'اضغط داخل القاعة قرب الجدار الذي ستلصق عليه الملصق، وكرر لكل ملصق. يتجه الملصق نحو الجهة التي ضغطت فيها.') }}</template>
                    <template v-else-if="rectShape === 'L'">{{ t('library.map.l_hint', 'اسحب من زاوية الغرفة إلى الزاوية المقابلة. بعدها «غيّر الزاوية المقطوعة» يقلب الـ L، واسحب الزاوية الداخلية لتغيير عرض الذراعين.') }}</template>
                    <template v-else>{{ t('library.map.rect_hint', 'اسحب على المخطط من زاوية إلى الزاوية المقابلة. تلتصق الزوايا بجدران القاعات المجاورة.') }}</template>
                </span>
                <button v-if="tool !== 'door' && tool !== 'qr'" @click="startDrawing(tool)" class="rounded-md border border-current/30 px-2.5 py-1 text-xs">{{ t('library.map.free_shape', 'شكل حر بالنقاط') }}</button>
                <button @click="tool = null" class="rounded-md px-2.5 py-1 underline">{{ tool === 'door' || tool === 'qr' ? t('common.done', 'تم') : t('common.cancel', 'إلغاء') }}</button>
            </div>
            <p v-if="qrNote" class="flex items-start gap-2 rounded-lg bg-gray-900 px-3 py-2 text-sm text-white dark:bg-gray-700"><QrCodeIcon class="mt-0.5 h-4 w-4 shrink-0" /> {{ qrNote }}</p>
            <p v-if="placingEntrance" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">{{ t('library.map.place_entrance', 'اضغط على المخطط في مكان باب المكتبة.') }}</p>

            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-sm text-gray-500">{{ t('library.map.storeys', 'الطوابق') }}:</span>
                <button v-for="(n, i) in floor.storeys" :key="i" @click="storey = i; selectedKey = null"
                    class="rounded-full px-3 py-1 text-sm" :class="storey === i ? 'bg-[#1f2a44] font-semibold text-white' : 'bg-white ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700'">
                    {{ storeyLabel(i) }} <span class="text-xs opacity-60">({{ items.filter((x) => storeyOf(x) === i && x.kind === 'shelf').length }})</span>
                </button>
                <button @click="addStorey" class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm text-[var(--accent-700)] hover:bg-[var(--accent-50)]"><PlusIcon class="h-4 w-4" /> {{ t('library.map.add_storey', 'طابق') }}</button>
            </div>

            <div class="grid lg:grid-cols-[1fr_20rem]" :class="full ? 'min-h-0 flex-1 gap-2 max-lg:overflow-y-auto lg:grid-rows-[minmax(0,1fr)]' : 'gap-4'">
                <!-- Plan or 3D -->
                <div class="relative min-h-[380px] overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800" :class="full ? 'h-full max-lg:h-[78dvh]' : 'h-[62dvh]'">
                    <p v-if="lockedNote" class="pointer-events-none absolute inset-x-0 top-3 z-10 mx-auto flex w-fit items-center gap-1.5 rounded-full bg-slate-800/90 px-3 py-1.5 text-xs text-white shadow"><LockClosedIcon class="h-4 w-4" /> {{ lockedNote }}</p>
                    <div v-show="view === 'plan'" ref="planBox" dir="ltr" class="h-full w-full overflow-auto overscroll-contain"
                        @pointerdown.capture="gestureDown" @pointermove.capture="gestureMove" @pointerup.capture="gestureUp" @pointercancel.capture="gestureUp" @wheel="onWheel">
                        <svg ref="svg" :viewBox="`-0.6 -0.6 ${floor.width + 1.2} ${floor.depth + 1.2}`" class="block touch-none select-none" :class="placingEntrance || drawing || tool || calib ? 'cursor-crosshair' : bgMove ? 'cursor-move' : ''"
                            :style="{ width: `${zoom * 100}%`, height: `${zoom * 100}%` }" preserveAspectRatio="xMidYMid meet"
                            @pointerdown="onPlanDown" @pointermove="onMove" @pointerup="endDrag" @pointercancel="endDrag" @click="onPlanClick" @dblclick="drawing && finishDrawing()">
                            <defs>
                                <pattern id="hazard" width="0.4" height="0.4" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                                    <rect width="0.4" height="0.4" fill="#6b7280" /><rect width="0.2" height="0.4" fill="#4b5563" />
                                </pattern>
                            </defs>
                            <rect data-bg="1" x="0" y="0" :width="floor.width" :height="floor.depth" class="fill-[#f8f5ef] dark:fill-gray-900" />
                            <image v-if="bg && !bg.hidden" :href="bg.url" :x="bg.x" :y="bg.y" :width="bg.width" :height="bg.width * bg.aspect" preserveAspectRatio="none" :opacity="bg.opacity" data-bg="1" />
                            <line v-for="(g, i) in gridLines" :key="i" v-bind="g" data-bg="1" :stroke-width="g.major ? 0.03 : 0.012" class="stroke-gray-300 dark:stroke-gray-700" />
                            <rect x="0" y="0" :width="floor.width" :height="floor.depth" fill="none" stroke-width="0.12" class="stroke-gray-400 dark:stroke-gray-500" />

                            <g v-for="s in planItems" :key="s._key" :transform="corners(s)" class="cursor-grab active:cursor-grabbing" :class="drawing || calib || bgMove ? 'pointer-events-none opacity-60' : (tool === 'door' && s.kind !== 'door') || (tool === 'qr' && s.kind !== 'qr') ? 'pointer-events-none' : ''" @pointerdown.stop="startDrag($event, s); pressStart($event, s)" @pointerup="pressEnd" @contextmenu.prevent.stop="openCtx($event, s)">
                                <template v-if="isHallKind(s)">
                                    <!-- A hall or corridor: its floor, and its sides drawn as walls. -->
                                    <polygon :points="polyPoints(s)" :fill="s.kind === 'corridor' ? '#e2e8f0' : '#fbf8f2'" class="dark:opacity-30" stroke-linejoin="miter"
                                        :stroke="s._key === selectedKey ? '#f59e0b' : '#475569'" :stroke-width="s._key === selectedKey ? 0.2 : 0.15" />
                                    <text :transform="`rotate(${-s.rotation})`" text-anchor="middle" dominant-baseline="central" :font-size="s.kind === 'corridor' ? fontSize * 0.9 : fontSize * 1.6" font-weight="700" :fill="s.kind === 'corridor' ? '#64748b' : '#94a3b8'" opacity="0.9" class="pointer-events-none">{{ s.code }}</text>
                                    <template v-if="s._key === selectedKey && canMove(s)">
                                        <circle v-for="m in midPoints(s)" :key="'m' + m[2]" :cx="m[0]" :cy="m[1]" r="0.16" fill="#fff" fill-opacity="0.7" stroke="#f59e0b" stroke-width="0.04" class="cursor-copy" @pointerdown.stop="startVertexDrag($event, s, m[2], true)" />
                                        <circle v-for="(p, i) in s.points" :key="'v' + i" :cx="p[0]" :cy="p[1]" r="0.24" fill="#f59e0b" stroke="#fff" stroke-width="0.05" class="cursor-move" @pointerdown.stop="startVertexDrag($event, s, i)" @dblclick.stop="removeVertex(s, i)" />
                                    </template>
                                </template>
                                <template v-else-if="s.kind === 'door'">
                                    <rect :x="-s.width / 2" :y="-0.14" :width="s.width" height="0.28" fill="#10b981" :stroke="s._key === selectedKey ? '#f59e0b' : '#fff'" :stroke-width="s._key === selectedKey ? 0.08 : 0.04" />
                                </template>
                                <template v-else-if="s.kind === 'qr'">
                                    <!-- A small QR plate on the wall; the triangle shows the side readers scan it from. -->
                                    <polygon points="-0.14,0.12 0.14,0.12 0,0.32" fill="#111827" opacity="0.5" />
                                    <rect x="-0.2" y="-0.1" width="0.4" height="0.2" rx="0.03" fill="#111827" :stroke="s._key === selectedKey ? '#f59e0b' : '#fff'" :stroke-width="s._key === selectedKey ? 0.07 : 0.03" />
                                    <text :transform="`rotate(${-s.rotation})`" text-anchor="middle" dominant-baseline="central" font-size="0.13" font-weight="800" fill="#fff" class="pointer-events-none">QR</text>
                                </template>
                                <template v-else-if="s.kind === 'stairs'">
                                    <rect :x="-s.width / 2" :y="-s.depth / 2" :width="s.width" :height="s.depth" fill="#ede9fe" :stroke="s._key === selectedKey ? '#f59e0b' : '#7c3aed'" :stroke-width="s._key === selectedKey ? 0.09 : 0.05" />
                                    <template v-if="s.double_sided">
                                        <line :x1="-s.width / 2" :y1="-s.depth / 2" :x2="s.width / 2" :y2="s.depth / 2" stroke="#7c3aed" stroke-width="0.04" />
                                        <line :x1="s.width / 2" :y1="-s.depth / 2" :x2="-s.width / 2" :y2="s.depth / 2" stroke="#7c3aed" stroke-width="0.04" />
                                    </template>
                                    <line v-else v-for="k in 7" :key="k" :x1="-s.width / 2" :x2="s.width / 2" :y1="-s.depth / 2 + (s.depth / 8) * k" :y2="-s.depth / 2 + (s.depth / 8) * k" stroke="#7c3aed" stroke-width="0.025" />
                                    <text :transform="`rotate(${-s.rotation})`" text-anchor="middle" dominant-baseline="central" :font-size="fontSize" font-weight="700" fill="#5b21b6" style="paint-order: stroke" stroke="#fff" :stroke-width="fontSize / 6">{{ s.code }}</text>
                                </template>
                                <template v-if="s.kind === 'obstacle' && s.points?.length >= 3">
                                    <polygon :points="polyPoints(s)" :fill="s.color || 'url(#hazard)'" fill-opacity="0.85" stroke-linejoin="round"
                                        :stroke="s._key === selectedKey ? '#f59e0b' : '#facc15'" :stroke-width="s._key === selectedKey ? 0.09 : 0.06" />
                                    <template v-if="s._key === selectedKey && canMove(s)">
                                        <circle v-for="m in midPoints(s)" :key="'m' + m[2]" :cx="m[0]" :cy="m[1]" r="0.11" fill="#fff" fill-opacity="0.7" stroke="#f59e0b" stroke-width="0.03" class="cursor-copy" @pointerdown.stop="startVertexDrag($event, s, m[2], true)" />
                                        <circle v-for="(p, i) in s.points" :key="'v' + i" :cx="p[0]" :cy="p[1]" r="0.16" fill="#f59e0b" stroke="#fff" stroke-width="0.04" class="cursor-move" @pointerdown.stop="startVertexDrag($event, s, i)" @dblclick.stop="removeVertex(s, i)" />
                                    </template>
                                </template>
                                <template v-if="s.kind === 'gate'">
                                    <!-- Two posts; the dashed line is the passage readers walk through. -->
                                    <rect :x="-s.width / 2" :y="-s.depth / 2" :width="s.width" :height="s.depth" fill="transparent" :stroke="s._key === selectedKey ? '#f59e0b' : 'none'" :stroke-width="0.06" />
                                    <rect v-for="side in [1, -1]" :key="side" :x="side * (s.width / 2 - 0.075) - 0.075" :y="-s.depth / 2" width="0.15" :height="s.depth" rx="0.03" fill="#dc2626" />
                                    <line :x1="-s.width / 2 + 0.2" y1="0" :x2="s.width / 2 - 0.2" y2="0" stroke="#dc2626" stroke-width="0.04" stroke-dasharray="0.1 0.08" />
                                </template>
                                <rect v-else-if="!['obstacle', 'hall', 'corridor', 'door', 'stairs', 'qr'].includes(s.kind)" :x="-s.width / 2" :y="-s.depth / 2" :width="s.width" :height="s.depth" :rx="s.kind === 'table' ? 0.1 : 0.04"
                                    :fill="fill(s)" :fill-opacity="s.kind === 'shelf' ? 0.85 : 0.7"
                                    :stroke="s._key === selectedKey ? '#f59e0b' : 'rgba(0,0,0,0.25)'" :stroke-width="s._key === selectedKey ? 0.09 : 0.025" />
                                <!-- Front face: where readers stand. -->
                                <line v-if="s.kind === 'shelf'" :x1="-s.width / 2 + 0.05" :y1="s.depth / 2" :x2="s.width / 2 - 0.05" :y2="s.depth / 2" stroke="#fff" stroke-width="0.07" stroke-dasharray="0.15 0.08" />
                                <line v-if="s.kind === 'shelf' && s.double_sided" :x1="-s.width / 2 + 0.05" :y1="-s.depth / 2" :x2="s.width / 2 - 0.05" :y2="-s.depth / 2" stroke="#fff" stroke-width="0.07" stroke-dasharray="0.15 0.08" />
                                <g v-if="s.locked" :transform="`translate(${(s.points?.length >= 3 ? Math.max(...s.points.map((q) => q[0])) : s.width / 2) - 0.25} ${(s.points?.length >= 3 ? Math.min(...s.points.map((q) => q[1])) : -s.depth / 2) + 0.25}) rotate(${-s.rotation})`" class="pointer-events-none">
                                    <circle r="0.22" fill="#334155" stroke="#fff" stroke-width="0.04" />
                                    <rect x="-0.1" y="-0.02" width="0.2" height="0.14" rx="0.02" fill="#fff" />
                                    <path d="M-0.06 -0.02 v-0.05 a0.06 0.06 0 0 1 0.12 0 v0.05" fill="none" stroke="#fff" stroke-width="0.03" />
                                </g>
                                <rect v-if="multi.has(s._key)" :x="-s.width / 2 - 0.12" :y="-s.depth / 2 - 0.12" :width="s.width + 0.24" :height="s.depth + 0.24" rx="0.08" fill="rgba(37,99,235,0.15)" stroke="#2563eb" stroke-width="0.08" stroke-dasharray="0.2 0.1" class="pointer-events-none" />
                                <text v-if="!['wall', 'gate', 'obstacle', 'hall', 'corridor', 'door', 'stairs', 'qr'].includes(s.kind)" :transform="`rotate(${-s.rotation})`" text-anchor="middle" dominant-baseline="central" :font-size="fontSize" font-weight="700" fill="#fff" style="paint-order: stroke" stroke="rgba(0,0,0,0.35)" :stroke-width="fontSize / 8">{{ s.code }}</text>
                            </g>

                            <rect v-if="rectBox && rect.kind === 'select'" v-bind="rectBox" class="pointer-events-none" fill="rgba(37,99,235,0.12)" stroke="#2563eb" stroke-width="0.08" stroke-dasharray="0.25 0.12" />
                            <polygon v-if="rectL" :points="rectL" class="pointer-events-none" :fill="rect.kind === 'corridor' ? 'rgba(148,163,184,0.35)' : 'rgba(71,85,105,0.18)'" stroke="#475569" stroke-width="0.12" stroke-dasharray="0.3 0.15" />
                            <rect v-if="rectBox && !rectL && rect.kind !== 'select'" v-bind="rectBox" class="pointer-events-none" :fill="rect.kind === 'corridor' ? 'rgba(148,163,184,0.35)' : 'rgba(71,85,105,0.18)'" stroke="#475569" stroke-width="0.12" stroke-dasharray="0.3 0.15" />
                            <text v-if="rectBox && rectBox.width > 1" :x="rectBox.x + rectBox.width / 2" :y="rectBox.y + rectBox.height / 2" text-anchor="middle" dominant-baseline="central" :font-size="fontSize * 1.2" font-weight="700" fill="#334155" class="pointer-events-none" dir="ltr">{{ rectBox.width.toFixed(1) }} × {{ rectBox.height.toFixed(1) }} m</text>
                            <g v-if="calib" class="pointer-events-none">
                                <line v-if="calibLine" v-bind="calibLine" stroke="#0284c7" stroke-width="0.08" stroke-dasharray="0.2 0.1" />
                                <circle v-for="(q, i) in calib.points" :key="i" :cx="q.x" :cy="q.y" r="0.18" fill="#0284c7" stroke="#fff" stroke-width="0.05" />
                            </g>
                            <g v-if="drawing" class="pointer-events-none">
                                <polyline :points="drawPath" fill="rgba(245,158,11,0.15)" stroke="#f59e0b" stroke-width="0.06" stroke-dasharray="0.15 0.08" />
                                <circle v-for="(p, i) in drawing.points" :key="i" :cx="p.x" :cy="p.y" :r="i === 0 ? 0.2 : 0.12" :fill="i === 0 ? '#fff' : '#f59e0b'" stroke="#f59e0b" stroke-width="0.05" />
                            </g>
                            <!-- «مثل كانفا»: size handles, and the round handle above to turn it. -->
                            <g v-if="handleBox && view === 'plan'" :transform="corners(selected)">
                                <rect :x="handleBox.x0" :y="handleBox.y0" :width="handleBox.x1 - handleBox.x0" :height="handleBox.y1 - handleBox.y0" fill="none" :stroke="handleBox.locked ? '#64748b' : '#2563eb'" :stroke-width="hR * 0.3" :stroke-dasharray="`${hR} ${hR * 0.6}`" class="pointer-events-none" />
                                <template v-if="!handleBox.locked">
                                    <line x1="0" :y1="handleBox.y0" x2="0" :y2="handleBox.y0 - hR * 4" stroke="#2563eb" :stroke-width="hR * 0.3" class="pointer-events-none" />
                                    <g :transform="`translate(0 ${handleBox.y0 - hR * 4})`" class="cursor-grab" @pointerdown.stop="startRotate">
                                        <circle :r="hR * 1.15" fill="#fff" stroke="#2563eb" :stroke-width="hR * 0.3" />
                                        <path :d="`M ${-hR * 0.55} 0 A ${hR * 0.55} ${hR * 0.55} 0 1 1 0 ${hR * 0.55}`" fill="none" stroke="#2563eb" :stroke-width="hR * 0.25" class="pointer-events-none" />
                                    </g>
                                    <text v-if="rotating" x="0" :y="handleBox.y0 - hR * 6.5" :transform="`rotate(${-selected.rotation} 0 ${handleBox.y0 - hR * 6.5})`" text-anchor="middle" dominant-baseline="central" :font-size="hR * 2.4" font-weight="800" fill="#2563eb" style="paint-order: stroke" stroke="#fff" :stroke-width="hR * 0.5" class="pointer-events-none">{{ selected.rotation }}°</text>
                                    <rect v-for="h in shownHandles" :key="`${h.hx}${h.hy}`" :x="h.x - hR" :y="h.y - hR" :width="hR * 2" :height="hR * 2" :rx="hR * 0.35" fill="#fff" stroke="#2563eb" :stroke-width="hR * 0.3" :style="{ cursor: h.cursor }" @pointerdown.stop="startResize($event, h)" />
                                </template>
                            </g>
                            <!-- «تدوير المجموعة»: the frame round the selection, and its turn handle. -->
                            <g v-if="multiBox && view === 'plan'">
                                <rect :x="multiBox.x0" :y="multiBox.y0" :width="multiBox.x1 - multiBox.x0" :height="multiBox.y1 - multiBox.y0" fill="none" :stroke="multiBox.locked ? '#64748b' : '#2563eb'" :stroke-width="hR * 0.3" :stroke-dasharray="`${hR} ${hR * 0.6}`" class="pointer-events-none" />
                                <template v-if="!multiBox.locked">
                                    <line :x1="multiBox.cx" :y1="multiBox.y0" :x2="multiBox.cx" :y2="multiBox.y0 - hR * 4" stroke="#2563eb" :stroke-width="hR * 0.3" class="pointer-events-none" />
                                    <g :transform="`translate(${multiBox.cx} ${multiBox.y0 - hR * 4})`" class="cursor-grab" @pointerdown.stop="startMultiRotate">
                                        <circle :r="hR * 1.3" fill="#2563eb" stroke="#fff" :stroke-width="hR * 0.3" />
                                        <path :d="`M ${-hR * 0.6} 0 A ${hR * 0.6} ${hR * 0.6} 0 1 1 0 ${hR * 0.6}`" fill="none" stroke="#fff" :stroke-width="hR * 0.28" class="pointer-events-none" />
                                    </g>
                                    <text v-if="multiTurn !== null" :x="multiBox.cx" :y="multiBox.y0 - hR * 7" text-anchor="middle" dominant-baseline="central" :font-size="hR * 2.4" font-weight="800" fill="#2563eb" style="paint-order: stroke" stroke="#fff" :stroke-width="hR * 0.5" class="pointer-events-none">{{ multiTurn }}°</text>
                                </template>
                            </g>
                            <g v-if="storey === 0" :transform="`translate(${floor.entrance.x} ${floor.entrance.y})`" class="cursor-grab" @pointerdown.stop="startEntranceDrag">
                                <circle r="0.45" fill="#10b981" stroke="#fff" stroke-width="0.08" />
                                <text text-anchor="middle" dominant-baseline="central" font-size="0.32" font-weight="700" fill="#fff">{{ t('library.map.door', 'باب') }}</text>
                            </g>
                        </svg>
                    </div>
                    <LibraryMap3D v-if="view === '3d'" :floor="floor" :shelves="preview" :highlight="selected ? { id: selected.id ?? selected._key } : null" editable :can-drag="(id) => canMove(byPreviewId(id))"
                        @pick="(id) => (selectedKey = byPreviewId(id)?._key ?? null)" @move="on3dMove" @context="on3dContext" @dragging="on3dDragging" />
                    <p v-if="view === '3d'" class="pointer-events-none absolute inset-x-0 bottom-3 mx-auto w-fit rounded-full bg-white/90 px-3 py-1.5 text-xs text-gray-600 shadow dark:bg-gray-800/90 dark:text-gray-300">{{ t('library.map.edit3d_hint', 'اسحب أي رف لتحريكه داخل غرفته (Alt لنقله لغرفة أخرى) · اسحب الأرض لتدوير المشهد · زر الماوس الأيمن على أي عنصر لخياراته') }}</p>

                    <div v-if="view === 'plan'" class="absolute bottom-3 end-3 flex gap-1.5">
                        <button @click="zoomAt(zoom / 1.5)" class="rounded-lg bg-white/95 p-2 shadow ring-1 ring-black/5 dark:bg-gray-700"><MagnifyingGlassMinusIcon class="h-5 w-5" /></button>
                        <button @click="zoomAt(zoom * 1.5)" class="rounded-lg bg-white/95 p-2 shadow ring-1 ring-black/5 dark:bg-gray-700"><MagnifyingGlassPlusIcon class="h-5 w-5" /></button>
                    </div>
                    <div v-if="view === 'plan' && !onStorey.length && !tool && !drawing" class="absolute inset-x-0 top-1/4 mx-auto max-w-sm space-y-3 rounded-2xl bg-white/90 p-5 text-center shadow ring-1 ring-black/5 dark:bg-gray-800/90">
                        <BuildingOffice2Icon class="mx-auto h-10 w-10 text-[var(--accent-600)]" />
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('library.map.empty_plan_wizard', 'ابدأ بـ «معالج البناء»: اختر غرفة أو بناية وعدد الطوابق، فتُبنى القاعات والممرات والأبواب تلقائياً.') }}</p>
                        <div class="flex flex-wrap justify-center gap-2">
                            <button @click="openWizard" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm font-medium text-white">{{ t('library.map.wizard', 'معالج البناء') }}</button>
                            <button @click="bgPanel = true; $nextTick(() => bgInput?.click())" class="inline-flex items-center gap-1.5 rounded-lg border border-sky-300 px-4 py-2 text-sm font-medium text-sky-700 dark:border-sky-700 dark:text-sky-300"><PhotoIcon class="h-4 w-4" /> {{ t('library.map.bg_from_plan', 'ارسم فوق صورة المخطط') }}</button>
                        </div>
                    </div>
                </div>

                <!-- Properties -->
                <aside class="space-y-4" :class="full ? 'lg:min-h-0 lg:overflow-y-auto' : ''">
                    <section v-if="multi.size" class="space-y-3 rounded-2xl border-2 border-blue-200 bg-white p-4 text-sm dark:border-blue-800 dark:bg-gray-800">
                        <div class="flex items-center gap-2">
                            <RectangleStackIcon class="h-5 w-5 text-blue-600" />
                            <h3 class="flex-1 font-semibold">{{ t('library.map.multi_count', ':n محدد (:s رف)', { n: multi.size, s: multiShelves.length }) }}</h3>
                            <button @click="multiItems.forEach((i) => (i.locked = !multiItems.every((x) => x.locked)))" class="rounded-lg p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('library.map.lock_selected', 'قفل المحدد / فتحه')"><component :is="multiItems.every((x) => x.locked) ? LockClosedIcon : LockOpenIcon" class="h-5 w-5" /></button>
                            <button @click="bulkRotate(90)" class="rounded-lg p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('library.map.rotate', 'تدوير 90°')"><ArrowPathRoundedSquareIcon class="h-5 w-5" /></button>
                            <button @click="bulkRemove" class="rounded-lg p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" :title="t('common.delete', 'حذف')"><TrashIcon class="h-5 w-5" /></button>
                            <button @click="multi = new Set()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('library.map.unselect', 'إلغاء التحديد')"><XMarkIcon class="h-5 w-5" /></button>
                        </div>
                        <p v-if="multiIsOneGroup" class="flex items-center gap-1.5 rounded-lg bg-blue-50 px-2.5 py-1.5 text-xs text-blue-900 dark:bg-blue-900/30 dark:text-blue-100"><RectangleGroupIcon class="h-4 w-4 shrink-0" /> {{ t('library.map.is_group', 'مجموعة: تتحرك معاً. Alt + نقر يختار رفاً منها وحده.') }}</p>
                        <div class="grid grid-cols-2 gap-1.5">
                            <button v-if="!multiIsOneGroup && multi.size > 1" @click="groupSelected" class="inline-flex items-center justify-center gap-1 rounded-lg bg-blue-600 px-2 py-1.5 text-xs font-medium text-white"><RectangleGroupIcon class="h-4 w-4" /> {{ t('library.map.group_short', 'جمّعها') }}</button>
                            <button v-if="multiHasGroup" @click="ungroupSelected" class="inline-flex items-center justify-center gap-1 rounded-lg border border-blue-300 px-2 py-1.5 text-xs font-medium text-blue-700 dark:border-blue-700 dark:text-blue-200"><RectangleGroupIcon class="h-4 w-4" /> {{ t('library.map.ungroup', 'فك التجميع') }}</button>
                            <div class="col-span-2 flex items-center gap-1 rounded-lg bg-gray-50 p-1 dark:bg-gray-900/40">
                                <span class="px-1 text-xs text-gray-500">{{ t('library.map.turn_group', 'تدوير') }}</span>
                                <button v-for="deg in [-90, -15, 15, 90, 180]" :key="deg" @click="bulkRotate(deg)" class="flex-1 whitespace-nowrap rounded-md border border-gray-300 bg-white px-0.5 py-1 text-[11px] tabular-nums hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-800 dark:hover:bg-gray-700" dir="ltr">{{ deg > 0 && deg < 180 ? '↻' : deg < 0 ? '↺' : '⟲' }} {{ Math.abs(deg) }}°</button>
                            </div>
                            <button @click="bulkFlip" class="inline-flex items-center justify-center gap-1 rounded-lg border border-gray-300 px-2 py-1.5 text-xs dark:border-gray-600"><ArrowsRightLeftIcon class="h-4 w-4" /> {{ t('library.map.flip_short', 'اقلب الاتجاه') }}</button>
                            <button @click="bulkDuplicate" class="inline-flex items-center justify-center gap-1 rounded-lg border border-gray-300 px-2 py-1.5 text-xs dark:border-gray-600"><DocumentDuplicateIcon class="h-4 w-4" /> {{ t('library.map.duplicate_short', 'تكرار') }}</button>
                        </div>
                        <template v-if="multiShelves.length">
                            <label class="block">
                                <span class="text-gray-600 dark:text-gray-300">{{ t('library.map.bulk_section', 'القسم لكل الرفوف المحددة') }}</span>
                                <select @change="bulkSet('section_id', $event.target.value ? +$event.target.value : null); $event.target.value = '__'" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                                    <option value="__" disabled selected>{{ t('library.map.choose', 'اختر…') }}</option>
                                    <option value="">{{ t('library.map.no_section', 'بدون قسم') }}</option>
                                    <option v-for="sec in sections" :key="sec.id" :value="sec.id">{{ sec.path }}</option>
                                </select>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.levels', 'عدد الطبقات') }}</span>
                                    <input type="number" min="1" max="10" :placeholder="t('library.map.unchanged', 'بلا تغيير')" @change="$event.target.value && bulkSet('levels', Math.min(10, Math.max(1, +$event.target.value)))" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                                </label>
                                <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.faces', 'الوجه') }}</span>
                                    <select @change="bulkSet('double_sided', $event.target.value === '2')" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                                        <option value="" disabled selected>{{ t('library.map.unchanged', 'بلا تغيير') }}</option>
                                        <option value="1">{{ t('library.map.one_face', 'وجه واحد') }}</option>
                                        <option value="2">{{ t('library.map.double', 'بوجهين') }}</option>
                                    </select>
                                </label>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-2.5 dark:bg-gray-900/40">
                                <span class="text-gray-600 dark:text-gray-300">{{ t('library.map.bulk_codes', 'رقّمها بالترتيب (صفاً صفاً)') }}</span>
                                <div class="mt-1 flex items-center gap-1.5">
                                    <input v-model.trim="bulk.prefix" maxlength="10" dir="auto" class="w-16 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" />
                                    <input v-model.number="bulk.start" type="number" min="1" class="w-16 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" />
                                    <button @click="bulkNumber" class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-medium text-white">{{ t('library.map.bulk_number', 'رقّم') }}</button>
                                </div>
                                <p class="mt-1 text-[11px] text-gray-500" dir="auto">{{ bulk.prefix }}{{ bulk.start || 1 }}، {{ bulk.prefix }}{{ (bulk.start || 1) + 1 }}…</p>
                            </div>
                        </template>
                        <p class="text-xs leading-5 text-gray-500">{{ t('library.map.multi_keys', 'اسحب أي رف محدد لتحريكها كلها · الأسهم للتحريك الدقيق · Delete للحذف.') }}</p>
                    </section>
                    <section v-if="selected" ref="panelEl" class="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded-full" :style="{ background: fill(selected) }" />
                            <h3 class="font-semibold">{{ kindLabel(selected.kind) }}</h3>
                            <div class="ms-auto flex gap-1">
                                <button @click="toggleLock(selected)" class="rounded-lg p-1.5" :class="selected.locked ? 'bg-slate-700 text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'" :title="selected.locked ? t('library.map.unlock', 'فتح القفل') : t('library.map.lock', 'قفل (لا يتحرك بالخطأ)')"><component :is="selected.locked ? LockClosedIcon : LockOpenIcon" class="h-5 w-5" /></button>
                                <button @click="canMove(selected) ? rotate() : flashLocked(selected)" class="rounded-lg p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('library.map.rotate', 'تدوير 90°')"><ArrowPathRoundedSquareIcon class="h-5 w-5" /></button>
                                <button @click="duplicate" class="rounded-lg p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('library.map.duplicate', 'تكرار بجانبه')"><DocumentDuplicateIcon class="h-5 w-5" /></button>
                                <button @click="canMove(selected) ? remove() : flashLocked(selected)" class="rounded-lg p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" :title="t('common.delete', 'حذف')"><TrashIcon class="h-5 w-5" /></button>
                            </div>
                        </div>
                        <label class="block text-sm">
                            <span class="text-gray-600 dark:text-gray-300">{{ selected.kind === 'shelf' ? t('library.map.code', 'رمز الرف (يُكتب في حقل «الرف» للنسخة)') : isHallKind(selected) ? t('library.map.hall_name', 'اسم القاعة (يظهر للقارئ في الإرشادات)') : t('library.map.label', 'الاسم المختصر') }}</span>
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
                        <p v-if="selected.kind === 'qr'" class="rounded-lg bg-gray-50 p-2 text-xs leading-5 text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">{{ t('library.map.qr_item_hint', 'يُطبع من «ملصقات QR» ويُلصق على هذا الجدار بمستوى النظر. من يمسحه تظهر له الخريطة من هذا المكان. اسحبه لتحريكه على الجدار.') }}</p>
                        <label v-if="selected.kind !== 'door' && selected.kind !== 'qr'" class="flex items-center gap-2 text-sm">
                            <span class="text-gray-600 dark:text-gray-300">{{ t('library.map.angle', 'الزاوية') }}</span>
                            <input :value="selected.rotation" @change="setRotation(selected, $event.target.value)" type="number" step="5" min="-359" max="359" class="w-20 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                            <span class="text-gray-400">°</span>
                            <button v-for="a in [0, 45, 90, 135]" :key="a" @click="setRotation(selected, a)" class="rounded-md border border-gray-200 px-1.5 py-0.5 text-xs hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">{{ a }}°</button>
                        </label>
                        <div v-if="isHallKind(selected)" class="flex flex-wrap gap-1.5">
                            <button @click="selectHallShelves(selected)" class="inline-flex items-center gap-1 rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"><RectangleStackIcon class="h-3.5 w-3.5" /> {{ t('library.map.select_hall_shelves', 'حدد كل رفوفها') }}</button>
                            <button v-if="selected.kind === 'hall'" @click="openGenerate" class="inline-flex items-center gap-1 rounded-lg bg-[var(--accent-50)] px-2.5 py-1 text-xs font-medium text-[var(--accent-700)] dark:bg-gray-700 dark:text-gray-100"><SparklesIcon class="h-3.5 w-3.5" /> {{ t('library.map.rows_here', 'صفوف رفوف داخل القاعة') }}</button>
                            <button @click="pickTool('door')" class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300"><RectangleGroupIcon class="h-3.5 w-3.5" /> {{ t('library.map.add_door', 'أضف باباً') }}</button>
                        </div>
                        <p v-if="isHallKind(selected)" class="text-xs leading-5 text-gray-500">{{ t('library.map.hall_hint', 'تحريك القاعة أو تدويرها يحرّك ما بداخلها وأبوابها معها. اختر القاعة ثم أضف رفاً ليوضع داخلها بنفس اتجاهها.') }}</p>
                        <button v-if="selected.kind === 'stairs' && floor.storeys.length > 1" @click="stairsToAllFloors" class="inline-flex items-center gap-1 rounded-lg bg-violet-50 px-2.5 py-1 text-xs font-medium text-violet-700 dark:bg-violet-900/30 dark:text-violet-300"><Square2StackIcon class="h-3.5 w-3.5" /> {{ t('library.map.stairs_all', 'ضعه في نفس المكان بكل الطوابق') }}</button>
                        <label v-if="selected.kind === 'stairs'" class="flex items-center gap-2 rounded-lg bg-violet-50 px-2.5 py-2 text-sm dark:bg-violet-900/30">
                            <input v-model="selected.double_sided" type="checkbox" class="rounded border-gray-300" />
                            <span>{{ t('library.map.is_lift', 'هذا مصعد (يصلح للكراسي المتحركة)') }}</span>
                        </label>
                        <p v-if="selected.kind === 'stairs'" class="text-xs leading-5 text-gray-500">{{ t('library.map.stairs_hint', 'الدرج أو المصعد الموجود في نفس المكان بطابقين يربطهما، والمسار يصعد أو ينزل منه.') }}</p>
                        <div v-if="selected.kind !== 'qr'" class="grid grid-cols-2 gap-2 text-sm">
                            <template v-if="selected.kind === 'obstacle' || isHallKind(selected)">
                                <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.length', 'الطول (م)') }}</span><input :value="selected.width" @change="scaleObstacle('x', +$event.target.value)" type="number" step="0.1" min="0.3" max="200" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                                <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.depth', 'العمق (م)') }}</span><input :value="selected.depth" @change="scaleObstacle('y', +$event.target.value)" type="number" step="0.1" min="0.1" max="200" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                                <label v-if="selected.kind === 'obstacle'"><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.height', 'الارتفاع (م)') }}</span><input v-model.number="selected.height" type="number" step="0.1" min="0.1" max="6" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                                <div class="col-span-2">
                                    <span class="text-gray-600 dark:text-gray-300">{{ t('library.map.shape', 'الشكل') }}</span>
                                    <div class="mt-1 flex flex-wrap gap-1.5">
                                        <button @click="presetShape('rect')" class="rounded-md border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">{{ t('library.map.shape_rect', 'مستطيل') }}</button>
                                        <button v-if="selected.kind === 'obstacle'" @click="presetShape('circle')" class="rounded-md border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">{{ t('library.map.shape_circle', 'دائري (عمود)') }}</button>
                                        <button @click="presetShape('L')" class="rounded-md border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">{{ t('library.map.shape_l', 'زاوية L') }}</button>
                                        <button v-if="isL(selected)" @click="turnL" class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700"><ArrowPathRoundedSquareIcon class="h-3.5 w-3.5" /> {{ t('library.map.turn_l', 'غيّر الزاوية المقطوعة') }}</button>
                                    </div>
                                    <p class="mt-1.5 text-xs leading-5 text-gray-500">{{ t('library.map.shape_hint', 'اسحب الزوايا البرتقالية لتعديل الشكل · النقطة الباهتة في منتصف الضلع تضيف زاوية · نقر مزدوج على زاوية يحذفها.') }}</p>
                                </div>
                            </template>
                            <label v-else><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.length', 'الطول (م)') }}</span><input v-model.number="selected.width" type="number" step="0.1" min="0.3" max="30" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                            <label v-if="selected.kind !== 'obstacle' && !isHallKind(selected) && selected.kind !== 'door'"><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.depth', 'العمق (م)') }}</span><input v-model.number="selected.depth" type="number" step="0.05" min="0.1" max="10" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
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
                        <h3 class="font-semibold">{{ t('library.map.building', 'المكتبة') }}</h3>
                        <div class="space-y-1.5">
                            <span class="text-gray-600 dark:text-gray-300">{{ t('library.map.storey_names', 'أسماء الطوابق') }}</span>
                            <div v-for="(n, i) in floor.storeys" :key="i" class="flex items-center gap-1.5">
                                <input v-model="floor.storeys[i]" maxlength="60" dir="auto" class="min-w-0 flex-1 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" />
                                <button v-if="floor.storeys.length > 1" @click="removeStorey(i)" class="rounded-lg p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" :title="t('common.delete', 'حذف')"><TrashIcon class="h-4 w-4" /></button>
                            </div>
                        </div>
                        <label class="flex items-start gap-2"><input v-model="floor.separate_halls" type="checkbox" class="mt-0.5 rounded border-gray-300" />
                            <span>{{ t('library.map.separate_halls', 'كل قاعة مستقلة') }}<span class="block text-xs text-gray-500">{{ t('library.map.separate_halls_hint', 'القارئ يرى القاعة التي هو فيها وحدها، وتتبدل الخريطة عند دخوله قاعة أخرى.') }}</span></span>
                        </label>
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
                            <button v-for="s in planItems" :key="s._key" @click="selectedKey = s._key" class="flex w-full items-center gap-2 px-4 py-1.5 text-start text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50" :class="s._key === selectedKey ? 'bg-amber-50 dark:bg-amber-900/20' : ''">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: fill(s) }" />
                                <span class="font-medium" dir="auto">{{ s.code }}</span>
                                <span class="truncate text-xs text-gray-500">{{ s.kind === 'shelf' ? sectionPath(s.section_id) : kindLabel(s.kind) }}</span>
                            </button>
                        </div>
                    </section>
                </aside>
            </div>

            <!-- Save bar -->
            <div class="sticky bottom-0 z-20 border-t border-gray-200 bg-white/95 backdrop-blur dark:border-gray-700 dark:bg-gray-900/95" :class="full ? '-mx-2 -mb-2' : '-mx-4 sm:-mx-6'">
                <div class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
                    <p class="flex min-w-0 flex-1 items-center gap-1.5 text-sm">
                        <template v-if="errorList.length"><ExclamationCircleIcon class="h-5 w-5 shrink-0 text-red-500" /><span class="truncate text-red-600">{{ errorList[0] }}</span></template>
                        <template v-else-if="dirty"><span class="h-2 w-2 rounded-full bg-amber-500" /><span class="font-medium text-amber-600">{{ t('settings.unsaved', 'تغييرات غير محفوظة') }}</span></template>
                        <template v-else-if="justSaved"><CheckCircleIcon class="h-5 w-5 text-emerald-500" /><span class="text-emerald-600">{{ t('settings.saved', 'تم الحفظ') }}</span></template>
                    </p>
                    <div class="flex rounded-lg border border-gray-200 dark:border-gray-700">
                        <button @click="undo" :disabled="!undoStack.length" class="rounded-s-lg p-2 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-800" :title="t('library.map.undo', 'تراجع (Ctrl+Z)')"><ArrowUturnRightIcon class="h-5 w-5" /></button>
                        <button @click="redo" :disabled="!redoStack.length" class="rounded-e-lg border-s border-gray-200 p-2 hover:bg-gray-100 disabled:opacity-30 dark:border-gray-700 dark:hover:bg-gray-800" :title="t('library.map.redo', 'إعادة (Ctrl+Y)')"><ArrowUturnLeftIcon class="h-5 w-5" /></button>
                    </div>
                    <button v-if="dirty" @click="reset" class="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"><ArrowUturnRightIcon class="h-4 w-4" /> {{ t('library.map.discard_all', 'إلغاء كل التغييرات') }}</button>
                    <button @click="save" :disabled="saving || !dirty" class="rounded-lg bg-[var(--accent-600)] px-5 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50">{{ t('library.map.save', 'حفظ الخريطة') }}</button>
                </div>
            </div>
        </div>

        <p v-if="genNote" class="fixed inset-x-0 bottom-6 z-50 mx-auto w-fit max-w-[90vw] rounded-full bg-gray-900/90 px-4 py-2 text-center text-sm text-white shadow-lg">{{ genNote }}</p>
        <Modal :show="gen.open" @close="gen.open = false" max-width="lg">
            <div class="space-y-4 p-6">
                <div>
                    <h3 class="flex items-center gap-2 text-lg font-semibold"><SparklesIcon class="h-5 w-5 text-[var(--accent-600)]" /> {{ t('library.map.generate', 'صفوف تلقائية') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ t('library.map.generate_text2', 'اختر شكل التوزيع والقاعة؛ كل الرفوف تبقى داخل القاعة وبعيدة عن الأبواب والمكاتب، وترقّم A1، A2… B1… وبعدها تقدر تسحب أي رف لمكانه.') }}</p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <button v-for="l in LAYOUTS" :key="l.key" type="button" @click="gen.layout = l.key" class="flex flex-col items-center gap-1 rounded-xl border-2 p-2 text-center text-xs leading-4 transition" :class="gen.layout === l.key ? 'border-[var(--accent-600)] bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700 dark:text-gray-100' : 'border-gray-200 hover:border-gray-300 dark:border-gray-600'">
                        <svg viewBox="0 0 60 40" class="h-10 w-16">
                            <rect x="1" y="1" width="58" height="38" rx="3" fill="none" stroke="currentColor" stroke-opacity=".4" />
                            <template v-if="l.key === 'rows'"><rect v-for="r in 4" :key="r" x="8" :y="4 + r * 7" width="44" height="3.2" rx="1" fill="currentColor" /></template>
                            <template v-else>
                                <rect x="4" y="3" width="52" height="2.4" rx="1" fill="currentColor" /><rect x="4" y="34.6" width="52" height="2.4" rx="1" fill="currentColor" />
                                <rect x="3" y="8" width="2.4" height="24" rx="1" fill="currentColor" /><rect x="54.6" y="8" width="2.4" height="24" rx="1" fill="currentColor" />
                                <template v-if="l.key === 'walls_rows'"><rect v-for="r in 3" :key="r" x="14" :y="7 + r * 6.5" width="32" height="3.6" rx="1" fill="currentColor" fill-opacity=".7" /></template>
                            </template>
                        </svg>
                        {{ l.label }}
                    </button>
                </div>
                <label v-if="genHalls.length" class="block text-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_hall', 'القاعة') }}</span>
                    <select v-model="gen.hall" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                        <option :value="null">{{ t('library.map.gen_whole', 'كل المساحة') }}</option>
                        <option v-for="h in genHalls" :key="h._key" :value="h">{{ h.code }}</option>
                    </select>
                </label>
                <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                    <template v-if="gen.layout === 'rows'">
                        <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_rows', 'عدد الصفوف') }}</span><input v-model.number="gen.rows" type="number" min="1" max="26" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                        <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_per_row', 'رفوف في كل صف') }}</span><input v-model.number="gen.perRow" type="number" min="1" max="30" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    </template>
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_width', 'طول الرف (م)') }}</span><input v-model.number="gen.width" type="number" step="0.1" min="0.5" max="5" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_aisle', 'عرض الممر (م)') }}</span><input v-model.number="gen.aisle" type="number" step="0.1" min="0.8" max="5" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.levels', 'عدد الطبقات') }}</span><input v-model.number="gen.levels" type="number" min="1" max="10" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_letter', 'أول حرف') }}</span><input v-model="gen.startLetter" maxlength="1" dir="ltr" class="mt-1 w-full rounded-lg border-gray-300 uppercase dark:border-gray-600 dark:bg-gray-700" /></label>
                </div>
                <div v-if="gen.layout !== 'rows'" class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-gray-900/40">
                    <p class="text-gray-600 dark:text-gray-300">{{ t('library.map.gen_walls', 'الجدران التي عليها رفوف') }}</p>
                    <div class="mt-1.5 flex flex-wrap gap-3">
                        <label class="flex items-center gap-1.5"><input v-model="gen.walls.top" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.wall_top', 'الأعلى') }}</label>
                        <label class="flex items-center gap-1.5"><input v-model="gen.walls.bottom" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.wall_bottom', 'الأسفل') }}</label>
                        <label class="flex items-center gap-1.5"><input v-model="gen.walls.right" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.wall_right', 'اليمين') }}</label>
                        <label class="flex items-center gap-1.5"><input v-model="gen.walls.left" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.wall_left', 'اليسار') }}</label>
                    </div>
                    <label v-if="gen.layout === 'walls_rows'" class="mt-2 flex items-center gap-2"><input v-model="gen.groupCenter" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.gen_group_center', 'اجعل رفوف الوسط مجموعة تتحرك معاً') }}</label>
                </div>
                <div class="space-y-2 text-sm">
                    <label v-if="gen.layout === 'rows'" class="flex items-center gap-2"><input v-model="gen.double" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.gen_double', 'رفوف بوجهين (كتب من الجهتين)') }}</label>
                    <label class="flex items-center gap-2"><input v-model="gen.sectionsByRow" type="checkbox" class="rounded border-gray-300" :disabled="!sections.length" /> {{ t('library.map.gen_sections', 'خصص كل صف لقسم بالترتيب') }}</label>
                    <label class="flex items-center gap-2"><input v-model="gen.replace" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.gen_replace', 'استبدل الرفوف الحالية (المكاتب والجدران تبقى)') }}</label>
                </div>
                <div class="flex justify-end gap-2">
                    <button @click="gen.open = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('common.cancel', 'إلغاء') }}</button>
                    <button @click="generate" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm font-medium text-white">{{ t('library.map.gen_build', 'ابنِ الصفوف') }}</button>
                </div>
            </div>
        </Modal>
        <Modal :show="check.open" @close="check.open = false" max-width="lg">
            <div class="space-y-4 p-6">
                <div class="flex items-center gap-2">
                    <ClipboardDocumentCheckIcon class="h-6 w-6 text-[var(--accent-600)]" />
                    <h3 class="flex-1 text-lg font-semibold">{{ t('library.map.check', 'فحص الخريطة') }}</h3>
                    <button @click="check.open = false" class="rounded-full p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><XMarkIcon class="h-5 w-5" /></button>
                </div>
                <p v-if="check.running" class="flex items-center gap-2 text-sm text-gray-500"><ArrowPathRoundedSquareIcon class="h-4 w-4 animate-spin" /> {{ t('library.map.checking', 'يفحص الطرق إلى كل رف…') }}</p>
                <template v-else>
                    <div v-if="!check.issues.some((i) => i.level !== 'info')" class="flex items-center gap-2 rounded-xl bg-emerald-50 p-3 text-sm font-medium text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">
                        <CheckCircleIcon class="h-6 w-6 shrink-0" /> {{ t('library.map.check_ok', 'الخريطة سليمة: كل رف يوصله طريق من المدخل.') }}
                    </div>
                    <p v-else class="text-sm text-gray-600 dark:text-gray-300">{{ t('library.map.check_count', ':e مشكلة و:w تنبيه. اضغط على أي سطر لتذهب إليه.', { e: check.issues.filter((i) => i.level === 'error').length, w: check.issues.filter((i) => i.level === 'warn').length }) }}</p>
                    <ul v-if="check.issues.length" class="max-h-[50vh] space-y-1.5 overflow-y-auto">
                        <li v-for="(iss, n) in check.issues" :key="n">
                            <button @click="goToIssue(iss)" :disabled="!iss.key && iss.storey === null" class="flex w-full items-start gap-2 rounded-lg px-2.5 py-2 text-start text-sm hover:bg-gray-50 disabled:cursor-default dark:hover:bg-gray-700/50"
                                :class="iss.level === 'error' ? 'text-red-700 dark:text-red-300' : iss.level === 'warn' ? 'text-amber-700 dark:text-amber-300' : 'text-gray-600 dark:text-gray-300'">
                                <component :is="iss.level === 'info' ? InformationCircleIcon : ExclamationCircleIcon" class="mt-0.5 h-4 w-4 shrink-0" />
                                <span class="flex-1">{{ iss.text }}</span>
                            </button>
                        </li>
                    </ul>
                    <div v-if="check.unplaced" class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
                        <p class="font-medium" :class="check.unplaced.count ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300'">
                            {{ check.unplaced.count ? t('library.map.unplaced', ':n كتاب بلا مكان على الخريطة (حسب آخر حفظ).', { n: check.unplaced.count }) : t('library.map.unplaced_none', 'كل الكتب لها مكان على الخريطة.') }}
                        </p>
                        <p v-if="check.unplaced.count" class="mt-1 text-xs leading-5 text-gray-500">{{ t('library.map.unplaced_hint', 'اكتب رمز الرف في حقل «الرف» لنسخها، أو اختر لرف قسم هذه الكتب، أو وزّعها بقارئ RFID من «توزيع على الرفوف».') }}</p>
                        <p v-if="check.unplaced.books.length" class="mt-1 line-clamp-2 text-xs text-gray-500" dir="auto">{{ check.unplaced.books.map((b) => b.title).join(' · ') }}</p>
                    </div>
                    <p v-if="dirty" class="text-xs text-gray-500">{{ t('library.map.check_unsaved', 'الفحص يشمل تغييراتك غير المحفوظة، إلا عدد الكتب بلا مكان فهو حسب آخر حفظ.') }}</p>
                </template>
            </div>
        </Modal>
        <Modal :show="wiz.open" @close="wiz.open = false" max-width="lg">
            <div class="space-y-4 p-6">
                <div>
                    <h3 class="flex items-center gap-2 text-lg font-semibold"><BuildingOffice2Icon class="h-5 w-5 text-[var(--accent-600)]" /> {{ t('library.map.wizard', 'معالج البناء') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ t('library.map.wizard_text', 'اختر شكل المكتبة فيُبنى المخطط كاملاً، وبعدها تسحب وتعدّل أي قاعة أو باب.') }}</p>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button v-for="opt in [{ v: 'room', icon: HomeModernIcon, title: t('library.map.wiz_room', 'غرفة واحدة'), text: t('library.map.wiz_room_text', 'قاعة مطالعة واحدة') }, { v: 'building', icon: BuildingOffice2Icon, title: t('library.map.wiz_building', 'بناية'), text: t('library.map.wiz_building_text', 'قاعات على ممر، طابق أو أكثر') }]" :key="opt.v"
                        @click="wiz.type = opt.v" class="rounded-xl border-2 p-3 text-start" :class="wiz.type === opt.v ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-200 dark:border-gray-600'">
                        <component :is="opt.icon" class="h-6 w-6 text-[var(--accent-600)]" />
                        <p class="mt-1 font-semibold">{{ opt.title }}</p>
                        <p class="text-xs text-gray-500">{{ opt.text }}</p>
                    </button>
                </div>
                <div v-if="wiz.type === 'room'" class="grid grid-cols-2 gap-3 text-sm">
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.room_width', 'العرض (م)') }}</span><input v-model.number="wiz.roomWidth" type="number" min="3" max="150" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.room_depth', 'الطول (م)') }}</span><input v-model.number="wiz.roomDepth" type="number" min="3" max="150" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                </div>
                <template v-else>
                    <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                        <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.wiz_storeys', 'عدد الطوابق') }}</span><input v-model.number="wiz.storeys" type="number" min="1" max="20" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                        <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.wiz_per_side', 'قاعات بكل جهة') }}</span><input v-model.number="wiz.perSide" type="number" min="1" max="12" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                        <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.wiz_sides', 'جهات الممر') }}</span>
                            <select v-model.number="wiz.sides" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700"><option :value="2">{{ t('library.map.wiz_two_sides', 'الجهتين') }}</option><option :value="1">{{ t('library.map.wiz_one_side', 'جهة واحدة') }}</option></select>
                        </label>
                        <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.wiz_hall_w', 'عرض القاعة (م)') }}</span><input v-model.number="wiz.hallWidth" type="number" min="3" max="60" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                        <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.wiz_hall_d', 'عمق القاعة (م)') }}</span><input v-model.number="wiz.hallDepth" type="number" min="3" max="60" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                        <label><span class="text-gray-600 dark:text-gray-300">{{ t('library.map.wiz_corridor', 'عرض الممر (م)') }}</span><input v-model.number="wiz.corridor" type="number" step="0.5" min="1.5" max="10" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    </div>
                    <!-- A small sketch of what will be built. -->
                    <svg :viewBox="`-0.5 -0.5 ${wizSize.w + 1} ${wizSize.d + 1}`" class="mx-auto block h-28 w-full max-w-sm">
                        <rect v-if="wiz.sides === 2" v-for="j in wiz.perSide" :key="'t' + j" :x="(j - 1) * wiz.hallWidth" y="0" :width="wiz.hallWidth" :height="wiz.hallDepth" fill="#fbf8f2" stroke="#475569" stroke-width="0.25" />
                        <rect x="0" :y="wiz.sides === 2 ? wiz.hallDepth : 0" :width="wizSize.w" :height="wiz.corridor" fill="#e2e8f0" stroke="#475569" stroke-width="0.25" />
                        <rect v-for="j in wiz.perSide" :key="'b' + j" :x="(j - 1) * wiz.hallWidth" :y="(wiz.sides === 2 ? wiz.hallDepth : 0) + wiz.corridor" :width="wiz.hallWidth" :height="wiz.hallDepth" fill="#fbf8f2" stroke="#475569" stroke-width="0.25" />
                        <rect :x="wizSize.w - 2.5" :y="(wiz.sides === 2 ? wiz.hallDepth : 0) + 0.3" width="2" :height="wiz.corridor - 0.6" fill="#ede9fe" stroke="#7c3aed" stroke-width="0.2" />
                        <circle cx="0" :cy="(wiz.sides === 2 ? wiz.hallDepth : 0) + wiz.corridor / 2" r="0.8" fill="#10b981" />
                    </svg>
                    <p class="text-center text-xs text-gray-500">{{ t('library.map.wiz_summary', 'في كل طابق: :h قاعات وممر ودرج · المساحة :w × :d م', { h: wiz.perSide * wiz.sides, w: wizSize.w, d: wizSize.d }) }}</p>
                </template>
                <label class="flex items-center gap-2 text-sm"><input v-model="wiz.keepShelves" type="checkbox" class="rounded border-gray-300" /> {{ t('library.map.wiz_keep', 'أبقِ الرفوف الحالية (تُستبدل القاعات والممرات والأبواب فقط)') }}</label>
                <div class="flex justify-end gap-2">
                    <button @click="wiz.open = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('common.cancel', 'إلغاء') }}</button>
                    <button @click="runWizard" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm font-medium text-white">{{ t('library.map.wiz_build', 'ابنِ المخطط') }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
