<script setup>
// «استشهاد»: a book or article as a ready reference. APA 7 and Vancouver (medical and dental
// journals) copy with their italics into Word; BibTeX and RIS go to EndNote, Zotero or Mendeley.
import { ref, watch, computed } from 'vue';
import Modal from '@/Components/Modal.vue';
import { useTrans } from '@/Composables/useTrans';
import { ClipboardDocumentIcon, CheckIcon, ArrowDownTrayIcon, ArrowTopRightOnSquareIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ show: Boolean, book: { type: Object, required: true } });
defineEmits(['close']);

const styles = [
    { key: 'apa', label: 'APA 7', hint: () => t('library.cite.apa_hint', 'الأكثر استخداماً في الرسائل والبحوث') },
    { key: 'vancouver', label: 'Vancouver', hint: () => t('library.cite.vancouver_hint', 'أسلوب المجلات الطبية وطب الأسنان') },
    { key: 'bibtex', label: 'BibTeX', hint: () => t('library.cite.bibtex_hint', 'لـ LaTeX و Overleaf') },
    { key: 'ris', label: 'RIS', hint: () => t('library.cite.ris_hint', 'لـ EndNote و Zotero و Mendeley') },
];
const style = ref('apa');
const data = ref(null);
const error = ref('');
const copied = ref(false);

watch(() => props.show, async (show) => {
    if (!show || data.value) return;
    error.value = '';
    try {
        data.value = (await window.axios.get(route('library.books.cite', props.book.id))).data;
    } catch {
        error.value = t('library.cite.failed', 'تعذّر تجهيز الاستشهاد.');
    }
});

const current = computed(() => {
    if (!data.value) return null;
    const v = data.value[style.value];
    return typeof v === 'string' ? { text: v, html: null } : v;
});

async function copy() {
    const c = current.value;
    if (!c) return;
    try {
        // Rich text keeps the italics when pasted into Word or Google Docs.
        if (c.html && window.ClipboardItem && navigator.clipboard?.write) {
            await navigator.clipboard.write([new ClipboardItem({
                'text/html': new Blob([c.html], { type: 'text/html' }),
                'text/plain': new Blob([c.text], { type: 'text/plain' }),
            })]);
        } else {
            await navigator.clipboard.writeText(c.text);
        }
        copied.value = true;
        setTimeout(() => (copied.value = false), 1800);
    } catch {
        error.value = t('library.cite.copy_failed', 'لم يسمح المتصفح بالنسخ؛ حدّد النص وانسخه يدوياً.');
    }
}
const download = (format) => route('library.books.cite', { book: props.book.id, download: format });
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="$emit('close')">
        <div class="p-5 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('library.cite.title', 'استشهاد') }}</h2>
                    <p class="truncate text-sm text-gray-500" dir="auto">{{ book.title }}</p>
                </div>
                <button type="button" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" @click="$emit('close')"><XMarkIcon class="h-5 w-5" /></button>
            </div>

            <div class="mt-4 grid grid-cols-4 gap-1 rounded-xl bg-gray-100 p-1 text-sm dark:bg-gray-900/60" dir="ltr">
                <button
                    v-for="s in styles" :key="s.key" type="button"
                    class="rounded-lg px-2 py-1.5 font-medium transition"
                    :class="style === s.key ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-500 hover:text-gray-800 dark:hover:text-gray-200'"
                    @click="style = s.key; copied = false"
                >{{ s.label }}</button>
            </div>
            <p class="mt-2 text-xs text-gray-500">{{ styles.find((s) => s.key === style).hint() }}</p>

            <div class="mt-3 min-h-[7rem] rounded-xl border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700 dark:bg-gray-900/40" dir="ltr">
                <div v-if="!data && !error" class="space-y-2">
                    <div class="h-3 w-11/12 animate-pulse rounded bg-gray-200 dark:bg-gray-700"></div>
                    <div class="h-3 w-9/12 animate-pulse rounded bg-gray-200 dark:bg-gray-700"></div>
                    <div class="h-3 w-7/12 animate-pulse rounded bg-gray-200 dark:bg-gray-700"></div>
                </div>
                <p v-else-if="current?.html" class="cite-hanging break-words font-serif text-[15px] leading-7 text-gray-900 dark:text-gray-100" dir="auto" v-html="current.html"></p>
                <pre v-else-if="current" class="max-h-64 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-5 text-gray-800 dark:text-gray-200">{{ current.text }}</pre>
            </div>
            <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button
                    type="button" :disabled="!current"
                    class="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-40"
                    :class="copied ? 'bg-emerald-600' : 'bg-[var(--accent-600)] hover:bg-[var(--accent-700)]'"
                    @click="copy"
                >
                    <component :is="copied ? CheckIcon : ClipboardDocumentIcon" class="h-5 w-5" />
                    {{ copied ? t('library.cite.copied', 'تم النسخ') : t('library.cite.copy', 'نسخ') }}
                </button>
                <a :href="download('ris')" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                    <ArrowDownTrayIcon class="h-4 w-4" /> EndNote / Zotero <span class="text-xs text-gray-400" dir="ltr">.ris</span>
                </a>
                <a :href="download('bib')" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                    <ArrowDownTrayIcon class="h-4 w-4" /> BibTeX <span class="text-xs text-gray-400" dir="ltr">.bib</span>
                </a>
                <a v-if="data?.doi_url" :href="data.doi_url" target="_blank" rel="noopener" class="ms-auto inline-flex items-center gap-1 text-sm text-[var(--accent-600)] hover:underline">
                    {{ t('library.cite.open_doi', 'المصدر الأصلي') }} <ArrowTopRightOnSquareIcon class="h-4 w-4" />
                </a>
            </div>
        </div>
    </Modal>
</template>

<style scoped>
/* A reference list's hanging indent. */
.cite-hanging {
    padding-inline-start: 2em;
    text-indent: -2em;
}
.cite-hanging :deep(a) {
    color: var(--accent-600);
    word-break: break-all;
}
</style>
