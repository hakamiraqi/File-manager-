<script setup>
import { useTrans } from '@/Composables/useTrans';
// Every page of a PDF, drawn in the page itself (2026-11-11). Phone browsers show a PDF in
// an <iframe> as its first page only (iPhone) or not at all (Android), so on touch screens
// the preview draws the pages with pdf.js instead. Pages are drawn as they scroll into view
// and let go when far away, so a 500-page book does not fill the phone's memory.
//
// Text layer (2026-11-12): each page also gets pdf.js's invisible text over the picture, so
// text can be selected and copied — for scanned PDFs too, once their searchable copy (OCR) is
// made — and a search box finds a word in the whole file and jumps to it.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';

const { t } = useTrans();

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

const props = defineProps({
    src: { type: String, required: true },
});

const scroller = ref(null);
const pageEls = ref([]);
const pages = ref(0);
const ratio = ref(1.414); // height / width, from the first page
const current = ref(1);
const loading = ref(true);
const progress = ref(0);
const failed = ref(false);

let doc = null;
let task = null;
let observer = null;
const drawn = new Map(); // page number => render task
const texts = new Map(); // page number => its plain text (for search)

// ---- Search across the whole file ----
const query = ref('');
const hits = ref([]); // page numbers containing the query
const hitIndex = ref(0);
const searching = ref(false);
const searched = ref(false);
const hasText = ref(null); // null: unknown yet; false: the file has no text layer at all

// Arabic search ignores diacritics, tatweel and the alef/yaa/taa-marbuta variants.
const normalize = (v) =>
    (v || '')
        .toLowerCase()
        .replace(/[\u064B-\u0652\u0640]/g, '')
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
        for (let n = 1; n <= pages.value; n++) {
            if (normalize(await pageText(n)).includes(q)) hits.value.push(n);
        }
    } finally {
        searching.value = false;
        searched.value = true;
    }
    for (const n of drawn.keys()) markHits(n);
    if (hits.value.length) goTo(hits.value[0]);
}

function step(d) {
    if (!hits.value.length) return;
    hitIndex.value = (hitIndex.value + d + hits.value.length) % hits.value.length;
    goTo(hits.value[hitIndex.value]);
}

function goTo(n) {
    const el = pageEls.value[n - 1];
    if (el && scroller.value) scroller.value.scrollTo({ top: el.offsetTop - 8, behavior: 'smooth' });
}

function clearSearch() {
    query.value = '';
    hits.value = [];
    searched.value = false;
    for (const n of drawn.keys()) markHits(n);
}

// Highlight the words of the query in a drawn page's text layer.
function markHits(n) {
    const layer = pageEls.value[n - 1]?.querySelector('.textLayer');
    if (!layer) return;
    const q = normalize(query.value.trim());
    for (const span of layer.querySelectorAll('span')) {
        span.classList.toggle('fn-hit', !!q && hits.value.includes(n) && normalize(span.textContent).includes(q));
    }
}
const hitLabel = computed(() => (hits.value.length ? `${hitIndex.value + 1} / ${hits.value.length}` : ''));

async function draw(n) {
    if (!doc || drawn.has(n)) return;
    const holder = pageEls.value[n - 1];
    if (!holder) return;
    drawn.set(n, null);
    try {
        const page = await doc.getPage(n);
        const base = page.getViewport({ scale: 1 });
        const width = holder.clientWidth || scroller.value?.clientWidth || 360;
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const viewport = page.getViewport({ scale: (width / base.width) * dpr });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        canvas.style.width = '100%';
        canvas.style.height = 'auto';
        canvas.style.display = 'block';
        const render = page.render({ canvasContext: canvas.getContext('2d'), viewport });
        if (!drawn.has(n)) {
            render.cancel();
            return;
        }
        drawn.set(n, render);
        await render.promise;
        if (drawn.get(n) === render) {
            holder.style.aspectRatio = `${base.width} / ${base.height}`;
            // The invisible, selectable text over the picture, at the page's on-screen size.
            const scale = width / base.width;
            holder.style.setProperty('--scale-factor', String(scale));
            holder.style.setProperty('--total-scale-factor', String(scale));
            const layer = document.createElement('div');
            layer.className = 'textLayer';
            holder.replaceChildren(canvas, layer);
            try {
                const content = await page.getTextContent();
                if (content.items.some((i) => i.str.trim())) hasText.value = true;
                else if (hasText.value === null) hasText.value = false;
                texts.set(n, content.items.map((i) => i.str).join(' '));
                if (drawn.get(n) !== render) return;
                await new pdfjs.TextLayer({ textContentSource: content, container: layer, viewport: page.getViewport({ scale }) }).render();
                markHits(n);
            } catch {
                // The picture stays; only selection is missing on this page.
            }
        }
    } catch {
        drawn.delete(n);
    }
}

function forget(n) {
    const render = drawn.get(n);
    if (render) render.cancel();
    drawn.delete(n);
    const holder = pageEls.value[n - 1];
    if (holder) holder.replaceChildren();
}

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
        ratio.value = vp.height / vp.width;
        pages.value = doc.numPages;
        loading.value = false;
        await nextTick();

        observer = new IntersectionObserver(
            (entries) => {
                for (const e of entries) {
                    const n = Number(e.target.dataset.page);
                    if (e.isIntersecting) draw(n);
                    else forget(n);
                }
            },
            { root: scroller.value, rootMargin: '1200px 0px' },
        );
        pageEls.value.forEach((el) => el && observer.observe(el));
    } catch {
        loading.value = false;
        failed.value = true;
    }
}

function onScroll() {
    const box = scroller.value;
    if (!box || !pages.value) return;
    const mid = box.scrollTop + box.clientHeight / 2;
    let n = 1;
    for (const el of pageEls.value) {
        if (el && el.offsetTop <= mid) n = Number(el.dataset.page);
    }
    current.value = n;
}

function close() {
    observer?.disconnect();
    observer = null;
    for (const n of [...drawn.keys()]) forget(n);
    task?.destroy();
    task = null;
    doc = null;
    pages.value = 0;
    texts.clear();
    hits.value = [];
    searched.value = false;
    hasText.value = null;
}

onMounted(open);
onBeforeUnmount(close);
watch(
    () => props.src,
    () => {
        close();
        open();
    },
);
</script>

<template>
    <div class="relative flex h-[70vh] w-full flex-col overflow-hidden rounded bg-gray-200 dark:bg-gray-900">
        <!-- Search the whole file -->
        <form v-if="pages" class="flex shrink-0 items-center gap-1.5 border-b border-gray-300 bg-white px-2 py-1.5 dark:border-gray-700 dark:bg-gray-800" @submit.prevent="search">
            <input
                v-model="query"
                type="search"
                dir="auto"
                :placeholder="t('pdf.search', 'ابحث داخل الملف')"
                class="min-w-0 flex-1 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                @search="!query && clearSearch()"
            />
            <span v-if="searching" class="text-xs text-gray-500">{{ t('pdf.searching', 'يبحث…') }}</span>
            <template v-else-if="hits.length">
                <span dir="ltr" class="text-xs tabular-nums text-gray-600 dark:text-gray-300">{{ hitLabel }}</span>
                <button type="button" class="rounded px-1.5 py-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" :title="t('pdf.prev', 'السابق')" @click="step(-1)">▲</button>
                <button type="button" class="rounded px-1.5 py-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" :title="t('pdf.next', 'التالي')" @click="step(1)">▼</button>
            </template>
            <span v-else-if="searched" class="text-xs text-gray-500">{{ t('pdf.no_hits', 'لا نتائج') }}</span>
            <button type="submit" class="rounded-lg bg-[var(--accent-600)] px-3 py-1 text-xs font-semibold text-white">{{ t('pdf.find', 'بحث') }}</button>
        </form>
        <p v-if="hasText === false" class="shrink-0 bg-amber-50 px-3 py-1 text-[11px] text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
            {{ t('pdf.no_text', 'هذا الملف صور بلا نص بعد؛ يصبح قابلاً للنسخ والبحث بعد أن يقرأه التعرف على النصوص.') }}
        </p>
        <div ref="scroller" class="min-h-0 w-full flex-1 overflow-y-auto overscroll-contain px-1 py-2" @scroll.passive="onScroll">
            <div
                v-for="n in pages"
                :key="n"
                :ref="(el) => (pageEls[n - 1] = el)"
                :data-page="n"
                class="relative mx-auto mb-2 w-full overflow-hidden bg-white shadow"
                :style="{ aspectRatio: `1 / ${ratio}` }"
            />
        </div>

        <div v-if="loading" class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm text-gray-600 dark:text-gray-300">
            <div class="h-8 w-8 animate-spin rounded-full border-4 border-blue-500 border-t-transparent" />
            <span>{{ t('pdf.loading', 'جارٍ تحميل الملف…') }} <span v-if="progress" dir="ltr">{{ progress }}%</span></span>
        </div>
        <div v-else-if="failed" class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-gray-600 dark:text-gray-300">
            {{ t('pdf.failed', 'تعذّر عرض الملف هنا. استخدم زر التنزيل لفتحه.') }}
        </div>

        <div
            v-if="pages"
            dir="ltr"
            class="pointer-events-none absolute bottom-2 start-1/2 -translate-x-1/2 rounded-full bg-black/60 px-3 py-1 text-xs text-white rtl:translate-x-1/2"
        >
            {{ current }} / {{ pages }}
        </div>
    </div>
</template>

<style>
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
