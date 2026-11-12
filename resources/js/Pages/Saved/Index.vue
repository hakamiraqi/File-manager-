<script setup>
import { computed, ref, onMounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FileThumbnail from '@/Components/FileThumbnail.vue';
import PreviewModal from '@/Components/PreviewModal.vue';
import SaveButton from '@/Components/SaveButton.vue';
import { useSaved } from '@/Composables/useSaved';
import { useTrans } from '@/Composables/useTrans';
import {
    BookmarkIcon, ArrowDownTrayIcon, FolderIcon, MagnifyingGlassIcon, Squares2X2Icon, ListBulletIcon,
    XMarkIcon, TrashIcon, DocumentIcon, ArrowTopRightOnSquareIcon,
    FilmIcon, PhotoIcon, MusicalNoteIcon, DocumentTextIcon, Squares2X2Icon as AllIcon, CubeIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    saved: { type: Array, default: () => [] },
    downloads: { type: Array, default: () => [] },
});

const { t } = useTrans();
const page = usePage();
const can = computed(() => page.props.permissions ?? {});
const { isSaved } = useSaved();

const tab = ref('saved');
const kind = ref('all');
const q = ref('');
const view = ref('grid');
const previewFile = ref(null);

onMounted(() => {
    if (window.location.hash === '#downloads') tab.value = 'downloads';
    try { view.value = localStorage.getItem('saved-view') || 'grid'; } catch { /* ignore */ }
});
function setTab(key) {
    tab.value = key;
    kind.value = 'all';
    history.replaceState(history.state, '', key === 'downloads' ? '#downloads' : location.pathname);
}
function setView(v) {
    view.value = v;
    try { localStorage.setItem('saved-view', v); } catch { /* ignore */ }
}

// Unsaving on this page hides the tile at once, like Instagram.
const savedRows = computed(() => props.saved.filter((r) => isSaved(r.type, r.item.id)));

// One filter for both tabs: by what the thing is (video, image, audio, document, other, folder).
const DOC_EXT = /\.(pdf|docx?|odt|rtf|txt|md|pptx?|odp|xlsx?|ods|csv|epub)$/i;
function category(r) {
    if (r.type === 'folder') return 'folder';
    const mime = (r.item.mime_type || '').toLowerCase();
    const name = r.item.name || '';
    if (mime.startsWith('video/') || /\.(mp4|mkv|webm|mov|avi|m4v)$/i.test(name)) return 'video';
    if (mime.startsWith('image/') || /\.(jpe?g|png|gif|webp|heic|bmp|svg)$/i.test(name)) return 'image';
    if (mime.startsWith('audio/') || /\.(mp3|m4a|wav|ogg|aac|flac|opus)$/i.test(name)) return 'audio';
    if (mime.startsWith('text/') || /pdf|word|officedocument|opendocument|msexcel|powerpoint|epub|rtf/.test(mime) || DOC_EXT.test(name)) return 'document';
    return 'other';
}

const tabRows = computed(() => (tab.value === 'saved' ? savedRows.value : props.downloads));
const rows = computed(() => {
    const needle = q.value.trim().toLowerCase();
    return tabRows.value.filter((r) => (kind.value === 'all' || category(r) === kind.value) && (!needle || r.item.name?.toLowerCase().includes(needle)));
});
const counts = computed(() => ({
    saved: savedRows.value.length,
    downloads: props.downloads.length,
}));
const kindChips = computed(() => {
    const n = { all: tabRows.value.length, video: 0, image: 0, audio: 0, document: 0, other: 0, folder: 0 };
    for (const r of tabRows.value) n[category(r)]++;
    return [
        { key: 'all', label: t('saved.all', 'الكل'), icon: AllIcon },
        { key: 'video', label: t('saved.kind_video', 'فيديو'), icon: FilmIcon },
        { key: 'image', label: t('saved.kind_image', 'صور'), icon: PhotoIcon },
        { key: 'audio', label: t('saved.kind_audio', 'صوت'), icon: MusicalNoteIcon },
        { key: 'document', label: t('saved.kind_document', 'مستندات'), icon: DocumentTextIcon },
        { key: 'other', label: t('saved.kind_other', 'أخرى'), icon: CubeIcon },
        { key: 'folder', label: t('saved.folders', 'مجلدات'), icon: FolderIcon },
    ]
        .map((c) => ({ ...c, n: n[c.key] }))
        .filter((c) => c.key === 'all' || c.n > 0 || c.key === kind.value);
});
const previewList = computed(() => rows.value.filter((r) => r.type === 'file').map((r) => r.item));

function open(r) {
    if (r.type === 'folder') {
        router.get(route('dashboard'), { folder: r.item.id });
    } else {
        previewFile.value = r.item;
    }
}
function openLocation(r) {
    router.get(route('dashboard'), r.item.folder_id ? { folder: r.item.folder_id } : {});
}
function forget(id = null) {
    if (id === null && !confirm(t('saved.clear_confirm', 'مسح قائمة التنزيلات؟ لن تُحذف الملفات نفسها.'))) return;
    router.delete(route('saved.downloads.forget'), { data: id ? { id } : {}, preserveScroll: true });
}

const fmt = (iso) => (iso ? new Date(iso).toLocaleDateString(page.props.appSettings?.locale === 'en' ? 'en-GB' : 'ar-IQ', { timeZone: 'Asia/Baghdad', year: 'numeric', month: 'short', day: 'numeric' }) : '');
const nf = new Intl.NumberFormat('ar-IQ');
</script>

<template>
    <Head :title="t('nav.saved', 'المحفوظات والتنزيلات')" />

    <AppLayout>
        <template #header>
            <h2 class="truncate text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('nav.saved', 'المحفوظات والتنزيلات') }}</h2>
        </template>

        <div class="py-6 sm:py-8">
            <div class="mx-auto max-w-6xl space-y-5 px-4 sm:px-6 lg:px-8">
                <!-- Tabs, Instagram style -->
                <div class="grid grid-cols-2 border-b border-gray-200 dark:border-gray-700">
                    <button
                        v-for="x in [
                            { key: 'saved', label: t('saved.tab_saved', 'المحفوظات'), icon: BookmarkIcon, n: counts.saved },
                            { key: 'downloads', label: t('saved.tab_downloads', 'التنزيلات'), icon: ArrowDownTrayIcon, n: counts.downloads },
                        ]"
                        :key="x.key" type="button" @click="setTab(x.key)"
                        class="-mb-px flex items-center justify-center gap-2 border-b-2 py-3 text-sm font-semibold transition"
                        :class="tab === x.key ? 'border-[var(--accent-600)] text-[var(--accent-700)] dark:text-gray-100' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    >
                        <component :is="x.icon" class="h-5 w-5" />{{ x.label }}
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ nf.format(x.n) }}</span>
                    </button>
                </div>

                <!-- Tools -->
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative min-w-[12rem] flex-1">
                        <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <input v-model="q" type="search" :placeholder="t('saved.search', 'ابحث بالاسم')" class="w-full rounded-xl border-gray-200 ps-9 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100" />
                    </div>
                    <div class="flex rounded-xl border border-gray-200 bg-white p-1 dark:border-gray-700 dark:bg-gray-800">
                        <button type="button" class="rounded-lg p-1.5" :class="view === 'grid' ? 'bg-gray-100 dark:bg-gray-700' : ''" :title="t('view.grid', 'شبكة')" @click="setView('grid')"><Squares2X2Icon class="h-5 w-5 text-gray-600 dark:text-gray-300" /></button>
                        <button type="button" class="rounded-lg p-1.5" :class="view === 'list' ? 'bg-gray-100 dark:bg-gray-700' : ''" :title="t('view.list', 'قائمة')" @click="setView('list')"><ListBulletIcon class="h-5 w-5 text-gray-600 dark:text-gray-300" /></button>
                    </div>
                    <button v-if="tab === 'downloads' && downloads.length" type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-700" @click="forget()">
                        <TrashIcon class="h-4 w-4" />{{ t('saved.clear', 'مسح القائمة') }}
                    </button>
                </div>

                <!-- Filter by type, on both tabs -->
                <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0" style="scrollbar-width: none">
                    <button
                        v-for="c in kindChips" :key="c.key" type="button" @click="kind = c.key"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-medium transition"
                        :class="kind === c.key
                            ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white shadow-sm'
                            : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                    >
                        <component :is="c.icon" class="h-4 w-4" />
                        {{ c.label }}
                        <span class="rounded-full px-1.5 text-xs" :class="kind === c.key ? 'bg-white/25' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'">{{ nf.format(c.n) }}</span>
                    </button>
                </div>

                <!-- Empty -->
                <div v-if="!rows.length" class="flex flex-col items-center rounded-2xl border border-dashed border-gray-300 px-6 py-16 text-center dark:border-gray-700">
                    <span class="flex h-16 w-16 items-center justify-center rounded-full border-2 border-gray-300 text-gray-400 dark:border-gray-600">
                        <component :is="tab === 'saved' ? BookmarkIcon : ArrowDownTrayIcon" class="h-8 w-8" />
                    </span>
                    <h3 class="mt-4 text-lg font-semibold text-gray-800 dark:text-gray-100">
                        {{ q || kind !== 'all' ? t('saved.no_match', 'لا نتائج مطابقة') : tab === 'saved' ? t('saved.empty_title', 'احفظ ما تحتاج الرجوع إليه') : t('saved.empty_dl_title', 'لا توجد تنزيلات بعد') }}
                    </h3>
                    <p v-if="!q" class="mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">
                        {{ tab === 'saved'
                            ? t('saved.empty_hint', 'اضغط على علامة الحفظ بجانب أي ملف أو مجلد ليظهر هنا، ولن يراه غيرك.')
                            : t('saved.empty_dl_hint', 'الملفات التي تنزّلها تظهر هنا تلقائياً لتعود إليها بسرعة.') }}
                    </p>
                    <Link v-if="!q" :href="route('dashboard')" class="mt-5 rounded-xl bg-[var(--accent-600)] px-4 py-2 text-sm font-semibold text-white hover:bg-[var(--accent-700)]">{{ t('saved.browse', 'تصفّح الملفات') }}</Link>
                </div>

                <!-- Grid -->
                <div v-else-if="view === 'grid'" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    <div
                        v-for="r in rows" :key="r.type + r.item.id"
                        class="group relative cursor-pointer overflow-hidden rounded-2xl border border-gray-200 bg-white transition hover:shadow-md dark:border-gray-700 dark:bg-gray-800"
                        @click="open(r)"
                    >
                        <div class="flex aspect-square items-center justify-center bg-gray-50 dark:bg-gray-900/40">
                            <FolderIcon v-if="r.type === 'folder'" class="h-16 w-16 text-amber-400" />
                            <FileThumbnail v-else :file="r.item" :src="route('files.thumbnail', r.item.id)" icon-class="h-14 w-14" />
                        </div>
                        <div class="p-2.5">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100" :title="r.item.name"><bdi>{{ r.item.name }}</bdi></p>
                            <p class="mt-0.5 truncate text-xs text-gray-400">
                                <template v-if="tab === 'downloads'">{{ fmt(r.downloaded_at) }}<template v-if="r.times > 1"> · {{ t('saved.times', 'مرات') }} {{ nf.format(r.times) }}</template></template>
                                <template v-else>{{ r.type === 'folder' ? t('saved.folder', 'مجلد') : r.item.human_size }} · {{ fmt(r.saved_at) }}</template>
                            </p>
                        </div>
                        <div class="absolute end-1.5 top-1.5 flex gap-1" @click.stop>
                            <a v-if="tab === 'downloads' && can.download" :href="route('files.download', r.item.id)" class="rounded-full bg-white/90 p-1.5 shadow-sm hover:bg-white dark:bg-gray-800/90" :title="t('action.download', 'تنزيل')">
                                <ArrowDownTrayIcon class="h-4 w-4 text-gray-600 dark:text-gray-300" />
                            </a>
                            <button v-if="tab === 'downloads'" type="button" class="rounded-full bg-white/90 p-1.5 shadow-sm hover:bg-white dark:bg-gray-800/90" :title="t('saved.forget', 'إزالة من التنزيلات')" @click="forget(r.item.id)">
                                <XMarkIcon class="h-4 w-4 text-gray-600 dark:text-gray-300" />
                            </button>
                            <span class="rounded-full bg-white/90 shadow-sm dark:bg-gray-800/90"><SaveButton :type="r.type" :id="r.item.id" size="h-4 w-4" class="!p-1.5" /></span>
                        </div>
                    </div>
                </div>

                <!-- List -->
                <div v-else class="divide-y divide-gray-100 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
                    <div v-for="r in rows" :key="r.type + r.item.id" class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700/50" @click="open(r)">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gray-50 dark:bg-gray-900/40">
                            <FolderIcon v-if="r.type === 'folder'" class="h-7 w-7 text-amber-400" />
                            <FileThumbnail v-else :file="r.item" :src="route('files.thumbnail', r.item.id)" icon-class="h-7 w-7" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100"><bdi>{{ r.item.name }}</bdi></p>
                            <p class="truncate text-xs text-gray-400">
                                <template v-if="tab === 'downloads'">{{ t('saved.downloaded_on', 'نُزّل في') }} {{ fmt(r.downloaded_at) }}<template v-if="r.times > 1"> · {{ t('saved.times', 'مرات') }} {{ nf.format(r.times) }}</template></template>
                                <template v-else>{{ r.type === 'folder' ? t('saved.folder', 'مجلد') : r.item.human_size }} · {{ t('saved.saved_on', 'حُفظ في') }} {{ fmt(r.saved_at) }}</template>
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-0.5" @click.stop>
                            <button v-if="r.type === 'file'" type="button" class="rounded-full p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('saved.location', 'فتح موقعه')" @click="openLocation(r)">
                                <ArrowTopRightOnSquareIcon class="h-5 w-5 text-gray-400" />
                            </button>
                            <a v-if="r.type === 'file' && can.download" :href="route('files.download', r.item.id)" class="rounded-full p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('action.download', 'تنزيل')">
                                <ArrowDownTrayIcon class="h-5 w-5 text-gray-400" />
                            </a>
                            <button v-if="tab === 'downloads'" type="button" class="rounded-full p-1.5 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('saved.forget', 'إزالة من التنزيلات')" @click="forget(r.item.id)">
                                <XMarkIcon class="h-5 w-5 text-gray-400" />
                            </button>
                            <SaveButton :type="r.type" :id="r.item.id" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <PreviewModal :show="!!previewFile" :file="previewFile" :files="previewList" @close="previewFile = null" @change="previewFile = $event" />
    </AppLayout>
</template>
