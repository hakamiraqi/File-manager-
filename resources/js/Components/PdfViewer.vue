<script setup>
// The PDF reader (2026-11-12, by request: «all of WPS's reader features, free, easy and
// professional»). Used in the preview window and full screen (Pages/Reader/Show.vue).
//
//  - Views: continuous, one page at a time (swipe, like a book) and two pages side by side;
//    fit width / fit page, zoom, rotate, full screen.
//  - Reading colours: normal, night, eye comfort (green) and paper (sepia).
//  - Sidebar: page thumbnails, the book's contents (outline), bookmarks and «my notes».
//  - Notes: highlight / underline / strike selected text, pen, text, sticky notes and a
//    signature drawn once; undo/redo. They are the reader's own (the file never changes) and
//    are saved to their account, like the last page read (resume where you stopped).
//  - Auto-scroll, search the whole file (Arabic-aware), copy, print with the notes.
//
// Pages are drawn as they come into view and let go when far away (big books on phones), and
// every page has pdf.js's invisible text layer so text can be selected, copied and searched.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';
import { useTrans } from '@/Composables/useTrans';
import SignaturePad from '@/Components/Reader/SignaturePad.vue';
import {
    ArrowDownTrayIcon,
    ArrowPathIcon,
    ArrowsPointingInIcon,
    ArrowsPointingOutIcon,
    ArrowUturnLeftIcon,
    ArrowUturnRightIcon,
    BackspaceIcon,
    BookmarkIcon,
    BookOpenIcon,
    ChatBubbleLeftEllipsisIcon,
    ChevronDownIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    ChevronUpIcon,
    CursorArrowRaysIcon,
    DocumentIcon,
    EllipsisHorizontalIcon,
    ListBulletIcon,
    MagnifyingGlassIcon,
    MagnifyingGlassMinusIcon,
    MagnifyingGlassPlusIcon,
    PauseIcon,
    PencilIcon,
    PencilSquareIcon,
    PlayIcon,
    PrinterIcon,
    Squares2X2Icon,
    TrashIcon,
    ViewColumnsIcon,
    WrenchScrewdriverIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { BookmarkIcon as BookmarkSolidIcon } from '@heroicons/vue/24/solid';

const { t } = useTrans();
pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

const props = defineProps({
    src: { type: String, required: true },
    fileId: { type: Number, default: null },
    fileName: { type: String, default: '' },
    embedded: { type: Boolean, default: true },
    initialState: { type: Object, default: null },
    signature: { type: Array, default: null },
    canDownload: { type: Boolean, default: true },
    downloadUrl: { type: String, default: null },
    readerHref: { type: String, default: null },
});

const rtl = typeof document !== 'undefined' && document.documentElement.dir === 'rtl';

// ---------------------------------------------------------------- preferences (this browser)
const PREFS = 'fn-reader-prefs';
const saved = (() => {
    try {
        return JSON.parse(localStorage.getItem(PREFS) || '{}');
    } catch {
        return {};
    }
})();
const mode = ref(saved.mode || 'scroll'); // scroll | paged | spread
const fit = ref(saved.fit || 'width'); // width | page
const theme = ref(saved.theme || 'light'); // light | night | green | sepia
const penColor = ref(saved.penColor || '#e11d48');
const penSize = ref(saved.penSize || 4);
const autoSpeed = ref(saved.autoSpeed || 4);
watch([mode, fit, theme, penColor, penSize, autoSpeed], () => {
    try {
        localStorage.setItem(PREFS, JSON.stringify({ mode: mode.value, fit: fit.value, theme: theme.value, penColor: penColor.value, penSize: penSize.value, autoSpeed: autoSpeed.value }));
    } catch {
        /* private mode */
    }
});

const zoom = ref(1);
const rotation = ref(0);

// ---------------------------------------------------------------- document
const root = ref(null);
const scroller = ref(null);
const pageEls = ref([]);
const slotEls = ref([]);
const svgEls = ref([]);
const pages = ref(0);
const ratios = ref([]); // height / width of each page (as the file shows it, before our rotation)
const current = ref(1);
const loading = ref(true);
const progress = ref(0);
const failed = ref(false);
const box = reactive({ w: 0, h: 0 });

let doc = null;
let task = null;
let drawObserver = null;
let seenObserver = null;
let resizeObserver = null;
const drawn = new Map(); // page => render task
const texts = new Map(); // page => plain text
const seen = new Map(); // page => visible area

const ratioOf = (n) => {
    const r = ratios.value[n - 1] || ratios.value[0] || 1.414;
    return rotation.value % 180 ? 1 / r : r;
};
function pw(n) {
    const cw = Math.max(160, box.w - 16);
    const ch = Math.max(160, box.h - 16);
    const r = ratioOf(n);
    let w = mode.value === 'spread' ? (cw - 12) / 2 : cw;
    if (fit.value === 'page' || mode.value === 'paged') w = Math.min(w, ch / r);
    return Math.max(80, Math.floor(w * zoom.value));
}
const ph = (n) => Math.round(pw(n) * ratioOf(n));
// The annotation board is the page before our rotation: 1000 wide.
const boardH = (n) => Math.round(1000 * (ratios.value[n - 1] || ratios.value[0] || 1.414));
const unrotW = (n) => (rotation.value % 180 ? ph(n) : pw(n));
const unrotH = (n) => (rotation.value % 180 ? pw(n) : ph(n));
const unit = (n, px) => (px * 1000) / Math.max(1, unrotW(n)); // screen px => board units

async function draw(n) {
    if (!doc || drawn.has(n)) return;
    const slot = slotEls.value[n - 1];
    if (!slot) return;
    drawn.set(n, null);
    try {
        const page = await doc.getPage(n);
        const base = page.getViewport({ scale: 1 });
        if (!ratios.value[n - 1] || Math.abs(ratios.value[n - 1] - base.height / base.width) > 0.01) {
            ratios.value[n - 1] = base.height / base.width;
        }
        const turn = (page.rotate + rotation.value) % 360;
        const turned = page.getViewport({ scale: 1, rotation: turn });
        const width = pw(n);
        const scale = width / turned.width;
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const viewport = page.getViewport({ scale: scale * dpr, rotation: turn });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        canvas.className = 'fn-canvas';
        const render = page.render({ canvasContext: canvas.getContext('2d'), viewport });
        if (!drawn.has(n)) {
            render.cancel();
            return;
        }
        drawn.set(n, render);
        await render.promise;
        if (drawn.get(n) !== render) return;
        slot.style.setProperty('--scale-factor', String(scale));
        slot.style.setProperty('--total-scale-factor', String(scale));
        const layer = document.createElement('div');
        layer.className = 'textLayer';
        slot.replaceChildren(canvas, layer);
        try {
            const content = await page.getTextContent();
            if (content.items.some((i) => i.str.trim())) hasText.value = true;
            else if (hasText.value === null) hasText.value = false;
            texts.set(n, content.items.map((i) => i.str).join(' '));
            if (drawn.get(n) !== render) return;
            await new pdfjs.TextLayer({ textContentSource: content, container: layer, viewport: page.getViewport({ scale, rotation: turn }) }).render();
            markHits(n);
        } catch {
            // The picture stays; only selection is missing on this page.
        }
    } catch {
        drawn.delete(n);
    }
}

function forget(n) {
    const render = drawn.get(n);
    if (render) render.cancel();
    drawn.delete(n);
    slotEls.value[n - 1]?.replaceChildren();
}

function observeAll() {
    drawObserver?.disconnect();
    seenObserver?.disconnect();
    seen.clear();
    drawObserver = new IntersectionObserver(
        (entries) => {
            for (const e of entries) {
                const n = Number(e.target.dataset.page);
                if (e.isIntersecting) draw(n);
                else forget(n);
            }
        },
        { root: scroller.value, rootMargin: '1200px 1200px' },
    );
    seenObserver = new IntersectionObserver(
        (entries) => {
            for (const e of entries) seen.set(Number(e.target.dataset.page), e.isIntersecting ? e.intersectionRect.width * e.intersectionRect.height : 0);
            let best = current.value;
            let area = -1;
            for (const [n, a] of seen) {
                if (a > area || (a === area && n < best)) {
                    best = n;
                    area = a;
                }
            }
            if (area > 0 && keepPage === null) current.value = best;
        },
        { root: scroller.value, threshold: [0, 0.1, 0.25, 0.5, 0.75, 1] },
    );
    pageEls.value.slice(0, pages.value).forEach((el) => {
        if (!el) return;
        drawObserver.observe(el);
        seenObserver.observe(el);
    });
}

// Anything that changes the pages' size redraws them, keeping the page being read in place.
let relayoutTimer = null;
let keepPage = null; // the page being read when the layout started changing
const layoutSig = computed(() => [Math.round(box.w), Math.round(box.h), zoom.value, fit.value, mode.value, rotation.value].join('|'));
watch(layoutSig, () => {
    if (!pages.value) return;
    // Taken before the pages change size: the scroll position is meaningless after that.
    if (keepPage === null) keepPage = current.value;
    clearTimeout(relayoutTimer);
    relayoutTimer = setTimeout(async () => {
        const keep = keepPage;
        for (const n of [...drawn.keys()]) forget(n);
        await nextTick();
        observeAll();
        goTo(keep, false);
        keepPage = null;
        thumbsRefresh();
    }, 120);
});

function goTo(n, smooth = true) {
    n = Math.min(Math.max(1, Math.round(n) || 1), pages.value || 1);
    const el = pageEls.value[n - 1];
    if (!el || !scroller.value) return;
    if (mode.value === 'paged') {
        el.parentElement.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', inline: 'start', block: 'nearest' });
    } else {
        scroller.value.scrollTo({ top: el.offsetTop - 8, behavior: smooth ? 'smooth' : 'auto' });
    }
    current.value = n;
}
const stepPage = (d) => goTo(current.value + d * (mode.value === 'spread' ? 2 : 1));

const pageInput = ref('');
watch(current, (n) => (pageInput.value = String(n)), { immediate: true });

async function open() {
    loading.value = true;
    failed.value = false;
    progress.value = 0;
    try {
        task = pdfjs.getDocument({ url: props.src, withCredentials: true });
        task.onProgress = ({ loaded, total }) => {
            if (total) progress.value = Math.min(100, Math.round((loaded / total) * 100));
        };
        doc = await task.promise;
        const first = await doc.getPage(1);
        const vp = first.getViewport({ scale: 1 });
        ratios.value = Array.from({ length: doc.numPages }, (_, i) => (i === 0 ? vp.height / vp.width : 0));
        pages.value = doc.numPages;
        loading.value = false;
        await nextTick();
        measure();
        await nextTick();
        observeAll();
        loadOutline();
        await stateReady;
        const last = state.last_page || 1;
        if (last > 1 && last <= pages.value) {
            goTo(last, false);
            resumed.value = last;
            setTimeout(() => (resumed.value = 0), 7000);
        }
    } catch {
        loading.value = false;
        failed.value = true;
    }
}

function measure() {
    if (!scroller.value) return;
    box.w = scroller.value.clientWidth;
    box.h = scroller.value.clientHeight;
}

function close() {
    drawObserver?.disconnect();
    seenObserver?.disconnect();
    thumbObserver?.disconnect();
    for (const n of [...drawn.keys()]) forget(n);
    for (const n of [...thumbsDrawn.keys()]) thumbForget(n);
    task?.destroy();
    task = null;
    doc = null;
    pages.value = 0;
    texts.clear();
    hits.value = [];
    searched.value = false;
    hasText.value = null;
    outline.value = [];
    stopAuto();
}

// ---------------------------------------------------------------- search
const showSearch = ref(false);
const searchInput = ref(null);
const query = ref('');
const hits = ref([]);
const hitIndex = ref(0);
const searching = ref(false);
const searched = ref(false);
const hasText = ref(null);

const normalize = (v) =>
    (v || '')
        .toLowerCase()
        .replace(/[ً-ْـ]/g, '')
        .replace(/[أإآ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/\s+/g, ' ');

async function pageText(n) {
    if (texts.has(n)) return texts.get(n);
    const page = await doc.getPage(n);
    const content = await page.getTextContent();
    const text = content.items.map((i) => i.str).join(' ');
    texts.set(n, text);
    return text;
}
async function search() {
    const q = normalize(query.value.trim());
    hits.value = [];
    hitIndex.value = 0;
    searched.value = false;
    if (!q || !doc) return;
    searching.value = true;
    try {
        for (let n = 1; n <= pages.value; n++) if (normalize(await pageText(n)).includes(q)) hits.value.push(n);
    } finally {
        searching.value = false;
        searched.value = true;
    }
    for (const n of drawn.keys()) markHits(n);
    if (hits.value.length) goTo(hits.value[0]);
}
function stepHit(d) {
    if (!hits.value.length) return;
    hitIndex.value = (hitIndex.value + d + hits.value.length) % hits.value.length;
    goTo(hits.value[hitIndex.value]);
}
function clearSearch() {
    query.value = '';
    hits.value = [];
    searched.value = false;
    for (const n of drawn.keys()) markHits(n);
}
function markHits(n) {
    const layer = slotEls.value[n - 1]?.querySelector('.textLayer');
    if (!layer) return;
    const q = normalize(query.value.trim());
    for (const span of layer.querySelectorAll('span')) span.classList.toggle('fn-hit', !!q && hits.value.includes(n) && normalize(span.textContent).includes(q));
}
async function toggleSearch() {
    showSearch.value = !showSearch.value;
    if (showSearch.value) {
        await nextTick();
        searchInput.value?.focus();
    } else clearSearch();
}

// ---------------------------------------------------------------- sidebar
const sidebar = ref(false);
const tab = ref('thumbs'); // thumbs | outline | bookmarks | notes
const thumbList = ref(null);
const thumbEls = ref([]);
const thumbsDrawn = new Map();
let thumbObserver = null;

function openSidebar(which) {
    if (sidebar.value && tab.value === which) {
        sidebar.value = false;
        return;
    }
    tab.value = which;
    sidebar.value = true;
}
watch([sidebar, tab], async () => {
    thumbObserver?.disconnect();
    if (!sidebar.value || tab.value !== 'thumbs') return;
    await nextTick();
    thumbObserver = new IntersectionObserver(
        (entries) => {
            for (const e of entries) {
                const n = Number(e.target.dataset.page);
                if (e.isIntersecting) thumbDraw(n);
                else thumbForget(n);
            }
        },
        { root: thumbList.value, rootMargin: '600px 0px' },
    );
    thumbEls.value.slice(0, pages.value).forEach((el) => el && thumbObserver.observe(el));
    thumbEls.value[current.value - 1]?.scrollIntoView({ block: 'center' });
});
async function thumbDraw(n) {
    if (!doc || thumbsDrawn.has(n)) return;
    const holder = thumbEls.value[n - 1];
    if (!holder) return;
    thumbsDrawn.set(n, null);
    try {
        const page = await doc.getPage(n);
        const turn = (page.rotate + rotation.value) % 360;
        const vp1 = page.getViewport({ scale: 1, rotation: turn });
        const viewport = page.getViewport({ scale: (104 * Math.min(window.devicePixelRatio || 1, 2)) / vp1.width, rotation: turn });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        canvas.className = 'fn-canvas block w-full';
        const render = page.render({ canvasContext: canvas.getContext('2d'), viewport });
        thumbsDrawn.set(n, render);
        await render.promise;
        if (thumbsDrawn.get(n) === render) holder.replaceChildren(canvas);
    } catch {
        thumbsDrawn.delete(n);
    }
}
function thumbForget(n) {
    thumbsDrawn.get(n)?.cancel();
    thumbsDrawn.delete(n);
    thumbEls.value[n - 1]?.replaceChildren();
}
function thumbsRefresh() {
    for (const n of [...thumbsDrawn.keys()]) thumbForget(n);
    if (!thumbObserver) return;
    // Observing again reports what is in view, so those thumbnails are drawn afresh.
    thumbEls.value.slice(0, pages.value).forEach((el) => {
        if (!el) return;
        thumbObserver.unobserve(el);
        thumbObserver.observe(el);
    });
}

// The book's contents (PDF outline), with each entry's page.
const outline = ref([]);
async function loadOutline() {
    try {
        const items = (await doc.getOutline()) || [];
        const flat = [];
        const walk = async (list, depth) => {
            for (const it of list) {
                if (flat.length >= 3000) return;
                let page = null;
                try {
                    let dest = it.dest;
                    if (typeof dest === 'string') dest = await doc.getDestination(dest);
                    if (Array.isArray(dest)) page = typeof dest[0] === 'number' ? dest[0] + 1 : (await doc.getPageIndex(dest[0])) + 1;
                } catch {
                    page = null;
                }
                flat.push({ title: it.title, page, depth });
                if (it.items?.length) await walk(it.items, depth + 1);
            }
        };
        await walk(items, 0);
        outline.value = flat;
    } catch {
        outline.value = [];
    }
}
const chapterOf = (n) => {
    let title = '';
    for (const o of outline.value) if (o.page && o.page <= n) title = o.title;
    return title;
};

// ---------------------------------------------------------------- saved state (bookmarks, notes, place)
const state = reactive({ last_page: 1, bookmarks: [], annotations: [] });
const LOCAL = 'fn-reader:' + props.src;
let stateLoaded;
const stateReady = new Promise((r) => (stateLoaded = r));
const signatureStrokes = ref(props.signature);

async function loadState() {
    try {
        let s = props.initialState;
        if (!s && props.fileId) {
            const { data } = await window.axios.get(route('reader.state', props.fileId));
            s = data.state;
            if (!signatureStrokes.value) signatureStrokes.value = data.signature;
        }
        if (!s && !props.fileId) s = JSON.parse(localStorage.getItem(LOCAL) || 'null');
        if (s) {
            state.last_page = s.last_page || 1;
            state.bookmarks = s.bookmarks || [];
            state.annotations = s.annotations || [];
        }
    } catch {
        /* reading still works; saving will retry */
    } finally {
        stateLoaded();
    }
}

const dirty = new Set();
const saving = ref(false);
const saveFailed = ref(false);
let saveTimer = null;
function markDirty(field, delay = 1200) {
    dirty.add(field);
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flush, delay);
}
async function flush() {
    if (!dirty.size) return;
    const fields = [...dirty];
    dirty.clear();
    const body = {};
    for (const f of fields) body[f] = state[f];
    if (fields.includes('last_page')) body.pages = pages.value || null;
    if (!props.fileId) {
        try {
            localStorage.setItem(LOCAL, JSON.stringify({ last_page: state.last_page, bookmarks: state.bookmarks, annotations: state.annotations }));
        } catch {
            /* full */
        }
        return;
    }
    saving.value = true;
    try {
        await window.axios.put(route('reader.state.save', props.fileId), body);
        saveFailed.value = false;
    } catch {
        fields.forEach((f) => dirty.add(f));
        saveFailed.value = true;
        saveTimer = setTimeout(flush, 8000);
    } finally {
        saving.value = false;
    }
}
watch(current, (n) => {
    if (loading.value || n === state.last_page) return;
    state.last_page = n;
    markDirty('last_page', 2500);
});
const resumed = ref(0);

// Bookmarks
const isBookmarked = (n) => state.bookmarks.some((b) => b.page === n);
function toggleBookmark(n = current.value) {
    if (isBookmarked(n)) state.bookmarks = state.bookmarks.filter((b) => b.page !== n);
    else state.bookmarks = [...state.bookmarks, { page: n, title: chapterOf(n) }].sort((a, b) => a.page - b.page);
    markDirty('bookmarks', 400);
}

// ---------------------------------------------------------------- annotations
const annotating = ref(false);
const tool = ref('select'); // select | pen | text | note | sign | eraser
const COLORS = ['#facc15', '#4ade80', '#60a5fa', '#f472b6', '#fb923c'];
const PEN_COLORS = ['#e11d48', '#2563eb', '#16a34a', '#111827', '#f59e0b'];
const undoStack = ref([]);
const redoStack = ref([]);
const byPage = computed(() => {
    const m = {};
    for (const a of state.annotations) (m[a.page] ||= []).push(a);
    return m;
});
const uid = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 7);

function addAnn(a, record = true) {
    state.annotations = [...state.annotations, a];
    if (record) {
        undoStack.value.push({ op: 'add', a });
        redoStack.value = [];
    }
    markDirty('annotations');
}
function removeAnn(id, record = true) {
    const a = state.annotations.find((x) => x.id === id);
    if (!a) return;
    state.annotations = state.annotations.filter((x) => x.id !== id);
    if (record) {
        undoStack.value.push({ op: 'remove', a });
        redoStack.value = [];
    }
    markDirty('annotations');
}
function updateAnn(id, patch) {
    state.annotations = state.annotations.map((x) => (x.id === id ? { ...x, ...patch } : x));
    markDirty('annotations');
}
function undo() {
    const h = undoStack.value.pop();
    if (!h) return;
    if (h.op === 'add') removeAnn(h.a.id, false);
    else addAnn(h.a, false);
    redoStack.value.push(h);
}
function redo() {
    const h = redoStack.value.pop();
    if (!h) return;
    if (h.op === 'add') addAnn(h.a, false);
    else removeAnn(h.a.id, false);
    undoStack.value.push(h);
}

// A screen point => board units on page n.
function boardPoint(n, clientX, clientY) {
    const svg = svgEls.value[n - 1];
    const m = svg?.getScreenCTM();
    if (!m) return null;
    const p = new DOMPoint(clientX, clientY).matrixTransform(m.inverse());
    return [Math.round(p.x * 10) / 10, Math.round(p.y * 10) / 10];
}

// Pen
const liveInk = ref(null); // { page, points }
function svgDown(n, e) {
    if (!annotating.value) return;
    const p = boardPoint(n, e.clientX, e.clientY);
    if (!p) return;
    if (tool.value === 'pen') {
        e.currentTarget.setPointerCapture(e.pointerId);
        liveInk.value = { page: n, points: [p] };
    } else if (tool.value === 'text' || tool.value === 'note') {
        editor.value = { mode: tool.value, page: n, x: p[0], y: p[1], text: '', id: null, ...popAt(e.clientX, e.clientY) };
    } else if (tool.value === 'sign') {
        if (!signatureStrokes.value) {
            signing.value = { page: n, x: p[0], y: p[1] };
            return;
        }
        placeSignature(n, p[0], p[1]);
    } else if (tool.value === 'eraser') {
        const id = e.target.closest?.('[data-ann]')?.dataset.ann;
        if (id) removeAnn(id);
    }
}
function svgMove(n, e) {
    if (!liveInk.value || liveInk.value.page !== n) return;
    const p = boardPoint(n, e.clientX, e.clientY);
    const last = liveInk.value.points[liveInk.value.points.length - 1];
    if (p && Math.hypot(p[0] - last[0], p[1] - last[1]) >= unit(n, 1.5)) liveInk.value.points.push(p);
}
function svgUp() {
    const ink = liveInk.value;
    liveInk.value = null;
    if (!ink || ink.points.length < 2) return;
    addAnn({ id: uid(), type: 'ink', page: ink.page, color: penColor.value, size: penSize.value, points: ink.points });
}
const inkPath = (pts) => 'M' + pts.map((p) => p.join(' ')).join(' L');

// Signature
const signing = ref(null); // the pad is open; holds where to place it afterwards
function placeSignature(n, x, y) {
    const w = 260;
    addAnn({ id: uid(), type: 'sign', page: n, x: Math.max(0, x - w / 2), y: Math.max(0, y - w * 0.2), w, h: w * 0.4, strokes: signatureStrokes.value });
    tool.value = 'select';
}
async function saveSignature(strokes) {
    signatureStrokes.value = strokes;
    const at = signing.value;
    signing.value = null;
    if (props.fileId) {
        try {
            await window.axios.put(route('reader.signature'), { strokes });
        } catch {
            /* still placed on this page */
        }
    }
    if (at && at.page) placeSignature(at.page, at.x, at.y);
}
async function forgetSignature() {
    signatureStrokes.value = null;
    signing.value = null;
    if (props.fileId) await window.axios.delete(route('reader.signature')).catch(() => {});
}

// Text and sticky notes: a small editor next to where it was tapped.
const editor = ref(null); // { mode, page, x, y, text, id, left, top }
function popAt(clientX, clientY) {
    const r = root.value.getBoundingClientRect();
    return { left: Math.min(Math.max(8, clientX - r.left - 140), r.width - 296), top: Math.min(Math.max(8, clientY - r.top + 12), r.height - 190) };
}
function saveEditor() {
    const e = editor.value;
    editor.value = null;
    if (!e) return;
    const text = e.text.trim();
    if (e.id) {
        if (!text) removeAnn(e.id);
        else updateAnn(e.id, { text });
        return;
    }
    if (!text) return;
    if (e.mode === 'text') addAnn({ id: uid(), type: 'text', page: e.page, x: e.x, y: e.y, text, color: penColor.value, size: 22 });
    else addAnn({ id: uid(), type: 'note', page: e.page, x: e.x, y: e.y, text, color: '#f59e0b' });
}
function openAnn(a, ev) {
    if (tool.value === 'eraser') return removeAnn(a.id);
    editor.value = { mode: a.type, page: a.page, x: a.x, y: a.y, text: a.text, id: a.id, ...popAt(ev.clientX, ev.clientY) };
}

// Highlight / underline / strike of selected text
const selPop = ref(null); // { left, top, text }
let selRange = null;
let selTimer = null;
function onSelection() {
    clearTimeout(selTimer);
    selTimer = setTimeout(() => {
        const sel = window.getSelection();
        if (!sel || sel.isCollapsed || !sel.rangeCount || !scroller.value?.contains(sel.anchorNode)) {
            if (!selBusy) selPop.value = null;
            return;
        }
        const range = sel.getRangeAt(0);
        const text = sel.toString().trim();
        if (!text) return;
        selRange = range.cloneRange();
        const rr = range.getBoundingClientRect();
        const r = root.value.getBoundingClientRect();
        selPop.value = {
            text,
            left: Math.min(Math.max(8, rr.left + rr.width / 2 - r.left - 150), r.width - 308),
            top: rr.top - r.top > 70 ? rr.top - r.top - 52 : rr.bottom - r.top + 10,
        };
    }, 250);
}
let selBusy = false;
function markSelection(type, color) {
    if (!selRange) return;
    const rects = Array.from(selRange.getClientRects()).filter((q) => q.width > 1 && q.height > 1);
    const text = selPop.value?.text || '';
    for (const n of drawn.keys()) {
        const holder = pageEls.value[n - 1];
        if (!holder) continue;
        const hr = holder.getBoundingClientRect();
        const mine = [];
        for (const q of rects) {
            const cx = q.left + q.width / 2;
            const cy = q.top + q.height / 2;
            if (cx < hr.left || cx > hr.right || cy < hr.top || cy > hr.bottom) continue;
            const a = boardPoint(n, q.left, q.top);
            const b = boardPoint(n, q.right, q.bottom);
            if (!a || !b) continue;
            mine.push([Math.min(a[0], b[0]), Math.min(a[1], b[1]), Math.abs(b[0] - a[0]), Math.abs(b[1] - a[1])]);
        }
        if (!mine.length) continue;
        addAnn({ id: uid(), type, page: n, color, rects: mergeRects(mine), text: text.slice(0, 500) });
    }
    window.getSelection()?.removeAllRanges();
    selPop.value = null;
    selRange = null;
}
// Words of one line become one band.
function mergeRects(list) {
    list.sort((p, q) => p[1] - q[1] || p[0] - q[0]);
    const out = [];
    for (const r of list) {
        const last = out[out.length - 1];
        if (last && Math.abs(last[1] - r[1]) < Math.min(last[3], r[3]) * 0.5 && r[0] <= last[0] + last[2] + 8 && r[0] + r[2] >= last[0] - 8) {
            const x1 = Math.min(last[0], r[0]);
            const y1 = Math.min(last[1], r[1]);
            const x2 = Math.max(last[0] + last[2], r[0] + r[2]);
            const y2 = Math.max(last[1] + last[3], r[1] + r[3]);
            out[out.length - 1] = [x1, y1, x2 - x1, y2 - y1];
        } else out.push([...r]);
    }
    return out.map((r) => r.map((v) => Math.round(v * 10) / 10));
}
async function copySelection() {
    const text = selPop.value?.text;
    selPop.value = null;
    if (!text) return;
    try {
        await navigator.clipboard.writeText(text);
        flash(t('reader.copied', 'نُسخ النص'));
    } catch {
        document.execCommand('copy');
    }
}
function noteOnSelection() {
    const p = selPop.value;
    if (!p || !selRange) return;
    const rr = selRange.getBoundingClientRect();
    const n = [...drawn.keys()].find((k) => {
        const hr = pageEls.value[k - 1]?.getBoundingClientRect();
        return hr && rr.top >= hr.top - 2 && rr.top <= hr.bottom;
    });
    if (!n) return;
    markSelection('highlight', '#fde68a');
    const at = boardPoint(n, rr.right, rr.top);
    editor.value = { mode: 'note', page: n, x: at[0], y: at[1], text: '', id: null, ...popAt(rr.right, rr.bottom) };
}

const notesList = computed(() =>
    [...state.annotations].sort((a, b) => a.page - b.page || (a.rects?.[0]?.[1] ?? a.y ?? 0) - (b.rects?.[0]?.[1] ?? b.y ?? 0)),
);
const annLabel = (a) =>
    ({
        highlight: t('reader.highlight', 'تظليل'),
        underline: t('reader.underline', 'تسطير'),
        strike: t('reader.strike', 'شطب'),
        ink: t('reader.pen', 'قلم'),
        text: t('reader.text', 'نص'),
        note: t('reader.note', 'ملاحظة'),
        sign: t('reader.signature', 'توقيع'),
    })[a.type];
function exportNotes() {
    const lines = [props.fileName || 'PDF', ''];
    for (const a of notesList.value) {
        if (!a.text) continue;
        lines.push(`${t('reader.page', 'صفحة')} ${a.page} · ${annLabel(a)}: ${a.text}`);
    }
    const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/plain;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = (props.fileName || 'notes').replace(/\.[^.]+$/, '') + ' - ' + t('reader.my_notes', 'ملاحظاتي') + '.txt';
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
}

watch(annotating, (on) => {
    if (!on) {
        tool.value = 'select';
        editor.value = null;
    }
});

// ---------------------------------------------------------------- auto-scroll
const autoOn = ref(false);
let raf = null;
let pageTimer = null;
let lastT = 0;
function startAuto() {
    autoOn.value = true;
    if (mode.value === 'paged') {
        pageTimer = setInterval(() => (current.value >= pages.value ? stopAuto() : stepPage(1)), (12 - autoSpeed.value) * 2500);
        return;
    }
    lastT = performance.now();
    const tick = (now) => {
        if (!autoOn.value || !scroller.value) return;
        const dt = Math.min(100, now - lastT);
        lastT = now;
        scroller.value.scrollTop += (autoSpeed.value * 18 * dt) / 1000;
        if (scroller.value.scrollTop + scroller.value.clientHeight >= scroller.value.scrollHeight - 2) return stopAuto();
        raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
}
function stopAuto() {
    autoOn.value = false;
    cancelAnimationFrame(raf);
    clearInterval(pageTimer);
}
watch([autoSpeed, mode], () => {
    if (autoOn.value) {
        stopAuto();
        startAuto();
    }
});

// ---------------------------------------------------------------- view menu, zoom, full screen
const menu = ref(false);
const zoomLabel = computed(() => Math.round(zoom.value * 100) + '%');
const setZoom = (z) => (zoom.value = Math.min(4, Math.max(0.5, Math.round(z * 100) / 100)));
const rotate = () => (rotation.value = (rotation.value + 90) % 360);
const full = ref(false);
function toggleFull() {
    if (document.fullscreenElement) document.exitFullscreen?.();
    else root.value?.requestFullscreen?.().catch(() => {});
}
const canFull = typeof document !== 'undefined' && !!document.fullscreenEnabled;
const onFullChange = () => (full.value = document.fullscreenElement === root.value);

// ---------------------------------------------------------------- printing (with the notes)
const printing = ref(null); // { from, to } dialog; busy: { done, total }
const printBusy = ref(null);
function openPrint() {
    menu.value = false;
    printing.value = { which: 'all', from: 1, to: pages.value };
}
async function doPrint() {
    const p = printing.value;
    printing.value = null;
    let from = 1;
    let to = pages.value;
    if (p.which === 'current') from = to = current.value;
    if (p.which === 'range') {
        from = Math.max(1, Math.min(p.from, p.to));
        to = Math.min(pages.value, Math.max(p.from, p.to));
    }
    printBusy.value = { done: 0, total: to - from + 1 };
    const parts = [];
    try {
        for (let n = from; n <= to; n++) {
            const page = await doc.getPage(n);
            const vp1 = page.getViewport({ scale: 1 });
            const scale = Math.min(2.2, 1400 / vp1.width);
            const viewport = page.getViewport({ scale });
            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
            const img = canvas.toDataURL('image/jpeg', 0.88);
            canvas.width = canvas.height = 0;
            let svg = '';
            if (byPage.value[n]?.length && svgEls.value[n - 1]) {
                const clone = svgEls.value[n - 1].cloneNode(true);
                clone.removeAttribute('style');
                clone.removeAttribute('class');
                clone.setAttribute('preserveAspectRatio', 'none');
                svg = clone.outerHTML;
            }
            parts.push(`<div class="p" style="aspect-ratio:${vp1.width}/${vp1.height}"><img src="${img}">${svg}</div>`);
            printBusy.value = { done: n - from + 1, total: to - from + 1 };
        }
        const frame = document.createElement('iframe');
        frame.style.cssText = 'position:fixed;width:0;height:0;border:0;opacity:0';
        document.body.appendChild(frame);
        const d = frame.contentDocument;
        d.open();
        d.write(
            `<!doctype html><html><head><title>${(props.fileName || '').replace(/</g, '')}</title><style>@page{margin:0}html,body{margin:0}.p{position:relative;width:100%;break-after:page;overflow:hidden}.p:last-child{break-after:auto}.p img{display:block;width:100%;height:100%}.p svg{position:absolute;inset:0;width:100%;height:100%}.fn-note-text{display:none}</style></head><body>${parts.join('')}</body></html>`,
        );
        d.close();
        setTimeout(() => {
            frame.contentWindow.focus();
            frame.contentWindow.print();
            setTimeout(() => frame.remove(), 60000);
        }, 400);
    } finally {
        printBusy.value = null;
    }
}

// ---------------------------------------------------------------- small helpers
const flashText = ref('');
let flashTimer = null;
function flash(text) {
    flashText.value = text;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => (flashText.value = ''), 2200);
}

function onKey(e) {
    if (e.target.closest?.('input, textarea, select, [contenteditable]')) return;
    const mod = e.ctrlKey || e.metaKey;
    if (mod && e.key.toLowerCase() === 'f') {
        e.preventDefault();
        if (!showSearch.value) toggleSearch();
        else searchInput.value?.focus();
    } else if (mod && e.key.toLowerCase() === 'z') {
        e.preventDefault();
        e.shiftKey ? redo() : undo();
    } else if (mod && e.key.toLowerCase() === 'y') {
        e.preventDefault();
        redo();
    } else if (mod && e.key.toLowerCase() === 'p') {
        if (props.canDownload) {
            e.preventDefault();
            openPrint();
        }
    } else if (e.key === '+' || e.key === '=') setZoom(zoom.value + 0.1);
    else if (e.key === '-') setZoom(zoom.value - 0.1);
    else if (e.key === 'Escape') {
        editor.value = null;
        selPop.value = null;
        menu.value = false;
        tool.value = 'select';
    } else if (mode.value !== 'scroll' && ['PageDown', 'ArrowDown', ' '].includes(e.key)) {
        e.preventDefault();
        stepPage(1);
    } else if (mode.value !== 'scroll' && ['PageUp', 'ArrowUp'].includes(e.key)) {
        e.preventDefault();
        stepPage(-1);
    } else if (mode.value !== 'scroll' && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
        e.preventDefault();
        stepPage((e.key === 'ArrowLeft') === rtl ? 1 : -1);
    } else if (e.key === 'Home') goTo(1);
    else if (e.key === 'End') goTo(pages.value);
}

function userScrolled() {
    if (autoOn.value && mode.value !== 'paged') stopAuto();
}

const themeOptions = [
    { id: 'light', label: () => t('reader.theme_light', 'عادي'), swatch: '#ffffff' },
    { id: 'sepia', label: () => t('reader.theme_sepia', 'ورقي'), swatch: '#f4e7c5' },
    { id: 'green', label: () => t('reader.theme_green', 'راحة العين'), swatch: '#cfe8cf' },
    { id: 'night', label: () => t('reader.theme_night', 'ليلي'), swatch: '#1f2937' },
];
const modeOptions = [
    { id: 'scroll', label: () => t('reader.mode_scroll', 'تمرير متصل'), icon: DocumentIcon },
    { id: 'paged', label: () => t('reader.mode_paged', 'صفحة صفحة'), icon: BookOpenIcon },
    { id: 'spread', label: () => t('reader.mode_spread', 'صفحتان'), icon: ViewColumnsIcon },
];
const tools = [
    { id: 'select', icon: CursorArrowRaysIcon, label: () => t('reader.tool_select', 'تحديد وتظليل') },
    { id: 'pen', icon: PencilIcon, label: () => t('reader.pen', 'قلم') },
    { id: 'text', icon: null, label: () => t('reader.text', 'نص') },
    { id: 'note', icon: ChatBubbleLeftEllipsisIcon, label: () => t('reader.note', 'ملاحظة') },
    { id: 'sign', icon: PencilSquareIcon, label: () => t('reader.signature', 'توقيع') },
    { id: 'eraser', icon: BackspaceIcon, label: () => t('reader.eraser', 'ممحاة') },
];
const toolHint = computed(
    () =>
        ({
            select: t('reader.hint_select', 'حدّد نصاً لتظليله أو تسطيره أو نسخه'),
            pen: t('reader.hint_pen', 'ارسم على الصفحة بإصبعك أو بالقلم'),
            text: t('reader.hint_text', 'المس مكاناً في الصفحة لكتابة نص'),
            note: t('reader.hint_note', 'المس مكاناً لإضافة ملاحظة لاصقة'),
            sign: signatureStrokes.value ? t('reader.hint_sign', 'المس مكان التوقيع في الصفحة') : t('reader.hint_sign_new', 'المس مكان التوقيع، ثم ارسم توقيعك'),
            eraser: t('reader.hint_eraser', 'المس أي تظليل أو رسم أو ملاحظة لحذفه'),
        })[tool.value],
);

const pageWrapClass = computed(() =>
    mode.value === 'paged'
        ? 'flex h-full shrink-0 snap-center snap-always items-center justify-center overflow-auto p-2'
        : mode.value === 'spread'
          ? 'flex justify-center odd:justify-end even:justify-start'
          : 'mb-3 flex justify-center',
);
const containerClass = computed(() =>
    mode.value === 'paged' ? 'flex h-full' : mode.value === 'spread' ? 'grid min-w-min grid-cols-2 gap-3 px-2 py-2' : 'min-w-min px-2 py-2',
);

onMounted(() => {
    if (!props.embedded) root.value?.focus();
    loadState();
    open();
    resizeObserver = new ResizeObserver(() => measure());
    if (scroller.value) resizeObserver.observe(scroller.value);
    document.addEventListener('selectionchange', onSelection);
    document.addEventListener('fullscreenchange', onFullChange);
    window.addEventListener('beforeunload', flush);
});
onBeforeUnmount(() => {
    flush();
    close();
    resizeObserver?.disconnect();
    document.removeEventListener('selectionchange', onSelection);
    document.removeEventListener('fullscreenchange', onFullChange);
    window.removeEventListener('beforeunload', flush);
});
watch(
    () => props.src,
    () => {
        close();
        open();
    },
);
</script>

<template>
    <div
        ref="root"
        tabindex="-1"
        :class="[
            embedded && !full ? 'h-[75vh] rounded-xl' : 'h-full',
            'fn-reader relative flex w-full flex-col overflow-hidden outline-none',
            'fn-theme-' + theme,
        ]"
        @keydown="onKey"
    >
        <!-- Top bar -->
        <div class="fn-bar flex shrink-0 items-center gap-0.5 border-b px-1.5 py-1 sm:gap-1">
            <button type="button" class="fn-btn" :class="{ 'fn-on': sidebar }" :title="t('reader.pages', 'الصفحات والفهرس')" @click="openSidebar(tab)">
                <Squares2X2Icon class="h-5 w-5" />
            </button>
            <div v-if="pages" class="flex items-center gap-0.5">
                <button type="button" class="fn-btn hidden sm:inline-flex" :title="t('pdf.prev', 'السابق')" :disabled="current <= 1" @click="stepPage(-1)">
                    <ChevronUpIcon class="h-4 w-4" />
                </button>
                <form class="flex items-center gap-1 text-xs tabular-nums" dir="ltr" @submit.prevent="goTo(Number(pageInput))">
                    <input
                        v-model="pageInput"
                        inputmode="numeric"
                        class="fn-input w-11 rounded-md px-1 py-0.5 text-center text-xs"
                        :aria-label="t('reader.page', 'صفحة')"
                        @blur="pageInput = String(current)"
                    />
                    <span class="fn-muted whitespace-nowrap">/ {{ pages }}</span>
                </form>
                <button type="button" class="fn-btn hidden sm:inline-flex" :title="t('pdf.next', 'التالي')" :disabled="current >= pages" @click="stepPage(1)">
                    <ChevronDownIcon class="h-4 w-4" />
                </button>
            </div>
            <span v-if="!embedded && fileName" class="mx-2 hidden min-w-0 truncate text-sm font-medium md:block"><bdi>{{ fileName }}</bdi></span>
            <span class="flex-1" />
            <div v-if="pages" class="hidden items-center gap-0.5 md:flex">
                <button type="button" class="fn-btn" :title="t('reader.zoom_out', 'تصغير')" @click="setZoom(zoom - 0.1)"><MagnifyingGlassMinusIcon class="h-5 w-5" /></button>
                <button type="button" class="fn-btn min-w-[3.25rem] text-xs tabular-nums" :title="t('reader.zoom_reset', 'الحجم الأصلي')" @click="setZoom(1)">{{ zoomLabel }}</button>
                <button type="button" class="fn-btn" :title="t('reader.zoom_in', 'تكبير')" @click="setZoom(zoom + 0.1)"><MagnifyingGlassPlusIcon class="h-5 w-5" /></button>
                <span class="fn-sep" />
                <button v-for="m in modeOptions" :key="m.id" type="button" class="fn-btn" :class="{ 'fn-on': mode === m.id }" :title="m.label()" @click="mode = m.id">
                    <component :is="m.icon" class="h-5 w-5" />
                </button>
                <span class="fn-sep" />
            </div>
            <button type="button" class="fn-btn" :class="{ 'fn-on': showSearch }" :title="t('pdf.search', 'ابحث داخل الملف')" @click="toggleSearch">
                <MagnifyingGlassIcon class="h-5 w-5" />
            </button>
            <button type="button" class="fn-btn" :title="t('reader.bookmark', 'علامة مرجعية')" @click="toggleBookmark()">
                <BookmarkSolidIcon v-if="isBookmarked(current)" class="h-5 w-5 text-[var(--accent-600)]" />
                <BookmarkIcon v-else class="h-5 w-5" />
            </button>
            <button type="button" class="fn-btn" :class="{ 'fn-on': annotating }" :title="t('reader.annotate', 'تظليل وكتابة وتوقيع')" @click="annotating = !annotating">
                <PencilIcon class="h-5 w-5" />
            </button>
            <button type="button" class="fn-btn hidden sm:inline-flex" :class="{ 'fn-on': autoOn }" :title="t('reader.auto', 'تمرير تلقائي')" @click="autoOn ? stopAuto() : startAuto()">
                <PauseIcon v-if="autoOn" class="h-5 w-5" />
                <PlayIcon v-else class="h-5 w-5" />
            </button>
            <button v-if="canFull" type="button" class="fn-btn hidden sm:inline-flex" :title="t('reader.fullscreen', 'ملء الشاشة')" @click="toggleFull">
                <ArrowsPointingInIcon v-if="full" class="h-5 w-5" />
                <ArrowsPointingOutIcon v-else class="h-5 w-5" />
            </button>
            <div class="relative">
                <button type="button" class="fn-btn" :class="{ 'fn-on': menu }" :title="t('reader.more', 'العرض والمزيد')" @click="menu = !menu">
                    <EllipsisHorizontalIcon class="h-5 w-5" />
                </button>
                <div v-if="menu" class="fixed inset-0 z-40" @click="menu = false" />
                <div v-if="menu" class="fn-menu absolute end-0 top-full z-50 mt-1 w-72 max-w-[calc(100vw-1rem)] rounded-xl border p-3 text-sm shadow-xl">
                    <p class="fn-label">{{ t('reader.view', 'طريقة العرض') }}</p>
                    <div class="mb-3 grid grid-cols-3 gap-1">
                        <button
                            v-for="m in modeOptions"
                            :key="m.id"
                            type="button"
                            class="flex flex-col items-center gap-1 rounded-lg border px-1 py-2 text-[11px]"
                            :class="mode === m.id ? 'border-[var(--accent-600)] text-[var(--accent-600)]' : 'fn-line'"
                            @click="mode = m.id"
                        >
                            <component :is="m.icon" class="h-5 w-5" />{{ m.label() }}
                        </button>
                    </div>
                    <div class="mb-3 flex flex-wrap items-center gap-1">
                        <button type="button" class="fn-chip" :class="{ 'fn-chip-on': fit === 'width' }" @click="(fit = 'width'), setZoom(1)">{{ t('reader.fit_width', 'ملء العرض') }}</button>
                        <button type="button" class="fn-chip" :class="{ 'fn-chip-on': fit === 'page' }" @click="(fit = 'page'), setZoom(1)">{{ t('reader.fit_page', 'الصفحة كاملة') }}</button>
                        <span class="flex-1" />
                        <button type="button" class="fn-btn" :title="t('reader.zoom_out', 'تصغير')" @click="setZoom(zoom - 0.1)"><MagnifyingGlassMinusIcon class="h-4 w-4" /></button>
                        <span class="w-10 text-center text-xs tabular-nums">{{ zoomLabel }}</span>
                        <button type="button" class="fn-btn" :title="t('reader.zoom_in', 'تكبير')" @click="setZoom(zoom + 0.1)"><MagnifyingGlassPlusIcon class="h-4 w-4" /></button>
                    </div>
                    <p class="fn-label">{{ t('reader.colors', 'لون القراءة') }}</p>
                    <div class="mb-3 grid grid-cols-4 gap-1">
                        <button
                            v-for="o in themeOptions"
                            :key="o.id"
                            type="button"
                            class="flex flex-col items-center gap-1 rounded-lg border px-1 py-1.5 text-[11px]"
                            :class="theme === o.id ? 'border-[var(--accent-600)]' : 'fn-line'"
                            @click="theme = o.id"
                        >
                            <span class="h-5 w-8 rounded border border-black/10" :style="{ background: o.swatch }" />{{ o.label() }}
                        </button>
                    </div>
                    <p class="fn-label">{{ t('reader.auto_speed', 'سرعة التمرير التلقائي') }}</p>
                    <div class="mb-3 flex items-center gap-2">
                        <input v-model.number="autoSpeed" type="range" min="1" max="10" class="flex-1" />
                        <button type="button" class="fn-btn" @click="autoOn ? stopAuto() : startAuto()">
                            <PauseIcon v-if="autoOn" class="h-4 w-4" />
                            <PlayIcon v-else class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="grid grid-cols-2 gap-1">
                        <button type="button" class="fn-item" @click="rotate()"><ArrowPathIcon class="h-4 w-4" />{{ t('reader.rotate', 'تدوير') }}</button>
                        <button type="button" class="fn-item" @click="(menu = false), openSidebar('outline')"><ListBulletIcon class="h-4 w-4" />{{ t('reader.outline', 'الفهرس') }}</button>
                        <button v-if="canDownload" type="button" class="fn-item" @click="openPrint"><PrinterIcon class="h-4 w-4" />{{ t('reader.print', 'طباعة') }}</button>
                        <a v-if="canDownload && downloadUrl" :href="downloadUrl" class="fn-item"><ArrowDownTrayIcon class="h-4 w-4" />{{ t('reader.download', 'تنزيل') }}</a>
                        <button v-if="canFull" type="button" class="fn-item" @click="(menu = false), toggleFull()">
                            <ArrowsPointingOutIcon class="h-4 w-4" />{{ t('reader.fullscreen', 'ملء الشاشة') }}
                        </button>
                        <a v-if="fileId" :href="route('pdf-tools.index', { 'files[]': fileId })" class="fn-item"><WrenchScrewdriverIcon class="h-4 w-4" />{{ t('nav.pdf_tools', 'أدوات PDF') }}</a>
                        <a v-if="readerHref" :href="readerHref" class="fn-item"><BookOpenIcon class="h-4 w-4" />{{ t('reader.open_full', 'فتح في القارئ') }}</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search -->
        <form v-if="showSearch && pages" class="fn-bar flex shrink-0 items-center gap-1.5 border-b px-2 py-1.5" @submit.prevent="search">
            <input
                ref="searchInput"
                v-model="query"
                type="search"
                dir="auto"
                :placeholder="t('pdf.search', 'ابحث داخل الملف')"
                class="min-w-0 flex-1 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                @search="!query && clearSearch()"
            />
            <span v-if="searching" class="fn-muted text-xs">{{ t('pdf.searching', 'يبحث…') }}</span>
            <template v-else-if="hits.length">
                <span dir="ltr" class="fn-muted text-xs tabular-nums">{{ hitIndex + 1 }} / {{ hits.length }}</span>
                <button type="button" class="fn-btn" :title="t('pdf.prev', 'السابق')" @click="stepHit(-1)"><ChevronUpIcon class="h-4 w-4" /></button>
                <button type="button" class="fn-btn" :title="t('pdf.next', 'التالي')" @click="stepHit(1)"><ChevronDownIcon class="h-4 w-4" /></button>
            </template>
            <span v-else-if="searched" class="fn-muted text-xs">{{ t('pdf.no_hits', 'لا نتائج') }}</span>
            <button type="submit" class="rounded-lg bg-[var(--accent-600)] px-3 py-1 text-xs font-semibold text-white">{{ t('pdf.find', 'بحث') }}</button>
        </form>

        <!-- Annotation tools -->
        <div v-if="annotating" class="fn-bar flex shrink-0 flex-wrap items-center gap-1 border-b px-1.5 py-1">
            <button
                v-for="tl in tools"
                :key="tl.id"
                type="button"
                class="fn-btn gap-1 px-2"
                :class="{ 'fn-on': tool === tl.id }"
                :title="tl.label()"
                @click="tl.id === 'sign' && !signatureStrokes ? ((tool = 'sign'), (signing = { page: null })) : (tool = tl.id)"
            >
                <component :is="tl.icon" v-if="tl.icon" class="h-5 w-5" />
                <span v-else class="w-5 text-center font-serif text-base font-bold leading-5">T</span>
                <span class="hidden text-xs lg:inline">{{ tl.label() }}</span>
            </button>
            <template v-if="tool === 'pen' || tool === 'text'">
                <span class="fn-sep" />
                <button
                    v-for="c in PEN_COLORS"
                    :key="c"
                    type="button"
                    class="h-6 w-6 rounded-full border-2"
                    :class="penColor === c ? 'border-[var(--accent-600)] ring-2 ring-[var(--accent-500)]/30' : 'border-white/70'"
                    :style="{ background: c }"
                    :aria-label="c"
                    @click="penColor = c"
                />
                <select v-if="tool === 'pen'" v-model.number="penSize" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option :value="2">{{ t('reader.thin', 'رفيع') }}</option>
                    <option :value="4">{{ t('reader.medium', 'متوسط') }}</option>
                    <option :value="8">{{ t('reader.thick', 'عريض') }}</option>
                    <option :value="20">{{ t('reader.marker', 'قلم تظليل') }}</option>
                </select>
            </template>
            <button v-if="tool === 'sign' && signatureStrokes" type="button" class="fn-chip" @click="signing = { page: null }">{{ t('reader.sign_change', 'تغيير التوقيع') }}</button>
            <span class="flex-1" />
            <button type="button" class="fn-btn" :disabled="!undoStack.length" :title="t('reader.undo', 'تراجع')" @click="undo"><ArrowUturnLeftIcon class="h-5 w-5 rtl:-scale-x-100" /></button>
            <button type="button" class="fn-btn" :disabled="!redoStack.length" :title="t('reader.redo', 'إعادة')" @click="redo"><ArrowUturnRightIcon class="h-5 w-5 rtl:-scale-x-100" /></button>
            <button type="button" class="fn-btn" :title="t('common.close', 'إغلاق')" @click="annotating = false"><XMarkIcon class="h-5 w-5" /></button>
            <p class="fn-muted w-full px-1 text-[11px]">{{ toolHint }}</p>
        </div>

        <p v-if="hasText === false" class="shrink-0 bg-amber-50 px-3 py-1 text-[11px] text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
            {{ t('pdf.no_text', 'هذا الملف صور بلا نص بعد؛ يصبح قابلاً للنسخ والبحث بعد أن يقرأه التعرف على النصوص.') }}
        </p>

        <div class="relative flex min-h-0 flex-1">
            <!-- Sidebar -->
            <div v-if="sidebar" class="absolute inset-0 z-20 bg-black/30 md:hidden" @click="sidebar = false" />
            <aside v-if="sidebar && pages" class="fn-side absolute inset-y-0 start-0 z-30 flex w-60 flex-col border-e md:static md:z-auto">
                <div class="flex shrink-0 items-center gap-0.5 border-b p-1">
                    <button type="button" class="fn-tab" :class="{ 'fn-tab-on': tab === 'thumbs' }" :title="t('reader.thumbs', 'الصفحات')" @click="tab = 'thumbs'"><Squares2X2Icon class="h-5 w-5" /></button>
                    <button type="button" class="fn-tab" :class="{ 'fn-tab-on': tab === 'outline' }" :title="t('reader.outline', 'الفهرس')" @click="tab = 'outline'"><ListBulletIcon class="h-5 w-5" /></button>
                    <button type="button" class="fn-tab" :class="{ 'fn-tab-on': tab === 'bookmarks' }" :title="t('reader.bookmarks', 'العلامات')" @click="tab = 'bookmarks'"><BookmarkIcon class="h-5 w-5" /></button>
                    <button type="button" class="fn-tab" :class="{ 'fn-tab-on': tab === 'notes' }" :title="t('reader.my_notes', 'ملاحظاتي')" @click="tab = 'notes'">
                        <ChatBubbleLeftEllipsisIcon class="h-5 w-5" />
                    </button>
                    <button type="button" class="fn-btn ms-auto md:hidden" @click="sidebar = false"><XMarkIcon class="h-5 w-5" /></button>
                </div>
                <div v-if="tab === 'thumbs'" ref="thumbList" class="min-h-0 flex-1 overflow-y-auto p-2">
                    <button
                        v-for="n in pages"
                        :key="n"
                        type="button"
                        class="mb-2 block w-full rounded-lg p-1 text-center"
                        :class="current === n ? 'bg-[var(--accent-600)]/15 ring-2 ring-[var(--accent-600)]' : 'hover:bg-black/5'"
                        @click="goTo(n)"
                    >
                        <div
                            :ref="(el) => (thumbEls[n - 1] = el)"
                            :data-page="n"
                            class="fn-thumb relative mx-auto w-[104px] overflow-hidden bg-white shadow-sm"
                            :style="{ aspectRatio: `1 / ${ratioOf(n)}` }"
                        />
                        <span class="fn-muted mt-1 flex items-center justify-center gap-1 text-[11px] tabular-nums">
                            {{ n }}<BookmarkSolidIcon v-if="isBookmarked(n)" class="h-3 w-3 text-[var(--accent-600)]" />
                            <span v-if="byPage[n]?.length" class="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        </span>
                    </button>
                </div>
                <div v-else-if="tab === 'outline'" class="min-h-0 flex-1 overflow-y-auto py-1 text-sm">
                    <p v-if="!outline.length" class="fn-muted p-4 text-center text-xs">{{ t('reader.no_outline', 'لا يحتوي هذا الملف على فهرس.') }}</p>
                    <button
                        v-for="(o, i) in outline"
                        :key="i"
                        type="button"
                        class="flex w-full items-start gap-2 px-3 py-1.5 text-start hover:bg-black/5 disabled:opacity-50"
                        :style="{ paddingInlineStart: 12 + o.depth * 14 + 'px' }"
                        :disabled="!o.page"
                        @click="goTo(o.page)"
                    >
                        <span class="min-w-0 flex-1" :class="o.depth ? '' : 'font-medium'" dir="auto">{{ o.title }}</span>
                        <span class="fn-muted shrink-0 text-[11px] tabular-nums">{{ o.page }}</span>
                    </button>
                </div>
                <div v-else-if="tab === 'bookmarks'" class="min-h-0 flex-1 overflow-y-auto py-1 text-sm">
                    <button type="button" class="mx-2 mb-1 flex w-[calc(100%-1rem)] items-center justify-center gap-1 rounded-lg border border-dashed py-1.5 text-xs fn-line" @click="toggleBookmark()">
                        <BookmarkIcon class="h-4 w-4" />{{ isBookmarked(current) ? t('reader.unbookmark', 'إزالة علامة هذه الصفحة') : t('reader.bookmark_this', 'علّم هذه الصفحة') }}
                    </button>
                    <p v-if="!state.bookmarks.length" class="fn-muted p-4 text-center text-xs">{{ t('reader.no_bookmarks', 'لا علامات بعد.') }}</p>
                    <div v-for="b in state.bookmarks" :key="b.page" class="group flex items-center gap-2 px-3 py-1.5 hover:bg-black/5">
                        <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-start" @click="goTo(b.page)">
                            <BookmarkSolidIcon class="h-4 w-4 shrink-0 text-[var(--accent-600)]" />
                            <span class="min-w-0 flex-1 truncate" dir="auto">{{ b.title || t('reader.page', 'صفحة') + ' ' + b.page }}</span>
                            <span class="fn-muted text-[11px] tabular-nums">{{ b.page }}</span>
                        </button>
                        <button type="button" class="fn-muted opacity-60 hover:text-red-600 group-hover:opacity-100" @click="toggleBookmark(b.page)"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                </div>
                <div v-else class="min-h-0 flex-1 overflow-y-auto py-1 text-sm">
                    <p v-if="!notesList.length" class="fn-muted p-4 text-center text-xs">{{ t('reader.no_notes', 'ظلّل نصاً أو أضف ملاحظة من زر القلم، وتظهر هنا.') }}</p>
                    <button v-else type="button" class="mx-2 mb-1 flex w-[calc(100%-1rem)] items-center justify-center gap-1 rounded-lg border py-1.5 text-xs fn-line" @click="exportNotes">
                        <ArrowDownTrayIcon class="h-4 w-4" />{{ t('reader.export_notes', 'تصدير الملاحظات نصاً') }}
                    </button>
                    <div v-for="a in notesList" :key="a.id" class="group flex items-start gap-2 px-3 py-1.5 hover:bg-black/5">
                        <span class="mt-1 h-3 w-3 shrink-0 rounded-sm" :style="{ background: a.color || '#f59e0b' }" />
                        <button type="button" class="min-w-0 flex-1 text-start" @click="goTo(a.page)">
                            <span class="fn-muted block text-[11px]">{{ annLabel(a) }} · {{ t('reader.page', 'صفحة') }} {{ a.page }}</span>
                            <span v-if="a.text" class="line-clamp-3 block text-xs" dir="auto">{{ a.text }}</span>
                        </button>
                        <button type="button" class="fn-muted opacity-60 hover:text-red-600 group-hover:opacity-100" @click="removeAnn(a.id)"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                </div>
            </aside>

            <!-- Pages -->
            <div
                ref="scroller"
                class="fn-scroller relative min-h-0 min-w-0 flex-1 overscroll-contain"
                :class="mode === 'paged' ? 'flex snap-x snap-mandatory overflow-x-auto overflow-y-hidden' : 'overflow-auto'"
                @wheel.passive="userScrolled"
                @touchstart.passive="userScrolled"
            >
                <div :class="containerClass">
                    <div v-for="n in pages" :key="n" :class="pageWrapClass" :style="mode === 'paged' ? { width: box.w + 'px' } : null">
                        <div
                            :ref="(el) => (pageEls[n - 1] = el)"
                            :data-page="n"
                            class="fn-page relative shrink-0 bg-white shadow-md"
                            :style="{ width: pw(n) + 'px', height: ph(n) + 'px' }"
                        >
                            <div :ref="(el) => (slotEls[n - 1] = el)" class="fn-slot absolute inset-0" />
                            <svg
                                :ref="(el) => (svgEls[n - 1] = el)"
                                class="fn-ann"
                                :class="{ 'fn-ann-active': annotating && tool !== 'select', 'touch-none': annotating && tool === 'pen' }"
                                :viewBox="`0 0 1000 ${boardH(n)}`"
                                preserveAspectRatio="none"
                                :style="{ width: unrotW(n) + 'px', height: unrotH(n) + 'px', transform: `translate(-50%, -50%) rotate(${rotation}deg)` }"
                                @pointerdown="svgDown(n, $event)"
                                @pointermove="svgMove(n, $event)"
                                @pointerup="svgUp"
                                @pointercancel="svgUp"
                            >
                                <template v-for="a in byPage[n] || []" :key="a.id">
                                    <g v-if="a.type === 'highlight'" :data-ann="a.id" style="mix-blend-mode: multiply">
                                        <rect v-for="(r, i) in a.rects" :key="i" :x="r[0]" :y="r[1]" :width="r[2]" :height="r[3]" :fill="a.color" fill-opacity="0.45" rx="2" />
                                    </g>
                                    <g v-else-if="a.type === 'underline' || a.type === 'strike'" :data-ann="a.id">
                                        <rect v-for="(r, i) in a.rects" :key="'h' + i" :x="r[0]" :y="r[1]" :width="r[2]" :height="r[3]" fill="transparent" />
                                        <line
                                            v-for="(r, i) in a.rects"
                                            :key="i"
                                            :x1="r[0]"
                                            :x2="r[0] + r[2]"
                                            :y1="a.type === 'strike' ? r[1] + r[3] * 0.55 : r[1] + r[3] - 0.5"
                                            :y2="a.type === 'strike' ? r[1] + r[3] * 0.55 : r[1] + r[3] - 0.5"
                                            :stroke="a.color"
                                            :stroke-width="Math.max(1.5, r[3] * 0.09)"
                                            stroke-linecap="round"
                                        />
                                    </g>
                                    <path
                                        v-else-if="a.type === 'ink'"
                                        :data-ann="a.id"
                                        :d="inkPath(a.points)"
                                        fill="none"
                                        :stroke="a.color"
                                        :stroke-width="a.size"
                                        :stroke-opacity="a.size >= 20 ? 0.4 : 1"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                    <text
                                        v-else-if="a.type === 'text'"
                                        :data-ann="a.id"
                                        class="fn-clickable"
                                        :x="a.x"
                                        :y="a.y"
                                        :fill="a.color"
                                        :font-size="a.size"
                                        dominant-baseline="hanging"
                                        font-family="system-ui, sans-serif"
                                        @pointerdown.stop="!annotating || tool === 'select' || tool === 'eraser' ? openAnn(a, $event) : null"
                                    >
                                        <tspan v-for="(line, i) in a.text.split('\n')" :key="i" :x="a.x" :dy="i ? a.size * 1.25 : 0">{{ line }}</tspan>
                                    </text>
                                    <g
                                        v-else-if="a.type === 'note'"
                                        :data-ann="a.id"
                                        class="fn-clickable"
                                        :transform="`translate(${a.x} ${a.y}) scale(${unit(n, 26) / 24})`"
                                        @pointerdown.stop="openAnn(a, $event)"
                                    >
                                        <title class="fn-note-text">{{ a.text }}</title>
                                        <rect x="0" y="0" width="24" height="24" rx="5" :fill="a.color" stroke="#92400e" stroke-width="1" />
                                        <path d="M6 8h12M6 12h12M6 16h8" stroke="#78350f" stroke-width="1.6" stroke-linecap="round" />
                                    </g>
                                    <svg v-else-if="a.type === 'sign'" :data-ann="a.id" :x="a.x" :y="a.y" :width="a.w" :height="a.h" viewBox="0 0 600 240">
                                        <rect width="600" height="240" fill="transparent" />
                                        <path
                                            v-for="(s, i) in a.strokes"
                                            :key="i"
                                            :d="inkPath(s)"
                                            fill="none"
                                            stroke="#1e3a8a"
                                            stroke-width="5"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </svg>
                                </template>
                                <path
                                    v-if="liveInk && liveInk.page === n && liveInk.points.length > 1"
                                    :d="inkPath(liveInk.points)"
                                    fill="none"
                                    :stroke="penColor"
                                    :stroke-width="penSize"
                                    :stroke-opacity="penSize >= 20 ? 0.4 : 1"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <BookmarkSolidIcon v-if="isBookmarked(n)" class="pointer-events-none absolute -top-1 end-3 z-[3] h-7 w-7 text-[var(--accent-600)] drop-shadow" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Page turn buttons (one page / two pages) -->
            <template v-if="pages && mode !== 'scroll'">
                <button type="button" class="fn-turn start-2" :disabled="current <= 1" :aria-label="t('pdf.prev', 'السابق')" @click="stepPage(-1)">
                    <ChevronRightIcon v-if="rtl" class="h-6 w-6" />
                    <ChevronLeftIcon v-else class="h-6 w-6" />
                </button>
                <button type="button" class="fn-turn end-2" :disabled="current >= pages" :aria-label="t('pdf.next', 'التالي')" @click="stepPage(1)">
                    <ChevronLeftIcon v-if="rtl" class="h-6 w-6" />
                    <ChevronRightIcon v-else class="h-6 w-6" />
                </button>
            </template>
        </div>

        <!-- Page indicator and chapter -->
        <div v-if="pages" dir="auto" class="pointer-events-none absolute bottom-3 left-1/2 z-10 max-w-[80%] -translate-x-1/2 truncate rounded-full bg-black/65 px-3 py-1 text-xs text-white">
            <span dir="ltr" class="tabular-nums">{{ current }} / {{ pages }}</span>
            <template v-if="chapterOf(current)"> · {{ chapterOf(current) }}</template>
            <template v-if="saving || saveFailed"> · {{ saveFailed ? t('reader.not_saved', 'لم يُحفظ بعد') : t('reader.saving', 'يحفظ…') }}</template>
        </div>

        <!-- Resumed where the reader stopped -->
        <div v-if="resumed" class="absolute inset-x-0 bottom-12 z-20 flex justify-center px-3">
            <div class="flex items-center gap-3 rounded-xl bg-gray-900/90 px-4 py-2 text-sm text-white shadow-lg">
                {{ t('reader.resumed', 'تابعتَ من حيث توقفت: صفحة') }} {{ resumed }}
                <button type="button" class="font-semibold text-[var(--accent-500)] underline-offset-2 hover:underline" @click="(goTo(1), (resumed = 0))">
                    {{ t('reader.from_start', 'من البداية') }}
                </button>
            </div>
        </div>

        <!-- Selection: highlight, underline, strike, note, copy -->
        <div
            v-if="selPop && (!annotating || tool === 'select')"
            class="fn-menu absolute z-40 flex items-center gap-1 rounded-xl border p-1 shadow-xl"
            :style="{ left: selPop.left + 'px', top: selPop.top + 'px' }"
            @pointerdown="selBusy = true"
            @pointerup="selBusy = false"
            @mousedown.prevent
        >
            <button
                v-for="c in COLORS"
                :key="c"
                type="button"
                class="h-7 w-7 rounded-full border border-black/10"
                :style="{ background: c }"
                :title="t('reader.highlight', 'تظليل')"
                @click="markSelection('highlight', c)"
            />
            <span class="fn-sep" />
            <button type="button" class="fn-btn font-semibold underline decoration-2 underline-offset-2" :title="t('reader.underline', 'تسطير')" @click="markSelection('underline', '#2563eb')">U</button>
            <button type="button" class="fn-btn font-semibold line-through decoration-2" :title="t('reader.strike', 'شطب')" @click="markSelection('strike', '#dc2626')">S</button>
            <button type="button" class="fn-btn" :title="t('reader.note', 'ملاحظة')" @click="noteOnSelection"><ChatBubbleLeftEllipsisIcon class="h-5 w-5" /></button>
            <button type="button" class="fn-btn text-xs" @click="copySelection">{{ t('reader.copy', 'نسخ') }}</button>
        </div>

        <!-- Text / note editor -->
        <div v-if="editor" class="fn-menu absolute z-50 w-72 rounded-xl border p-3 shadow-xl" :style="{ left: editor.left + 'px', top: editor.top + 'px' }">
            <p class="fn-label">{{ editor.mode === 'note' ? t('reader.note', 'ملاحظة') : t('reader.text', 'نص') }} · {{ t('reader.page', 'صفحة') }} {{ editor.page }}</p>
            <textarea
                v-model="editor.text"
                rows="4"
                dir="auto"
                class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                :placeholder="editor.mode === 'note' ? t('reader.note_ph', 'اكتب ملاحظتك…') : t('reader.text_ph', 'اكتب النص الذي يظهر على الصفحة…')"
                @keydown.ctrl.enter="saveEditor"
                @keydown.meta.enter="saveEditor"
            />
            <div class="mt-2 flex items-center gap-2">
                <button v-if="editor.id" type="button" class="rounded-lg px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="(removeAnn(editor.id), (editor = null))">
                    {{ t('common.delete', 'حذف') }}
                </button>
                <span class="flex-1" />
                <button type="button" class="rounded-lg px-3 py-1 text-xs fn-muted hover:bg-black/5" @click="editor = null">{{ t('common.cancel', 'إلغاء') }}</button>
                <button type="button" class="rounded-lg bg-[var(--accent-600)] px-3 py-1 text-xs font-semibold text-white" @click="saveEditor">{{ t('common.save', 'حفظ') }}</button>
            </div>
        </div>

        <!-- Print -->
        <div v-if="printing" class="absolute inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="printing = null">
            <div class="fn-menu w-full max-w-xs rounded-2xl border p-4 text-sm shadow-xl">
                <h3 class="mb-3 font-semibold">{{ t('reader.print', 'طباعة') }}</h3>
                <label class="mb-1.5 flex items-center gap-2"><input v-model="printing.which" type="radio" value="all" />{{ t('reader.print_all', 'كل الصفحات') }} ({{ pages }})</label>
                <label class="mb-1.5 flex items-center gap-2"><input v-model="printing.which" type="radio" value="current" />{{ t('reader.print_current', 'الصفحة الحالية') }} ({{ current }})</label>
                <label class="mb-3 flex items-center gap-2">
                    <input v-model="printing.which" type="radio" value="range" />{{ t('reader.print_range', 'من') }}
                    <input v-model.number="printing.from" type="number" min="1" :max="pages" class="w-16 rounded border-gray-300 px-1 py-0.5 text-xs dark:bg-gray-900" @focus="printing.which = 'range'" />
                    {{ t('reader.to', 'إلى') }}
                    <input v-model.number="printing.to" type="number" min="1" :max="pages" class="w-16 rounded border-gray-300 px-1 py-0.5 text-xs dark:bg-gray-900" @focus="printing.which = 'range'" />
                </label>
                <p class="fn-muted mb-3 text-[11px]">{{ t('reader.print_hint', 'تُطبع ملاحظاتك وتظليلك مع الصفحات. لحفظ نسخة PDF بملاحظاتك اختر «حفظ بصيغة PDF» في نافذة الطباعة.') }}</p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg px-3 py-1.5 text-xs hover:bg-black/5" @click="printing = null">{{ t('common.cancel', 'إلغاء') }}</button>
                    <button type="button" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-xs font-semibold text-white" @click="doPrint">{{ t('reader.print', 'طباعة') }}</button>
                </div>
            </div>
        </div>
        <div v-if="printBusy" class="absolute inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="rounded-xl bg-white px-5 py-3 text-sm shadow-xl dark:bg-gray-800 dark:text-gray-100">
                {{ t('reader.preparing_print', 'تجهيز الطباعة…') }} <span dir="ltr" class="tabular-nums">{{ printBusy.done }} / {{ printBusy.total }}</span>
            </div>
        </div>

        <SignaturePad v-if="signing" :initial="signatureStrokes" @save="saveSignature" @cancel="signing = null" @forget="forgetSignature" />

        <div v-if="flashText" class="absolute inset-x-0 top-14 z-50 flex justify-center">
            <span class="rounded-full bg-gray-900/90 px-4 py-1.5 text-xs text-white shadow">{{ flashText }}</span>
        </div>

        <div v-if="loading" class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm fn-muted">
            <div class="h-8 w-8 animate-spin rounded-full border-4 border-[var(--accent-600)] border-t-transparent" />
            <span>{{ t('pdf.loading', 'جارٍ تحميل الملف…') }} <span v-if="progress" dir="ltr">{{ progress }}%</span></span>
        </div>
        <div v-else-if="failed" class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm fn-muted">
            {{ t('pdf.failed', 'تعذّر عرض الملف هنا. استخدم زر التنزيل لفتحه.') }}
        </div>
    </div>
</template>

<style>
/* ---- Reader chrome, light and dark ---- */
.fn-reader {
    --fn-bg: #e5e7eb;
    --fn-bar: #ffffff;
    --fn-line: #e5e7eb;
    --fn-text: #1f2937;
    --fn-muted: #6b7280;
    background: var(--fn-bg);
    color: var(--fn-text);
}
.dark .fn-reader {
    --fn-bg: #111827;
    --fn-bar: #1f2937;
    --fn-line: #374151;
    --fn-text: #f3f4f6;
    --fn-muted: #9ca3af;
}
.fn-reader.fn-theme-night {
    --fn-bg: #0b0f17;
    --fn-bar: #111827;
    --fn-line: #1f2937;
    --fn-text: #e5e7eb;
    --fn-muted: #9ca3af;
}
.fn-reader.fn-theme-sepia {
    --fn-bg: #e8dcc0;
}
.fn-reader.fn-theme-green {
    --fn-bg: #c5dcc5;
}
.fn-bar,
.fn-side,
.fn-menu {
    background: var(--fn-bar);
    border-color: var(--fn-line);
    color: var(--fn-text);
}
.fn-line {
    border-color: var(--fn-line);
}
.fn-input {
    border-color: var(--fn-line);
    background: color-mix(in srgb, var(--fn-bg) 40%, var(--fn-bar));
    color: var(--fn-text);
}
.fn-muted {
    color: var(--fn-muted);
}
.fn-label {
    margin-bottom: 0.375rem;
    font-size: 11px;
    font-weight: 600;
    color: var(--fn-muted);
}
.fn-btn:not(.hidden) {
    display: inline-flex;
}
.fn-btn {
    min-height: 2.25rem;
    min-width: 2.25rem;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    color: inherit;
    transition: background 0.15s;
}
.fn-btn:hover:not(:disabled) {
    background: color-mix(in srgb, var(--fn-text) 8%, transparent);
}
.fn-btn:disabled {
    opacity: 0.35;
}
.fn-on {
    background: color-mix(in srgb, var(--accent-600) 16%, transparent) !important;
    color: var(--accent-600);
}
.fn-sep {
    margin: 0 0.125rem;
    height: 1.25rem;
    width: 1px;
    background: var(--fn-line);
}
.fn-chip {
    white-space: nowrap;
    border-radius: 9999px;
    border: 1px solid var(--fn-line);
    padding: 0.25rem 0.625rem;
    font-size: 11px;
}
.fn-chip-on {
    border-color: var(--accent-600);
    color: var(--accent-600);
}
.fn-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border-radius: 0.5rem;
    padding: 0.5rem;
    font-size: 12px;
}
.fn-item:hover {
    background: color-mix(in srgb, var(--fn-text) 8%, transparent);
}
.fn-tab {
    display: inline-flex;
    flex: 1;
    justify-content: center;
    border-radius: 0.5rem;
    padding: 0.375rem;
    color: var(--fn-muted);
}
.fn-tab-on {
    background: color-mix(in srgb, var(--accent-600) 14%, transparent);
    color: var(--accent-600);
}
.fn-turn {
    position: absolute;
    top: 50%;
    z-index: 10;
    display: none;
    height: 2.75rem;
    width: 2.75rem;
    transform: translateY(-50%);
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    background: rgb(0 0 0 / 0.45);
    color: #fff;
}
.fn-turn:disabled {
    opacity: 0.2;
}
@media (pointer: fine) {
    .fn-turn {
        display: inline-flex;
    }
}

/* ---- Pages ---- */
.fn-canvas {
    display: block;
    width: 100%;
    height: 100%;
}
.fn-theme-night .fn-canvas {
    filter: invert(0.92) hue-rotate(180deg) contrast(0.95);
}
.fn-theme-night .fn-page,
.fn-theme-night .fn-thumb {
    background: #121212;
}
.fn-theme-sepia .fn-page::after,
.fn-theme-green .fn-page::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
    pointer-events: none;
    mix-blend-mode: multiply;
}
.fn-theme-sepia .fn-page::after {
    background: #f4e7c5;
}
.fn-theme-green .fn-page::after {
    background: #cfe8cf;
}
.fn-ann {
    position: absolute;
    left: 50%;
    top: 50%;
    z-index: 2;
    overflow: visible;
    pointer-events: none;
}
.fn-ann .fn-clickable {
    pointer-events: auto;
    cursor: pointer;
}
.fn-ann-active {
    pointer-events: auto;
    cursor: crosshair;
}
.fn-ann-active [data-ann] {
    cursor: pointer;
}

/* pdf.js text layer: transparent text exactly over the printed words, selectable. */
.textLayer {
    position: absolute;
    inset: 0;
    overflow: clip;
    line-height: 1;
    text-align: initial;
    -webkit-text-size-adjust: none;
    text-size-adjust: none;
    forced-color-adjust: none;
    transform-origin: 0 0;
    z-index: 0;
    --min-font-size: 1;
    --text-scale-factor: calc(var(--total-scale-factor) * var(--min-font-size));
    --min-font-size-inv: calc(1 / var(--min-font-size));
    --scale-round-x: 1px;
    --scale-round-y: 1px;
}
.textLayer[data-main-rotation='90'] {
    transform: rotate(90deg) translateY(-100%);
}
.textLayer[data-main-rotation='180'] {
    transform: rotate(180deg) translate(-100%, -100%);
}
.textLayer[data-main-rotation='270'] {
    transform: rotate(270deg) translateX(-100%);
}
.textLayer :is(span, br) {
    color: transparent;
    position: absolute;
    white-space: pre;
    cursor: text;
    transform-origin: 0% 0%;
}
.textLayer > :not(.markedContent),
.textLayer .markedContent span:not(.markedContent) {
    z-index: 1;
    --font-height: 0;
    font-size: calc(var(--text-scale-factor) * var(--font-height));
    --scale-x: 1;
    --rotate: 0deg;
    transform: rotate(var(--rotate)) scaleX(var(--scale-x)) scale(var(--min-font-size-inv));
}
.textLayer .markedContent {
    display: contents;
}
.textLayer ::selection {
    background: rgb(0 100 255 / 0.3);
}
.textLayer br::selection {
    background: transparent;
}
.textLayer .fn-hit {
    background: rgb(250 204 21 / 0.45);
    border-radius: 2px;
}
.textLayer .endOfContent {
    display: block;
    position: absolute;
    inset: 100% 0 0;
    z-index: 0;
    cursor: default;
    user-select: none;
}
</style>
