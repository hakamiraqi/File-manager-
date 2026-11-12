<script setup>
// Organise a PDF's pages: drag (or arrows on phones) to reorder, turn, delete, or pick pages
// to keep. v-model is the list of [page number, rotation] in the new order.
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { openPdf, pageCanvas } from './pdfjs';
import FilePicker from './FilePicker.vue';
import { ArrowPathIcon, ChevronLeftIcon, ChevronRightIcon, TrashIcon, ArrowUturnLeftIcon, CheckIcon, DocumentPlusIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ src: { type: String, required: true }, modelValue: { type: Array, default: () => [] } });
// sources: the other files whose pages were inserted (their order is the «source» number - 1)
const emit = defineEmits(['update:modelValue', 'pages', 'sources']);
const { t } = useTrans();

const items = ref([]); // { n, rot, key, src } (n 0 = a blank page; src 0 = this file)
const extra = ref([]); // inserted files, in source order (src = index + 1)
const docs = []; // pdf.js documents by source
let keySeq = 100000;
const picking = ref(false);
const removed = ref([]);
const selected = ref(new Set());
const loading = ref(true);
const failed = ref(false);
const thumbEls = ref({});
let task = null;
let doc = null;
let observer = null;
const drawn = new Set();

const rtl = document.documentElement.dir === 'rtl';
const sync = () => emit('update:modelValue', items.value.map((i) => [i.n, i.rot, i.src || 0]));
const tkey = (it) => `${it.src || 0}-${it.n}-${it.key}`;

async function open() {
    try {
        task = openPdf(props.src);
        doc = await task.promise;
        docs[0] = doc;
        items.value = Array.from({ length: doc.numPages }, (_, i) => ({ n: i + 1, rot: 0, key: i + 1, src: 0 }));
        emit('pages', doc.numPages);
        sync();
        loading.value = false;
        await nextTick();
        observer = new IntersectionObserver(
            (entries) => entries.forEach((e) => e.isIntersecting && draw(e.target.dataset.k)),
            { rootMargin: '400px' },
        );
        Object.values(thumbEls.value).forEach((el) => el && observer.observe(el));
    } catch {
        loading.value = false;
        failed.value = true;
    }
}
async function draw(k) {
    if (!k || drawn.has(k)) return;
    const it = items.value.find((i) => tkey(i) === k);
    if (!it || it.n === 0) return;
    const d = docs[it.src || 0];
    if (!d) return;
    drawn.add(k);
    try {
        const c = await pageCanvas(d, it.n, 150);
        thumbEls.value[k]?.replaceChildren(c);
    } catch {
        drawn.delete(k);
    }
}

// Insert a blank page (after the selected page, or at the end).
function insertBlank() {
    const at = lastSelectedIndex();
    items.value.splice(at + 1, 0, { n: 0, rot: 0, key: ++keySeq, src: 0 });
    sync();
}
function lastSelectedIndex() {
    let at = items.value.length - 1;
    items.value.forEach((i, idx) => selected.value.has(i.key) && (at = idx));
    return at;
}
// Insert all pages of another PDF.
async function insertFile(list) {
    picking.value = false;
    const f = list[0];
    if (!f) return;
    try {
        const d = await openPdf(route('files.preview', f.id)).promise;
        extra.value.push(f);
        const src = extra.value.length;
        docs[src] = d;
        const at = lastSelectedIndex();
        const added = Array.from({ length: d.numPages }, (_, i) => ({ n: i + 1, rot: 0, key: ++keySeq, src }));
        items.value.splice(at + 1, 0, ...added);
        emit('sources', [...extra.value]);
        sync();
    } catch {
        /* the file could not be opened */
    }
}
watch(
    () => items.value.length,
    async () => {
        await nextTick();
        Object.values(thumbEls.value).forEach((el) => el && observer?.observe(el));
    },
);
onMounted(open);
onBeforeUnmount(() => {
    observer?.disconnect();
    task?.destroy();
    docs.slice(1).forEach((d) => d?.destroy());
});

function turn(i) {
    items.value[i].rot = (items.value[i].rot + 90) % 360;
    sync();
}
function remove(i) {
    removed.value.push(items.value.splice(i, 1)[0]);
    sync();
}
function restore() {
    const it = removed.value.pop();
    if (!it) return;
    items.value.push(it);
    items.value.sort((a, b) => a.n - b.n);
    sync();
}
function move(i, d) {
    const j = i + d;
    if (j < 0 || j >= items.value.length) return;
    const [x] = items.value.splice(i, 1);
    items.value.splice(j, 0, x);
    sync();
}
// Drag and drop (mouse)
const dragFrom = ref(null);
function drop(i) {
    if (dragFrom.value === null || dragFrom.value === i) return;
    const [x] = items.value.splice(dragFrom.value, 1);
    items.value.splice(i, 0, x);
    dragFrom.value = null;
    sync();
}
function toggleSel(key) {
    const s = new Set(selected.value);
    s.has(key) ? s.delete(key) : s.add(key);
    selected.value = s;
}
function keepSelected() {
    if (!selected.value.size) return;
    removed.value.push(...items.value.filter((i) => !selected.value.has(i.key)));
    items.value = items.value.filter((i) => selected.value.has(i.key));
    selected.value = new Set();
    sync();
}
function removeSelected() {
    removed.value.push(...items.value.filter((i) => selected.value.has(i.key)));
    items.value = items.value.filter((i) => !selected.value.has(i.key));
    selected.value = new Set();
    sync();
}
function turnSelected() {
    items.value.forEach((i) => selected.value.has(i.key) && (i.rot = (i.rot + 90) % 360));
    sync();
}
function reverse() {
    items.value.reverse();
    sync();
}
function reset() {
    items.value = Array.from({ length: docs[0]?.numPages || 0 }, (_, i) => ({ n: i + 1, rot: 0, key: i + 1, src: 0 }));
    extra.value = [];
    emit('sources', []);
    removed.value = [];
    selected.value = new Set();
    sync();
}
</script>

<template>
    <div>
        <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="text-gray-500">{{ t('pdft.org_hint', 'اسحب الصفحات لترتيبها، أو استخدم الأسهم. المس الصفحة لتحديدها.') }}</span>
            <span class="flex-1" />
            <template v-if="selected.size">
                <span class="font-semibold text-[var(--accent-600)]">{{ selected.size }} {{ t('pdft.selected', 'محددة') }}</span>
                <button type="button" class="pdft-chip" @click="keepSelected"><CheckIcon class="h-4 w-4" />{{ t('pdft.keep_selected', 'إبقاء المحدد فقط') }}</button>
                <button type="button" class="pdft-chip" @click="turnSelected"><ArrowPathIcon class="h-4 w-4" />{{ t('pdft.turn', 'تدوير') }}</button>
                <button type="button" class="pdft-chip text-red-600" @click="removeSelected"><TrashIcon class="h-4 w-4" />{{ t('common.delete', 'حذف') }}</button>
            </template>
            <button type="button" class="pdft-chip" @click="insertBlank"><PlusIcon class="h-4 w-4" />{{ t('pdft.blank_page', 'صفحة فارغة') }}</button>
            <button type="button" class="pdft-chip" @click="picking = true"><DocumentPlusIcon class="h-4 w-4" />{{ t('pdft.insert_file', 'إدراج من ملف') }}</button>
            <button type="button" class="pdft-chip" @click="reverse">{{ t('pdft.reverse', 'عكس الترتيب') }}</button>
            <button v-if="removed.length" type="button" class="pdft-chip" @click="restore"><ArrowUturnLeftIcon class="h-4 w-4" />{{ t('pdft.restore', 'استرجاع') }} ({{ removed.length }})</button>
            <button type="button" class="pdft-chip" @click="reset">{{ t('pdft.reset', 'البدء من جديد') }}</button>
        </div>
        <p v-if="loading" class="py-10 text-center text-sm text-gray-500">{{ t('pdf.loading', 'جارٍ تحميل الملف…') }}</p>
        <p v-else-if="failed" class="py-10 text-center text-sm text-red-600">{{ t('pdf.failed', 'تعذّر عرض الملف هنا.') }}</p>
        <div v-else class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
            <div
                v-for="(it, i) in items"
                :key="it.key"
                draggable="true"
                class="group relative rounded-xl border-2 bg-white p-1.5 shadow-sm transition dark:bg-gray-800"
                :class="[selected.has(it.key) ? 'border-[var(--accent-600)]' : 'border-transparent', dragFrom === i ? 'opacity-40' : '']"
                @dragstart="dragFrom = i"
                @dragend="dragFrom = null"
                @dragover.prevent
                @drop.prevent="drop(i)"
                @click="toggleSel(it.key)"
            >
                <div class="flex aspect-[3/4] items-center justify-center overflow-hidden rounded bg-gray-100 dark:bg-gray-900">
                    <div v-if="it.n === 0" class="flex aspect-[3/4] w-4/5 items-center justify-center bg-white text-[10px] text-gray-400 shadow-inner">{{ t('pdft.blank', 'فارغة') }}</div>
                    <div
                        v-else
                        :ref="(el) => (thumbEls[tkey(it)] = el)"
                        :data-k="tkey(it)"
                        class="w-full transition-transform"
                        :style="{ transform: `rotate(${it.rot}deg) scale(${it.rot % 180 ? 0.72 : 1})` }"
                    />
                </div>
                <div class="mt-1 flex items-center justify-between gap-0.5">
                    <button type="button" class="pdft-mini" :aria-label="t('pdft.earlier', 'قبل')" @click.stop="move(i, -1)">
                        <ChevronRightIcon v-if="rtl" class="h-4 w-4" /><ChevronLeftIcon v-else class="h-4 w-4" />
                    </button>
                    <span class="text-[11px] tabular-nums text-gray-500"
                        >{{ i + 1 }}<span v-if="it.src" class="text-sky-500"> ({{ String.fromCharCode(64 + it.src) }}{{ it.n }})</span
                        ><span v-else-if="it.n && it.n !== i + 1" class="text-gray-400"> ({{ it.n }})</span></span
                    >
                    <button type="button" class="pdft-mini" :aria-label="t('pdft.later', 'بعد')" @click.stop="move(i, 1)">
                        <ChevronLeftIcon v-if="rtl" class="h-4 w-4" /><ChevronRightIcon v-else class="h-4 w-4" />
                    </button>
                </div>
                <div class="absolute end-2 top-2 flex flex-col gap-1 opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
                    <button type="button" class="pdft-float" :title="t('pdft.turn', 'تدوير')" @click.stop="turn(i)"><ArrowPathIcon class="h-4 w-4" /></button>
                    <button type="button" class="pdft-float text-red-600" :title="t('common.delete', 'حذف')" @click.stop="remove(i)"><TrashIcon class="h-4 w-4" /></button>
                </div>
            </div>
        </div>
        <FilePicker v-if="picking" :kinds="['pdf']" @pick="insertFile" @close="picking = false" />
    </div>
</template>

<style>
.pdft-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border-radius: 9999px;
    border: 1px solid rgb(209 213 219);
    padding: 0.25rem 0.625rem;
}
.dark .pdft-chip {
    border-color: rgb(75 85 99);
}
.pdft-mini {
    border-radius: 0.375rem;
    padding: 0.125rem;
    color: rgb(107 114 128);
}
.pdft-mini:hover {
    background: rgb(243 244 246);
}
.pdft-float {
    display: inline-flex;
    height: 1.75rem;
    width: 1.75rem;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    background: rgb(255 255 255 / 0.95);
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.2);
}
</style>
