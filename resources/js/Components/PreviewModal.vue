<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import {
    XMarkIcon,
    ArrowDownTrayIcon,
    ForwardIcon,
    BackwardIcon,
    PlayIcon,
    PauseIcon,
    QueueListIcon,
    TrashIcon,
    MusicalNoteIcon,
    DocumentMagnifyingGlassIcon,
    ChevronDownIcon,
    ChevronUpIcon,
} from '@heroicons/vue/24/outline';
import Plyr from 'plyr';
import 'plyr/dist/plyr.css';
// Plyr's own dist/plyr.svg isn't in its package "exports" map so Vite can't
// import it directly; a local copy also avoids depending on Plyr's CDN
// (cdn.plyr.io) at runtime, which may be unreachable behind a firewall.
import plyrIconUrl from '@/vendor/plyr-icons.svg?url';
import WaveSurfer from 'wavesurfer.js';

const props = defineProps({
    show: Boolean,
    file: { type: Object, default: null },
    files: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'change']);

function kind(file) {
    if (!file) return null;
    if (file.mime_type?.startsWith('image/')) return 'image';
    if (file.mime_type?.startsWith('video/')) return 'video';
    if (file.mime_type?.startsWith('audio/')) return 'audio';
    if (file.mime_type === 'application/pdf') return 'pdf';
    if (['text/plain', 'text/csv', 'application/json'].includes(file.mime_type)) return 'text';
    return 'unsupported';
}

// ---- Playlist (queue of sibling files of the same media kind) ----
const showPlaylist = ref(false);
const autoplayNext = ref(true);

const playlist = computed(() => {
    if (!props.file) return [];
    const k = kind(props.file);
    if (k !== 'video' && k !== 'audio') return [];
    return props.files.filter((f) => kind(f) === k);
});
const currentIndex = computed(() => playlist.value.findIndex((f) => f.id === props.file?.id));
const hasNext = computed(() => currentIndex.value >= 0 && currentIndex.value < playlist.value.length - 1);
const hasPrev = computed(() => currentIndex.value > 0);

function playNext() {
    if (hasNext.value) emit('change', playlist.value[currentIndex.value + 1]);
}
function playPrev() {
    if (hasPrev.value) emit('change', playlist.value[currentIndex.value - 1]);
}
function playFromList(item) {
    if (item.id !== props.file?.id) emit('change', item);
}

// ---- Subtitles ----
const subtitles = ref([]);
const subtitleForm = useForm({ subtitle: null, language: 'ar', label: '' });
const subtitleInput = ref(null);

async function loadSubtitles() {
    subtitles.value = [];
    if (!props.file || kind(props.file) !== 'video') return;
    try {
        const { data } = await window.axios.get(route('subtitles.index', props.file.id));
        subtitles.value = data.subtitles;
    } catch {
        subtitles.value = [];
    }
}

function submitSubtitle() {
    if (!props.file) return;
    subtitleForm.post(route('subtitles.store', props.file.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: async () => {
            subtitleForm.reset('subtitle', 'label');
            if (subtitleInput.value) subtitleInput.value.value = '';
            await loadSubtitles();
            mountPlyr();
        },
    });
}

function deleteSubtitle(subtitle) {
    router.delete(route('subtitles.destroy', subtitle.id), {
        preserveScroll: true,
        onSuccess: async () => {
            await loadSubtitles();
            mountPlyr();
        },
    });
}

// ---- Video player (Plyr): speed, volume, fullscreen, PiP, captions, quality ----
const videoEl = ref(null);
let player = null;

function videoSources(file) {
    const qualities = file.video_qualities || [];

    if (qualities.length > 1) {
        return qualities.map((q) => ({ src: q.src, type: file.mime_type, size: q.height }));
    }

    return [{ src: route('files.preview', file.id), type: file.mime_type }];
}

function destroyPlyr() {
    if (player) {
        player.destroy();
        player = null;
    }
}

function mountPlyr() {
    destroyPlyr();
    nextTick(() => {
        if (!videoEl.value || !props.file || kind(props.file) !== 'video') return;

        const settings = ['captions', 'speed'];
        const qualities = props.file.video_qualities || [];
        if (qualities.length > 1) settings.splice(1, 0, 'quality');

        player = new Plyr(videoEl.value, {
            iconUrl: plyrIconUrl,
            settings,
            captions: { active: subtitles.value.length > 0, update: true },
            ...(qualities.length > 1
                ? { quality: { default: qualities[0].height, options: qualities.map((q) => q.height) } }
                : {}),
        });

        player.on('ended', () => {
            if (autoplayNext.value && hasNext.value) playNext();
        });
    });
}

// ---- Audio player (wavesurfer.js): SoundCloud-style waveform seek bar ----
const waveformEl = ref(null);
let wavesurfer = null;
const isPlaying = ref(false);
const volume = ref(1);
const playbackRate = ref(1);
const currentTime = ref(0);
const duration = ref(0);

function formatTime(seconds) {
    if (!Number.isFinite(seconds)) return '0:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}

function destroyWavesurfer() {
    if (wavesurfer) {
        wavesurfer.destroy();
        wavesurfer = null;
    }
    isPlaying.value = false;
    currentTime.value = 0;
    duration.value = 0;
}

function mountWavesurfer(file) {
    destroyWavesurfer();
    nextTick(() => {
        if (!waveformEl.value || !file) return;

        wavesurfer = WaveSurfer.create({
            container: waveformEl.value,
            waveColor: '#cbd5e1',
            progressColor: '#ea580c',
            cursorColor: '#9a3412',
            height: 96,
            barWidth: 2,
            barGap: 1,
            barRadius: 2,
            cursorWidth: 1,
            url: route('files.preview', file.id),
        });

        wavesurfer.setVolume(volume.value);
        wavesurfer.setPlaybackRate(playbackRate.value);

        wavesurfer.on('play', () => (isPlaying.value = true));
        wavesurfer.on('pause', () => (isPlaying.value = false));
        wavesurfer.on('timeupdate', (t) => (currentTime.value = t));
        wavesurfer.on('ready', (d) => (duration.value = d));
        wavesurfer.on('finish', () => {
            isPlaying.value = false;
            if (autoplayNext.value && hasNext.value) playNext();
        });
    });
}

function toggleAudioPlay() {
    wavesurfer?.playPause();
}

watch(volume, (v) => wavesurfer?.setVolume(v));
watch(playbackRate, (r) => wavesurfer?.setPlaybackRate(r));

async function loadForFile(file) {
    destroyPlyr();
    destroyWavesurfer();
    showExtractedText.value = false;
    extractedText.value = null;

    if (!file) return;

    const k = kind(file);
    if (k === 'video') {
        await loadSubtitles();
        mountPlyr();
    } else if (k === 'audio') {
        mountWavesurfer(file);
    }
}

watch(
    () => props.file,
    (file) => loadForFile(file),
    { immediate: true },
);

// ---- Extracted document text (DocumentTextExtractor's content_text) ----
const showExtractedText = ref(false);
const extractedText = ref(null);
const extractedTextLoading = ref(false);

async function toggleExtractedText() {
    showExtractedText.value = !showExtractedText.value;
    if (showExtractedText.value && extractedText.value === null && props.file) {
        extractedTextLoading.value = true;
        try {
            const { data } = await window.axios.get(route('files.content', props.file.id));
            extractedText.value = data.content ?? '';
        } catch {
            extractedText.value = '';
        } finally {
            extractedTextLoading.value = false;
        }
    }
}

function handleClose() {
    destroyPlyr();
    destroyWavesurfer();
    emit('close');
}

onBeforeUnmount(() => {
    destroyPlyr();
    destroyWavesurfer();
});
</script>

<template>
    <Modal :show="show" @close="handleClose" max-width="4xl">
        <div v-if="file" class="flex max-h-[85vh] flex-col">
            <div class="flex items-center justify-between border-b border-gray-200 p-4">
                <div class="min-w-0">
                    <h3 class="truncate font-medium text-gray-900">{{ file.name }}</h3>
                    <p v-if="playlist.length > 1" class="text-xs text-gray-500">
                        {{ currentIndex + 1 }} من {{ playlist.length }} في قائمة التشغيل
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="playlist.length > 1"
                        @click="showPlaylist = !showPlaylist"
                        class="rounded-md p-2 hover:bg-gray-100"
                        :class="{ 'bg-gray-100': showPlaylist }"
                        title="قائمة التشغيل"
                    >
                        <QueueListIcon class="h-5 w-5" />
                    </button>
                    <a :href="route('files.download', file.id)" class="rounded-md p-2 hover:bg-gray-100" title="تنزيل">
                        <ArrowDownTrayIcon class="h-5 w-5" />
                    </a>
                    <button @click="handleClose" class="rounded-md p-2 hover:bg-gray-100">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>
            </div>

            <div class="flex flex-1 overflow-hidden">
                <div class="flex flex-1 flex-col overflow-auto bg-gray-50">
                    <div class="flex flex-1 items-center justify-center p-4">
                        <img v-if="kind(file) === 'image'" :src="route('files.preview', file.id)" class="max-h-[65vh] rounded" />

                        <video
                            v-else-if="kind(file) === 'video'"
                            ref="videoEl"
                            :poster="file.thumbnail_path ? route('files.thumbnail', file.id) : undefined"
                            playsinline
                            controls
                            class="max-h-[65vh] w-full rounded"
                        >
                            <source v-for="src in videoSources(file)" :key="src.src" :src="src.src" :type="src.type" :size="src.size" />
                            <track
                                v-for="(subtitle, i) in subtitles"
                                :key="subtitle.id"
                                kind="subtitles"
                                :label="subtitle.label"
                                :srclang="subtitle.language"
                                :src="route('subtitles.show', subtitle.id)"
                                :default="i === 0"
                            />
                        </video>

                        <div v-else-if="kind(file) === 'audio'" class="w-full max-w-2xl px-2">
                            <div class="mb-4 flex items-center justify-center gap-2 text-gray-400">
                                <MusicalNoteIcon class="h-10 w-10" />
                            </div>
                            <div ref="waveformEl" class="w-full cursor-pointer"></div>
                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <button
                                    @click="toggleAudioPlay"
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-orange-600 text-white hover:bg-orange-700"
                                >
                                    <PauseIcon v-if="isPlaying" class="h-5 w-5" />
                                    <PlayIcon v-else class="h-5 w-5" />
                                </button>
                                <span class="text-xs tabular-nums text-gray-500">
                                    {{ formatTime(currentTime) }} / {{ formatTime(duration) }}
                                </span>
                                <label class="flex items-center gap-1 text-xs text-gray-500">
                                    السرعة
                                    <select v-model.number="playbackRate" class="rounded border-gray-300 text-xs">
                                        <option :value="0.5">0.5x</option>
                                        <option :value="1">1x</option>
                                        <option :value="1.5">1.5x</option>
                                        <option :value="2">2x</option>
                                    </select>
                                </label>
                                <label class="flex items-center gap-1 text-xs text-gray-500">
                                    الصوت
                                    <input type="range" min="0" max="1" step="0.05" v-model.number="volume" class="w-24" />
                                </label>
                            </div>
                        </div>

                        <iframe v-else-if="kind(file) === 'pdf'" :src="route('files.preview', file.id)" class="h-[65vh] w-full rounded bg-white" />
                        <iframe v-else-if="kind(file) === 'text'" :src="route('files.preview', file.id)" class="h-[65vh] w-full rounded bg-white" />
                        <p v-else-if="!file.is_indexed" class="text-sm text-gray-500">لا تتوفر معاينة لهذا النوع من الملفات. الرجاء تنزيله للاطلاع عليه.</p>
                    </div>

                    <div v-if="file.is_indexed" class="border-t border-gray-200 bg-white p-3">
                        <button
                            @click="toggleExtractedText"
                            class="flex w-full items-center justify-between text-xs font-medium text-gray-600 hover:text-gray-900"
                        >
                            <span class="flex items-center gap-1.5">
                                <DocumentMagnifyingGlassIcon class="h-4 w-4" /> النص المستخرج من المستند
                            </span>
                            <ChevronUpIcon v-if="showExtractedText" class="h-4 w-4" />
                            <ChevronDownIcon v-else class="h-4 w-4" />
                        </button>
                        <div v-if="showExtractedText" class="mt-2">
                            <p v-if="extractedTextLoading" class="text-xs text-gray-400">جارِ التحميل...</p>
                            <p v-else-if="!extractedText" class="text-xs text-gray-400">لا يوجد نص مستخرج لهذا الملف.</p>
                            <pre v-else class="max-h-64 overflow-y-auto whitespace-pre-wrap break-words rounded-md bg-gray-50 p-3 text-xs leading-relaxed text-gray-700">{{ extractedText }}</pre>
                        </div>
                    </div>

                    <div v-if="playlist.length > 1 && (kind(file) === 'video' || kind(file) === 'audio')" class="flex items-center justify-center gap-4 border-t border-gray-200 bg-white p-2">
                        <button @click="playPrev" :disabled="!hasPrev" class="rounded-md p-2 hover:bg-gray-100 disabled:opacity-30" title="السابق">
                            <BackwardIcon class="h-5 w-5" />
                        </button>
                        <label class="flex items-center gap-1 text-xs text-gray-500">
                            <input type="checkbox" v-model="autoplayNext" class="rounded border-gray-300" />
                            تشغيل تلقائي للتالي
                        </label>
                        <button @click="playNext" :disabled="!hasNext" class="rounded-md p-2 hover:bg-gray-100 disabled:opacity-30" title="التالي">
                            <ForwardIcon class="h-5 w-5" />
                        </button>
                    </div>

                    <div v-if="kind(file) === 'video'" class="border-t border-gray-200 bg-white p-3">
                        <p class="mb-2 text-xs font-medium text-gray-600">الترجمة</p>
                        <div v-if="subtitles.length" class="mb-2 flex flex-wrap gap-2">
                            <span
                                v-for="subtitle in subtitles"
                                :key="subtitle.id"
                                class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-700"
                            >
                                {{ subtitle.label }} ({{ subtitle.language }})
                                <button @click="deleteSubtitle(subtitle)" class="text-gray-400 hover:text-red-600">
                                    <TrashIcon class="h-3.5 w-3.5" />
                                </button>
                            </span>
                        </div>
                        <form @submit.prevent="submitSubtitle" class="flex flex-wrap items-center gap-2">
                            <input
                                ref="subtitleInput"
                                type="file"
                                accept=".srt,.vtt"
                                @input="subtitleForm.subtitle = $event.target.files[0]"
                                class="text-xs"
                            />
                            <input
                                v-model="subtitleForm.language"
                                placeholder="رمز اللغة (ar, en...)"
                                class="w-28 rounded-md border-gray-300 text-xs"
                                maxlength="10"
                            />
                            <input
                                v-model="subtitleForm.label"
                                placeholder="اسم الترجمة"
                                class="w-32 rounded-md border-gray-300 text-xs"
                                maxlength="100"
                            />
                            <button
                                type="submit"
                                :disabled="subtitleForm.processing || !subtitleForm.subtitle"
                                class="rounded-md bg-gray-800 px-3 py-1.5 text-xs text-white hover:bg-gray-900 disabled:opacity-50"
                            >
                                إضافة
                            </button>
                        </form>
                        <p v-if="subtitleForm.errors.subtitle" class="mt-1 text-xs text-red-600">{{ subtitleForm.errors.subtitle }}</p>
                    </div>
                </div>

                <div v-if="showPlaylist && playlist.length > 1" class="w-64 shrink-0 overflow-y-auto border-r border-gray-200 bg-white">
                    <ul>
                        <li v-for="item in playlist" :key="item.id">
                            <button
                                @click="playFromList(item)"
                                class="block w-full truncate px-3 py-2 text-right text-sm hover:bg-gray-50"
                                :class="item.id === file.id ? 'bg-orange-50 font-medium text-orange-700' : 'text-gray-700'"
                            >
                                {{ item.name }}
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </Modal>
</template>
