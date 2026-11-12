<script setup>
// Choose files for a PDF tool from the reader's own files (search by name).
import { onMounted, ref, watch } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { CheckCircleIcon, DocumentIcon, MagnifyingGlassIcon, PhotoIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    kinds: { type: Array, default: () => ['pdf'] },
    multiple: Boolean,
    chosen: { type: Array, default: () => [] },
});
const emit = defineEmits(['pick', 'close']);
const { t } = useTrans();

const q = ref('');
const list = ref([]);
const loading = ref(false);
const picked = ref(new Map(props.chosen.map((f) => [f.id, f])));
let timer = null;

async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('pdf-tools.files'), { params: { q: q.value, kinds: props.kinds.join(',') } });
        list.value = data.files;
    } finally {
        loading.value = false;
    }
}
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
onMounted(load);

function toggle(f) {
    if (!props.multiple) {
        emit('pick', [f]);
        return;
    }
    const m = new Map(picked.value);
    m.has(f.id) ? m.delete(f.id) : m.set(f.id, f);
    picked.value = m;
}
const size = (b) => (b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');
</script>

<template>
    <div class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 sm:items-center sm:p-4" @click.self="emit('close')">
        <div class="flex max-h-[85vh] w-full max-w-lg flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl dark:bg-gray-800">
            <div class="flex items-center gap-2 border-b border-gray-200 p-3 dark:border-gray-700">
                <h3 class="flex-1 font-semibold text-gray-900 dark:text-gray-100">{{ multiple ? t('pdft.pick_many', 'اختر الملفات') : t('pdft.pick_one', 'اختر ملفاً') }}</h3>
                <button type="button" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" @click="emit('close')"><XMarkIcon class="h-5 w-5" /></button>
            </div>
            <div class="p-3">
                <div class="relative">
                    <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="q"
                        type="search"
                        dir="auto"
                        class="w-full rounded-xl border-gray-300 ps-9 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        :placeholder="t('pdft.search_files', 'ابحث باسم الملف…')"
                    />
                </div>
            </div>
            <div class="min-h-[12rem] flex-1 overflow-y-auto px-2 pb-2">
                <p v-if="loading && !list.length" class="p-6 text-center text-sm text-gray-500">{{ t('common.loading', 'جارٍ التحميل…') }}</p>
                <p v-else-if="!list.length" class="p-6 text-center text-sm text-gray-500">{{ t('pdft.no_files', 'لا توجد ملفات مناسبة. ارفعها أولاً من «ملفاتي».') }}</p>
                <button
                    v-for="f in list"
                    :key="f.id"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start hover:bg-gray-50 dark:hover:bg-gray-700/50"
                    :class="{ 'bg-[var(--accent-600)]/10': picked.has(f.id) }"
                    @click="toggle(f)"
                >
                    <img v-if="f.thumbnail" :src="route('files.thumbnail', f.id)" class="h-11 w-9 shrink-0 rounded border border-gray-200 object-cover dark:border-gray-600" loading="lazy" alt="" />
                    <PhotoIcon v-else-if="f.kind === 'image'" class="h-9 w-9 shrink-0 text-emerald-500" />
                    <DocumentIcon v-else class="h-9 w-9 shrink-0" :class="f.kind === 'pdf' ? 'text-red-500' : 'text-blue-500'" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100"><bdi>{{ f.name }}</bdi></span>
                        <span class="block truncate text-xs text-gray-500">{{ size(f.size) }}<template v-if="f.folder_name"> · <bdi>{{ f.folder_name }}</bdi></template></span>
                    </span>
                    <CheckCircleIcon v-if="picked.has(f.id)" class="h-6 w-6 shrink-0 text-[var(--accent-600)]" />
                </button>
            </div>
            <div v-if="multiple" class="flex items-center gap-2 border-t border-gray-200 p-3 dark:border-gray-700">
                <span class="flex-1 text-xs text-gray-500">{{ t('pdft.chosen', 'المختار') }}: {{ picked.size }}</span>
                <button
                    type="button"
                    class="rounded-xl bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white disabled:opacity-40"
                    :disabled="!picked.size"
                    @click="emit('pick', [...picked.values()])"
                >
                    {{ t('pdft.done', 'تم') }}
                </button>
            </div>
        </div>
    </div>
</template>
