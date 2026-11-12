<script setup>
// «الغلاف» for one file (2026-11-12): pick from a search, upload a picture, or use a page of the file.
import { onMounted, ref } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowUpTrayIcon, DocumentIcon, MagnifyingGlassIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ file: { type: Object, required: true } });
const emit = defineEmits(['close', 'changed']);
const { t } = useTrans();

const q = ref('');
const results = ref([]);
const isbns = ref([]);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const page = ref(1);
const isPdf = (props.file.extension || '').toLowerCase() === 'pdf';

async function search() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(route('files.cover.search', props.file.id), { params: { q: q.value || undefined } });
        q.value = data.query;
        results.value = data.results;
        isbns.value = data.isbns || [];
    } catch (e) {
        error.value = e.response?.data?.message || t('covers.search_failed', 'تعذّر البحث الآن.');
        results.value = [];
    } finally {
        loading.value = false;
    }
}
async function apply(payload) {
    busy.value = true;
    error.value = '';
    try {
        const form = new FormData();
        for (const [k, v] of Object.entries(payload)) if (v !== null && v !== undefined) form.append(k, v);
        await window.axios.post(route('files.cover.store', props.file.id), form);
        emit('changed');
        emit('close');
    } catch (e) {
        error.value = e.response?.data?.message || t('covers.apply_failed', 'تعذّر وضع الغلاف.');
    } finally {
        busy.value = false;
    }
}
async function removeCover() {
    busy.value = true;
    try {
        await window.axios.delete(route('files.cover.destroy', props.file.id));
        emit('changed');
        emit('close');
    } finally {
        busy.value = false;
    }
}
const upload = (e) => e.target.files?.[0] && apply({ image: e.target.files[0] });
onMounted(search);
</script>

<template>
    <div class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 sm:items-center sm:p-4" @click.self="emit('close')">
        <div class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl dark:bg-gray-800">
            <div class="flex items-center gap-2 border-b border-gray-200 p-3 dark:border-gray-700">
                <h3 class="min-w-0 flex-1 truncate font-semibold text-gray-900 dark:text-gray-100">{{ t('covers.pick_title', 'غلاف الكتاب') }} · <bdi class="font-normal">{{ file.name }}</bdi></h3>
                <button type="button" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" @click="emit('close')"><XMarkIcon class="h-5 w-5" /></button>
            </div>
            <form class="flex gap-2 p-3" @submit.prevent="search">
                <div class="relative flex-1">
                    <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <input v-model="q" type="search" dir="auto" class="w-full rounded-xl border-gray-300 ps-9 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" :placeholder="t('covers.query', 'العنوان أو المؤلف أو ISBN')" />
                </div>
                <button type="submit" class="rounded-xl bg-[var(--accent-600)] px-4 text-sm font-semibold text-white">{{ t('pdf.find', 'بحث') }}</button>
            </form>
            <p v-if="isbns.length" class="px-3 text-xs text-gray-500">
                {{ t('covers.isbns', 'أرقام ISBN في الكتاب:') }}
                <button v-for="i in isbns" :key="i" type="button" class="ms-1 rounded bg-gray-100 px-1.5 py-0.5 font-mono hover:bg-gray-200 dark:bg-gray-700" @click="(q = i), search()">{{ i }}</button>
            </p>
            <div class="min-h-[12rem] flex-1 overflow-y-auto p-3">
                <p v-if="loading" class="py-10 text-center text-sm text-gray-500">{{ t('pdft.working', 'جارٍ التنفيذ…') }}</p>
                <p v-else-if="!results.length" class="py-10 text-center text-sm text-gray-500">{{ error || t('covers.no_results', 'لا نتائج. جرّب عنواناً أدق، أو ارفع صورة، أو استعمل صفحة من الملف.') }}</p>
                <div v-else class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                    <button
                        v-for="r in results"
                        :key="r.url"
                        type="button"
                        :disabled="busy"
                        class="group text-start disabled:opacity-50"
                        @click="apply({ url: r.url, source: r.source, isbn: r.isbn })"
                    >
                        <span class="block aspect-[2/3] overflow-hidden rounded-lg bg-gray-100 shadow ring-1 ring-black/5 transition group-hover:ring-2 group-hover:ring-[var(--accent-500)] dark:bg-gray-900">
                            <img :src="r.thumb || r.url" loading="lazy" referrerpolicy="no-referrer" class="h-full w-full object-cover" alt="" />
                        </span>
                        <span class="mt-1 line-clamp-2 block text-xs font-medium text-gray-800 dark:text-gray-100" dir="auto">{{ r.title }}</span>
                        <span class="block truncate text-[11px] text-gray-500" dir="auto">{{ r.author }}<template v-if="r.year"> · {{ r.year }}</template></span>
                    </button>
                </div>
            </div>
            <p v-if="error && results.length" class="px-3 pb-2 text-sm text-red-600">{{ error }}</p>
            <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 p-3 text-sm dark:border-gray-700">
                <label class="inline-flex cursor-pointer items-center gap-1 rounded-xl border border-gray-200 px-3 py-1.5 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                    <ArrowUpTrayIcon class="h-4 w-4" />{{ t('covers.upload', 'رفع صورة') }}
                    <input type="file" accept="image/*" class="hidden" :disabled="busy" @change="upload" />
                </label>
                <span v-if="isPdf" class="inline-flex items-center gap-1 rounded-xl border border-gray-200 px-2 py-1 dark:border-gray-600">
                    <DocumentIcon class="h-4 w-4" />{{ t('covers.page', 'صفحة') }}
                    <input v-model.number="page" type="number" min="1" class="w-16 rounded-lg border-gray-300 py-0.5 text-sm dark:border-gray-600 dark:bg-gray-900" />
                    <button type="button" class="rounded-lg px-2 py-0.5 text-[var(--accent-600)] hover:underline" :disabled="busy" @click="apply({ page })">{{ t('covers.use_page', 'استعمالها') }}</button>
                </span>
                <span class="flex-1" />
                <button v-if="file.cover_source" type="button" class="inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" :disabled="busy" @click="removeCover">
                    <TrashIcon class="h-4 w-4" />{{ t('covers.remove', 'إزالة الغلاف') }}
                </button>
            </div>
        </div>
    </div>
</template>
