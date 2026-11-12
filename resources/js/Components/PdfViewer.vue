<script setup>
import { useTrans } from '@/Composables/useTrans';
// Every page of a PDF, drawn in the page itself (2026-11-11). Phone browsers show a PDF in
// an <iframe> as its first page only (iPhone) or not at all (Android), so on touch screens
// the preview draws the pages with pdf.js instead. Pages are drawn as they scroll into view
// and let go when far away, so a 500-page book does not fill the phone's memory.
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
            holder.replaceChildren(canvas);
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
    <div class="relative h-[70vh] w-full overflow-hidden rounded bg-gray-200 dark:bg-gray-900">
        <div ref="scroller" class="h-full w-full overflow-y-auto overscroll-contain px-1 py-2" @scroll.passive="onScroll">
            <div
                v-for="n in pages"
                :key="n"
                :ref="(el) => (pageEls[n - 1] = el)"
                :data-page="n"
                class="mx-auto mb-2 w-full bg-white shadow"
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
