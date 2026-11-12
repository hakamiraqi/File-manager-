<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PreviewModal from '@/Components/PreviewModal.vue';
import { useTrans } from '@/Composables/useTrans';
import {
    FilmIcon, FolderIcon, MagnifyingGlassIcon, PlayIcon, ArrowRightIcon, ClockIcon, ArrowTopRightOnSquareIcon,
    Squares2X2Icon, PlayCircleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    folders: { type: Array, default: () => [] },
    videos: { type: Array, default: () => [] },
    limited: Boolean,
});

const { t } = useTrans();
const nf = new Intl.NumberFormat(document.documentElement.lang || 'ar');
const tab = ref('folders');
const q = ref('');
const sort = ref('newest');
const folderId = ref(undefined); // undefined: the folder grid; null: videos outside any folder
const previewFile = ref(null);
const previewList = ref([]);

const byId = computed(() => Object.fromEntries(props.videos.map((v) => [v.id, v])));
const needle = computed(() => q.value.trim().toLowerCase());
const matches = (v) => !needle.value || v.name.toLowerCase().includes(needle.value) || (v.folder_name || '').toLowerCase().includes(needle.value);

const shownFolders = computed(() =>
    props.folders.filter((f) => !needle.value || (f.name || '').toLowerCase().includes(needle.value) || f.path.join(' ').toLowerCase().includes(needle.value)
        || f.video_ids.some((id) => matches(byId.value[id] || { name: '' }))),
);
const currentFolder = computed(() => (folderId.value === undefined ? null : props.folders.find((f) => f.id === folderId.value) || null));
const folderVideos = computed(() => (currentFolder.value ? currentFolder.value.video_ids.map((id) => byId.value[id]).filter(Boolean).filter(matches) : []));
const allVideos = computed(() => {
    const list = props.videos.filter(matches);
    return sort.value === 'newest' ? [...list].sort((a, b) => (b.created_at || '').localeCompare(a.created_at || '')) : list;
});

// Videos started on this browser (the player remembers where each one was left).
const resume = ref({});
onMounted(() => {
    const found = {};
    try {
        for (const v of props.videos) {
            const s = Number(localStorage.getItem(`filenest:video-position:${v.id}`));
            if (s > 0) found[v.id] = s;
        }
    } catch {
        /* storage switched off */
    }
    resume.value = found;
    const m = location.hash.match(/^#folder-(\d+|root)$/);
    if (m) folderId.value = m[1] === 'root' ? null : Number(m[1]);
});
const continueWatching = computed(() => props.videos.filter((v) => resume.value[v.id]).slice(0, 12));

function openFolder(f) {
    folderId.value = f.id;
    history.replaceState(history.state, '', `#folder-${f.id ?? 'root'}`);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function closeFolder() {
    folderId.value = undefined;
    history.replaceState(history.state, '', location.pathname);
}
function play(video, list) {
    previewList.value = list;
    previewFile.value = video;
}
function siblings(video) {
    const f = props.folders.find((x) => x.id === (video.folder_id ?? null));
    return f ? f.video_ids.map((id) => byId.value[id]).filter(Boolean) : [video];
}

const isNew = (v) => v.created_at && Date.now() - new Date(v.created_at) < 7 * 86400000;
const mb = (bytes) => (bytes >= 1073741824 ? `${(bytes / 1073741824).toFixed(1)} GB` : `${Math.round(bytes / 1048576)} MB`);
const ago = (iso) => (iso ? new Date(iso).toLocaleDateString(document.documentElement.lang || 'ar', { year: 'numeric', month: 'short', day: 'numeric' }) : '');
function clock(s) {
    s = Math.floor(s);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = String(s % 60).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
}
const thumb = (id) => route('files.thumbnail', id);
</script>

<template>
    <Head :title="t('nav.lectures', 'المحاضرات المرئية')" />

    <AppLayout>
        <template #header>
            <h2 class="flex items-center gap-2 truncate text-lg font-semibold text-gray-800 dark:text-gray-100">
                <FilmIcon class="h-5 w-5 text-[var(--accent-600)]" />{{ t('nav.lectures', 'المحاضرات المرئية') }}
            </h2>
        </template>

        <div class="py-6 sm:py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Search + tabs -->
                <div class="flex flex-wrap items-center gap-3">
                    <div class="relative min-w-[14rem] flex-1">
                        <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                        <input
                            v-model="q" type="search"
                            :placeholder="t('lectures.search', 'ابحث عن محاضرة أو مادة')"
                            class="w-full rounded-xl border-gray-200 ps-9 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                        />
                    </div>
                    <div v-if="folderId === undefined" class="flex rounded-xl border border-gray-200 bg-white p-1 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <button
                            v-for="x in [
                                { key: 'folders', label: t('lectures.by_folder', 'حسب المجلد'), n: shownFolders.length, icon: Squares2X2Icon },
                                { key: 'all', label: t('lectures.all_videos', 'كل المقاطع'), n: allVideos.length, icon: PlayCircleIcon },
                            ]"
                            :key="x.key" type="button" @click="tab = x.key"
                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-medium transition"
                            :class="tab === x.key ? 'bg-[var(--accent-600)] text-white' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                        >
                            <component :is="x.icon" class="h-4 w-4" />{{ x.label }}
                            <span class="rounded-full px-1.5 text-xs" :class="tab === x.key ? 'bg-white/25' : 'bg-gray-100 dark:bg-gray-700'">{{ nf.format(x.n) }}</span>
                        </button>
                    </div>
                    <select
                        v-if="folderId === undefined && tab === 'all'" v-model="sort"
                        class="rounded-xl border-gray-200 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    >
                        <option value="newest">{{ t('lectures.sort_newest', 'الأحدث أولاً') }}</option>
                        <option value="name">{{ t('lectures.sort_name', 'حسب الاسم') }}</option>
                    </select>
                </div>

                <!-- Continue watching -->
                <section v-if="folderId === undefined && continueWatching.length && !q">
                    <h3 class="mb-3 flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-100">
                        <ClockIcon class="h-5 w-5 text-[var(--accent-600)]" />{{ t('lectures.continue', 'أكمل المشاهدة') }}
                    </h3>
                    <div class="-mx-4 flex gap-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0" style="scrollbar-width: thin">
                        <button
                            v-for="v in continueWatching" :key="v.id" type="button" @click="play(v, siblings(v))"
                            class="group w-60 shrink-0 overflow-hidden rounded-2xl border border-gray-200 bg-white text-start transition hover:shadow-md dark:border-gray-700 dark:bg-gray-800"
                        >
                            <div class="relative aspect-video bg-gray-900">
                                <img v-if="v.thumbnail_path" :src="thumb(v.id)" loading="lazy" alt="" class="h-full w-full object-cover opacity-90 transition group-hover:opacity-100" />
                                <FilmIcon v-else class="absolute inset-0 m-auto h-10 w-10 text-white/30" />
                                <span class="absolute inset-0 flex items-center justify-center opacity-0 transition group-hover:opacity-100">
                                    <span class="rounded-full bg-black/60 p-3"><PlayIcon class="h-6 w-6 text-white" /></span>
                                </span>
                                <span dir="ltr" class="absolute bottom-2 start-2 rounded-md bg-black/75 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-white">⏱ {{ clock(resume[v.id]) }}</span>
                            </div>
                            <p class="truncate px-3 py-2 text-sm font-medium text-gray-800 dark:text-gray-100"><bdi>{{ v.name }}</bdi></p>
                        </button>
                    </div>
                </section>

                <!-- One folder -->
                <section v-if="folderId !== undefined && currentFolder">
                    <div class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl bg-gradient-to-l from-[var(--accent-600)] to-[var(--accent-700)] p-5 text-white shadow-sm">
                        <button type="button" @click="closeFolder" class="rounded-full bg-white/15 p-2 transition hover:bg-white/25" :title="t('action.back', 'رجوع')">
                            <ArrowRightIcon class="h-5 w-5 ltr:rotate-180" />
                        </button>
                        <div class="min-w-0 flex-1">
                            <p v-if="currentFolder.path.length" class="truncate text-xs text-white/75">{{ currentFolder.path.join(' › ') }}</p>
                            <h3 class="truncate text-xl font-bold"><bdi>{{ currentFolder.name || t('lectures.no_folder', 'خارج المجلدات') }}</bdi></h3>
                            <p class="mt-0.5 text-sm text-white/80">
                                {{ t('lectures.n_videos', ':n مقطع', { n: nf.format(currentFolder.count) }) }} · {{ mb(currentFolder.size) }}
                            </p>
                        </div>
                        <button
                            v-if="folderVideos.length" type="button" @click="play(folderVideos[0], folderVideos)"
                            class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2 text-sm font-bold text-[var(--accent-700)] shadow transition hover:bg-white/90"
                        >
                            <PlayIcon class="h-4 w-4" />{{ t('lectures.play_all', 'تشغيل الكل') }}
                        </button>
                        <Link
                            v-if="currentFolder.id" :href="route('dashboard', { folder: currentFolder.id })"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-white/15 px-3 py-2 text-sm transition hover:bg-white/25"
                        >
                            <ArrowTopRightOnSquareIcon class="h-4 w-4" />{{ t('lectures.open_in_files', 'فتح في الملفات') }}
                        </Link>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <button
                            v-for="(v, i) in folderVideos" :key="v.id" type="button" @click="play(v, folderVideos)"
                            class="group overflow-hidden rounded-2xl border border-gray-200 bg-white text-start transition hover:-translate-y-0.5 hover:shadow-lg dark:border-gray-700 dark:bg-gray-800"
                        >
                            <div class="relative aspect-video bg-gradient-to-br from-gray-800 to-gray-900">
                                <img v-if="v.thumbnail_path" :src="thumb(v.id)" loading="lazy" alt="" class="h-full w-full object-cover" />
                                <FilmIcon v-else class="absolute inset-0 m-auto h-10 w-10 text-white/25" />
                                <span class="absolute start-2 top-2 rounded-md bg-black/70 px-2 py-0.5 text-xs font-bold text-white">{{ nf.format(i + 1) }}</span>
                                <span v-if="isNew(v)" class="absolute end-2 top-2 rounded-md bg-emerald-500 px-2 py-0.5 text-[11px] font-bold text-white">{{ t('lectures.new', 'جديد') }}</span>
                                <span class="absolute inset-0 flex items-center justify-center bg-black/0 transition group-hover:bg-black/30">
                                    <span class="scale-75 rounded-full bg-white/90 p-3 opacity-0 shadow-lg transition group-hover:scale-100 group-hover:opacity-100"><PlayIcon class="h-6 w-6 text-[var(--accent-700)]" /></span>
                                </span>
                                <span v-if="resume[v.id]" class="absolute inset-x-0 bottom-0 h-1 bg-[var(--accent-500)]"></span>
                            </div>
                            <div class="p-3">
                                <p class="line-clamp-2 text-sm font-semibold leading-snug text-gray-800 dark:text-gray-100"><bdi>{{ v.name }}</bdi></p>
                                <p class="mt-1 text-xs text-gray-400">{{ v.human_size }} · {{ ago(v.created_at) }}</p>
                            </div>
                        </button>
                    </div>
                </section>

                <!-- Folder grid -->
                <section v-else-if="tab === 'folders'">
                    <div v-if="!shownFolders.length" class="rounded-2xl border border-dashed border-gray-300 px-6 py-16 text-center text-gray-500 dark:border-gray-700">
                        <FilmIcon class="mx-auto h-12 w-12 text-gray-300" />
                        <p class="mt-3">{{ q ? t('saved.no_match', 'لا نتائج مطابقة') : t('lectures.empty', 'لا توجد محاضرات مرئية متاحة لك بعد.') }}</p>
                    </div>
                    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <button
                            v-for="f in shownFolders" :key="f.id ?? 'root'" type="button" @click="openFolder(f)"
                            class="group overflow-hidden rounded-2xl border border-gray-200 bg-white text-start transition hover:-translate-y-0.5 hover:shadow-lg dark:border-gray-700 dark:bg-gray-800"
                        >
                            <div class="relative aspect-video overflow-hidden bg-gradient-to-br from-[var(--accent-600)] to-gray-900">
                                <div v-if="f.thumbs.length >= 4" class="grid h-full grid-cols-2 grid-rows-2 gap-0.5">
                                    <img v-for="id in f.thumbs.slice(0, 4)" :key="id" :src="thumb(id)" loading="lazy" alt="" class="h-full w-full object-cover" />
                                </div>
                                <img v-else-if="f.thumbs.length" :src="thumb(f.thumbs[0])" loading="lazy" alt="" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" />
                                <FolderIcon v-else class="absolute inset-0 m-auto h-14 w-14 text-white/40" />
                                <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent"></div>
                                <span class="absolute bottom-2 end-2 inline-flex items-center gap-1 rounded-lg bg-black/70 px-2 py-1 text-xs font-bold text-white backdrop-blur">
                                    <PlayIcon class="h-3.5 w-3.5" />{{ t('lectures.n_videos', ':n مقطع', { n: nf.format(f.count) }) }}
                                </span>
                            </div>
                            <div class="p-3">
                                <p v-if="f.path.length" class="truncate text-[11px] text-gray-400">{{ f.path.join(' › ') }}</p>
                                <p class="truncate font-semibold text-gray-900 dark:text-gray-100"><bdi>{{ f.name || t('lectures.no_folder', 'خارج المجلدات') }}</bdi></p>
                                <p class="mt-0.5 text-xs text-gray-400">{{ mb(f.size) }} · {{ t('lectures.updated', 'آخر إضافة') }} {{ ago(f.latest) }}</p>
                            </div>
                        </button>
                    </div>
                </section>

                <!-- All videos -->
                <section v-else>
                    <div v-if="!allVideos.length" class="rounded-2xl border border-dashed border-gray-300 px-6 py-16 text-center text-gray-500 dark:border-gray-700">
                        {{ t('saved.no_match', 'لا نتائج مطابقة') }}
                    </div>
                    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <button
                            v-for="v in allVideos" :key="v.id" type="button" @click="play(v, siblings(v))"
                            class="group overflow-hidden rounded-2xl border border-gray-200 bg-white text-start transition hover:-translate-y-0.5 hover:shadow-lg dark:border-gray-700 dark:bg-gray-800"
                        >
                            <div class="relative aspect-video bg-gradient-to-br from-gray-800 to-gray-900">
                                <img v-if="v.thumbnail_path" :src="thumb(v.id)" loading="lazy" alt="" class="h-full w-full object-cover" />
                                <FilmIcon v-else class="absolute inset-0 m-auto h-10 w-10 text-white/25" />
                                <span v-if="isNew(v)" class="absolute end-2 top-2 rounded-md bg-emerald-500 px-2 py-0.5 text-[11px] font-bold text-white">{{ t('lectures.new', 'جديد') }}</span>
                                <span class="absolute inset-0 flex items-center justify-center bg-black/0 transition group-hover:bg-black/30">
                                    <span class="scale-75 rounded-full bg-white/90 p-3 opacity-0 shadow-lg transition group-hover:scale-100 group-hover:opacity-100"><PlayIcon class="h-6 w-6 text-[var(--accent-700)]" /></span>
                                </span>
                                <span v-if="resume[v.id]" class="absolute inset-x-0 bottom-0 h-1 bg-[var(--accent-500)]"></span>
                            </div>
                            <div class="p-3">
                                <p class="line-clamp-2 text-sm font-semibold leading-snug text-gray-800 dark:text-gray-100"><bdi>{{ v.name }}</bdi></p>
                                <p class="mt-1 flex items-center gap-1 truncate text-xs text-gray-400">
                                    <FolderIcon class="h-3.5 w-3.5 shrink-0" /><bdi class="truncate">{{ v.folder_name || t('lectures.no_folder', 'خارج المجلدات') }}</bdi>
                                    <span class="shrink-0">· {{ v.human_size }}</span>
                                </p>
                            </div>
                        </button>
                    </div>
                    <p v-if="limited" class="mt-4 text-center text-xs text-gray-400">{{ t('lectures.limited', 'تُعرض أول 3000 مقطع؛ استخدم البحث للوصول إلى البقية.') }}</p>
                </section>
            </div>
        </div>

        <PreviewModal :show="!!previewFile" :file="previewFile" :files="previewList" @close="previewFile = null" @change="previewFile = $event" />
    </AppLayout>
</template>
