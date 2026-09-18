<script setup>
import { ref, reactive, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
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
    QuestionMarkCircleIcon,
    ChatBubbleLeftIcon,
    PlusIcon,
} from '@heroicons/vue/24/outline';
import Plyr from 'plyr';
import 'plyr/dist/plyr.css';
// Plyr's own dist/plyr.svg isn't in its package "exports" map so Vite can't
// import it directly; a local copy also avoids depending on Plyr's CDN
// (cdn.plyr.io) at runtime, which may be unreachable behind a firewall.
import plyrIconUrl from '@/vendor/plyr-icons.svg?url';
import WaveSurfer from 'wavesurfer.js';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
const props = defineProps({
    show: Boolean,
    file: { type: Object, default: null },
    files: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'change']);

const page = usePage();
const can = computed(() => page.props.permissions ?? {});
const currentUserId = computed(() => page.props.auth?.user?.id);
const interactiveVideoEnabled = computed(() => page.props.appSettings?.interactiveVideoEnabled ?? false);

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

// ---- Interactive video: questions that pause playback at a timestamp ----
const questions = ref([]);
const score = ref({ answered: 0, correct: 0, total: 0 });
const activeQuestion = ref(null); // the question currently blocking playback, or null
const selectedChoiceId = ref(null);
const answerFeedback = ref(null); // { is_correct, correct_choice_id } once submitted
const answering = ref(false);
const showQuestionManager = ref(false);
const questionForm = reactive({ editingId: null, timestamp_seconds: 0, question: '', choices: ['', ''], correct_index: 0 });
const questionFormBusy = ref(false);
const questionFormError = ref(null);
const importFileInput = ref(null);
const importBusy = ref(false);
const importMessage = ref(null);
const importError = ref(null);
const showReport = ref(false);
const reportData = ref(null);
const reportLoading = ref(false);
const reportError = ref(null);

async function loadQuestions() {
    questions.value = [];
    score.value = { answered: 0, correct: 0, total: 0 };
    if (!props.file || kind(props.file) !== 'video' || !interactiveVideoEnabled.value) return;
    try {
        const { data } = await window.axios.get(route('questions.index', props.file.id));
        questions.value = data.questions;
        score.value = data.score;
    } catch {
        questions.value = [];
    }
}

/**
 * Checked on both `timeupdate` (natural playback reaching a question) and
 * `seeked` (the user dragging the seek bar past one) — a single gate keeps
 * both paths consistent: any unanswered question at or before the current
 * time pauses playback and snaps the position back to it, so a question
 * can never be skipped by seeking forward past it.
 */
function enforceQuestionGate() {
    if (!interactiveVideoEnabled.value || !player || activeQuestion.value) return;

    const current = player.currentTime;
    const blocking = questions.value.find((q) => q.answered_choice_id == null && current >= q.timestamp_seconds - 0.4);
    if (!blocking) return;

    player.pause();
    if (Math.abs(current - blocking.timestamp_seconds) > 1) {
        player.currentTime = blocking.timestamp_seconds;
    }
    activeQuestion.value = blocking;
    selectedChoiceId.value = null;
    answerFeedback.value = null;
}

async function submitAnswer() {
    if (!activeQuestion.value || selectedChoiceId.value == null) return;
    answering.value = true;
    try {
        const { data } = await window.axios.post(route('questions.answer', activeQuestion.value.id), {
            choice_id: selectedChoiceId.value,
        });
        answerFeedback.value = { is_correct: data.is_correct, correct_choice_id: data.correct_choice_id };
        score.value = data.score;
        const q = questions.value.find((x) => x.id === activeQuestion.value.id);
        if (q) {
            q.answered_choice_id = selectedChoiceId.value;
            q.answered_correctly = data.is_correct;
        }
    } catch {
        // Already answered (e.g. a second tab) or a validation error — resume playback either way.
        continueAfterQuestion();
    } finally {
        answering.value = false;
    }
}

function continueAfterQuestion() {
    activeQuestion.value = null;
    selectedChoiceId.value = null;
    answerFeedback.value = null;
    player?.play();
}

function choiceClasses(choice) {
    if (!answerFeedback.value) {
        return selectedChoiceId.value === choice.id
            ? 'border-[var(--accent-500)] bg-[var(--accent-50)]'
            : 'border-gray-200';
    }
    if (choice.id === answerFeedback.value.correct_choice_id) return 'border-green-500 bg-green-50';
    if (choice.id === selectedChoiceId.value) return 'border-red-500 bg-red-50';
    return 'border-gray-200 opacity-60';
}

function resetQuestionForm() {
    questionForm.editingId = null;
    questionForm.timestamp_seconds = Math.floor(player?.currentTime ?? 0);
    questionForm.question = '';
    questionForm.choices = ['', ''];
    questionForm.correct_index = 0;
    questionFormError.value = null;
}

function editQuestion(question) {
    questionForm.editingId = question.id;
    questionForm.timestamp_seconds = question.timestamp_seconds;
    questionForm.question = question.question;
    questionForm.choices = question.choices.map((c) => c.label);
    questionForm.correct_index = question.choices.findIndex((c) => c.is_correct);
    if (questionForm.correct_index < 0) questionForm.correct_index = 0;
    questionFormError.value = null;
}

function addChoiceField() {
    if (questionForm.choices.length < 6) questionForm.choices.push('');
}

function removeChoiceField(index) {
    if (questionForm.choices.length <= 2) return;
    questionForm.choices.splice(index, 1);
    if (questionForm.correct_index >= questionForm.choices.length) {
        questionForm.correct_index = questionForm.choices.length - 1;
    }
}

async function submitQuestionForm() {
    if (!props.file) return;
    questionFormBusy.value = true;
    questionFormError.value = null;

    const payload = {
        timestamp_seconds: questionForm.timestamp_seconds,
        question: questionForm.question,
        choices: questionForm.choices,
        correct_index: questionForm.correct_index,
    };

    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: async () => {
            resetQuestionForm();
            await loadQuestions();
        },
        onError: (errors) => {
            questionFormError.value = Object.values(errors)[0] ?? t('preview.question_save_error', 'تعذّر حفظ السؤال.');
        },
        onFinish: () => {
            questionFormBusy.value = false;
        },
    };

    if (questionForm.editingId) {
        router.put(route('questions.update', questionForm.editingId), payload, options);
    } else {
        router.post(route('questions.store', props.file.id), payload, options);
    }
}

function deleteQuestion(question) {
    router.delete(route('questions.destroy', question.id), {
        preserveScroll: true,
        onSuccess: loadQuestions,
    });
}

function triggerImportPicker() {
    importMessage.value = null;
    importError.value = null;
    importFileInput.value?.click();
}

async function toggleReport() {
    showReport.value = !showReport.value;
    if (!showReport.value || !props.file) return;

    reportLoading.value = true;
    reportError.value = null;
    try {
        const { data } = await window.axios.get(route('questions.report', props.file.id));
        reportData.value = data;
    } catch {
        reportError.value = t('preview.report_error', 'تعذّر تحميل التقرير.');
    } finally {
        reportLoading.value = false;
    }
}

function percent(value) {
    return value == null ? '—' : `${Math.round(value * 100)}%`;
}

async function handleImportFile(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || !props.file) return;

    importBusy.value = true;
    importMessage.value = null;
    importError.value = null;

    const formData = new FormData();
    formData.append('file', file);

    router.post(route('questions.import', props.file.id), formData, {
        preserveScroll: true,
        preserveState: true,
        forceFormData: true,
        onSuccess: async (page) => {
            importMessage.value = page.props.flash?.success ?? t('preview.import_success', 'تم الاستيراد.');
            await loadQuestions();
        },
        onError: (errors) => {
            importError.value = Object.values(errors)[0] ?? t('preview.import_error', 'تعذّر استيراد الملف.');
        },
        onFinish: () => {
            importBusy.value = false;
        },
    });
}

function formatTimestamp(seconds) {
    if (seconds == null) return '';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}

// ---- Comments ----
const comments = ref([]);
const commentForm = useForm({ body: '', timestamp_seconds: null });
const anchorCommentToTime = ref(false);

async function loadComments() {
    comments.value = [];
    if (!props.file || kind(props.file) !== 'video') return;
    try {
        const { data } = await window.axios.get(route('comments.index', props.file.id));
        comments.value = data.comments;
    } catch {
        comments.value = [];
    }
}

function submitComment() {
    if (!props.file || !commentForm.body.trim()) return;
    commentForm
        .transform((data) => ({
            body: data.body,
            timestamp_seconds: anchorCommentToTime.value ? Math.floor(player?.currentTime ?? 0) : null,
        }))
        .post(route('comments.store', props.file.id), {
            preserveScroll: true,
            onSuccess: async () => {
                commentForm.reset('body');
                await loadComments();
            },
        });
}

function deleteComment(comment) {
    router.delete(route('comments.destroy', comment.id), {
        preserveScroll: true,
        onSuccess: loadComments,
    });
}

function seekTo(seconds) {
    if (seconds == null || !player) return;
    player.currentTime = seconds;
    player.play();
}

// ---- Video player (Plyr): speed, volume, fullscreen, PiP, captions, quality ----
const videoEl = ref(null);
let player = null;
const videoLoadError = ref(false);

/**
 * Transcoded renditions (VideoTranscoder) are always re-encoded to H.264/AAC
 * in an mp4 container, whatever the original container was — so they must be
 * declared as "video/mp4", not the original file's mime type. Tagging them
 * with e.g. "video/quicktime" (a common phone-upload mime type that browsers
 * other than Safari can't decode) makes the browser skip the <source>
 * outright before ever requesting it, even though the transcoded bytes are
 * perfectly playable — the video area then just stays black while Plyr's
 * controls render fine, since they don't depend on the source loading.
 * The original file is always appended last as a final fallback source.
 */
function videoSources(file) {
    const qualities = file.video_qualities || [];
    const sources = qualities.map((q) => ({ src: q.src, type: 'video/mp4', size: q.height }));
    sources.push({ src: route('files.preview', file.id), type: file.mime_type });
    return sources;
}

function destroyPlyr() {
    if (player) {
        player.destroy();
        player = null;
    }
}

function mountPlyr() {
    destroyPlyr();
    videoLoadError.value = false;
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

        // A genuinely unplayable source (e.g. a codec no installed browser
        // can decode) fires this instead of throwing — without it the video
        // area just stays black with no indication anything went wrong.
        player.on('error', () => {
            videoLoadError.value = true;
        });

        player.on('timeupdate', enforceQuestionGate);
        player.on('seeked', enforceQuestionGate);
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

// ---- Extracted document text (DocumentTextExtractor's content_text) ----
const showExtractedText = ref(false);
const extractedText = ref(null);

async function loadForFile(file) {
    destroyPlyr();
    destroyWavesurfer();
    showExtractedText.value = false;
    extractedText.value = null;
    activeQuestion.value = null;
    answerFeedback.value = null;
    showQuestionManager.value = false;
    questions.value = [];
    comments.value = [];

    if (!file) return;

    const k = kind(file);
    if (k === 'video') {
        await Promise.all([loadSubtitles(), loadQuestions(), loadComments()]);
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
        <div v-if="file" class="flex max-h-[85vh] flex-col dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-700">
                <div class="min-w-0">
                    <h3 class="truncate font-medium text-gray-900 dark:text-gray-100">{{ file.name }}</h3>
                    <p v-if="playlist.length > 1" class="text-xs text-gray-500 dark:text-gray-400">
                        {{ t('preview.playlist_position', ':index من :total في قائمة التشغيل', { index: currentIndex + 1, total: playlist.length }) }}
                    </p>
                    <p v-if="score.answered > 0" class="text-xs font-medium text-[var(--accent-600)]">
                        {{ t('preview.your_score', 'درجتك: :correct من :total', { correct: score.correct, total: score.total }) }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="playlist.length > 1"
                        @click="showPlaylist = !showPlaylist"
                        class="rounded-md p-2 hover:bg-gray-100 dark:hover:bg-gray-700"
                        :class="{ 'bg-gray-100 dark:bg-gray-700': showPlaylist }"
                        :title="t('preview.playlist_title', 'قائمة التشغيل')"
                    >
                        <QueueListIcon class="h-5 w-5 dark:text-gray-300" />
                    </button>
                    <a :href="route('files.download', file.id)" class="rounded-md p-2 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('action.download', 'تنزيل')">
                        <ArrowDownTrayIcon class="h-5 w-5 dark:text-gray-300" />
                    </a>
                    <button @click="handleClose" class="rounded-md p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                        <XMarkIcon class="h-5 w-5 dark:text-gray-300" />
                    </button>
                </div>
            </div>

            <div class="flex flex-1 overflow-hidden">
                <div class="flex flex-1 flex-col overflow-auto bg-gray-50 dark:bg-gray-900">
                    <div class="flex flex-1 items-center justify-center p-4">
                        <img v-if="kind(file) === 'image'" :src="route('files.preview', file.id)" class="max-h-[65vh] rounded" />

                        <div v-else-if="kind(file) === 'video'" class="relative w-full max-h-[65vh]">
                            <video
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

                            <!-- Shown when no source the browser has could be decoded, instead of leaving a silent black rectangle -->
                            <div v-if="videoLoadError" class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-black/85 p-4 text-center">
                                <p class="text-sm text-gray-200">{{ t('preview.video_unplayable', 'تعذّر تشغيل هذا الفيديو في متصفحك، ربما بسبب صيغة غير مدعومة.') }}</p>
                                <a :href="route('files.download', file.id)" class="rounded-md bg-[var(--accent-600)] px-4 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)]">
                                    {{ t('action.download', 'تنزيل') }}
                                </a>
                            </div>

                            <!-- Interactive question overlay: blocks the video until answered -->
                            <div v-else-if="activeQuestion" class="absolute inset-0 z-10 flex items-center justify-center bg-black/70 p-4">
                                <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-gray-800">
                                    <p class="mb-3 flex items-center gap-1.5 text-xs font-medium text-[var(--accent-600)]">
                                        <QuestionMarkCircleIcon class="h-4 w-4" /> {{ t('preview.interactive_question_label', 'سؤال تفاعلي') }}
                                    </p>
                                    <p class="mb-4 font-semibold text-gray-900 dark:text-gray-100">{{ activeQuestion.question }}</p>
                                    <div class="space-y-2">
                                        <label
                                            v-for="choice in activeQuestion.choices"
                                            :key="choice.id"
                                            class="flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm text-gray-700 dark:text-gray-300"
                                            :class="choiceClasses(choice)"
                                        >
                                            <input
                                                type="radio"
                                                :value="choice.id"
                                                v-model="selectedChoiceId"
                                                :disabled="!!answerFeedback"
                                                class="border-gray-300 text-[var(--accent-600)] focus:ring-[var(--accent-500)]"
                                            />
                                            {{ choice.label }}
                                        </label>
                                    </div>
                                    <div class="mt-4 flex items-center justify-between gap-3">
                                        <p
                                            v-if="answerFeedback"
                                            class="text-sm font-medium"
                                            :class="answerFeedback.is_correct ? 'text-green-600' : 'text-red-600'"
                                        >
                                            {{ answerFeedback.is_correct ? t('preview.correct_answer', 'إجابة صحيحة!') : t('preview.wrong_answer', 'إجابة غير صحيحة.') }}
                                        </p>
                                        <span v-else></span>
                                        <button
                                            v-if="!answerFeedback"
                                            @click="submitAnswer"
                                            :disabled="selectedChoiceId == null || answering"
                                            class="shrink-0 rounded-md bg-[var(--accent-600)] px-4 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50"
                                        >
                                            {{ t('preview.submit_answer', 'إرسال الإجابة') }}
                                        </button>
                                        <button
                                            v-else
                                            @click="continueAfterQuestion"
                                            class="shrink-0 rounded-md bg-[var(--accent-600)] px-4 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)]"
                                        >
                                            {{ t('preview.continue_watching', 'متابعة المشاهدة') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

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
                                <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ formatTime(currentTime) }} / {{ formatTime(duration) }}
                                </span>
                                <label class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ t('preview.speed', 'السرعة') }}
                                    <select v-model.number="playbackRate" class="rounded border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                        <option :value="0.5">0.5x</option>
                                        <option :value="1">1x</option>
                                        <option :value="1.5">1.5x</option>
                                        <option :value="2">2x</option>
                                    </select>
                                </label>
                                <label class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ t('preview.volume', 'الصوت') }}
                                    <input type="range" min="0" max="1" step="0.05" v-model.number="volume" class="w-24" />
                                </label>
                            </div>
                        </div>

                        <iframe v-else-if="kind(file) === 'pdf'" :src="route('files.preview', file.id)" class="h-[65vh] w-full rounded bg-white" />
                        <iframe v-else-if="kind(file) === 'text'" :src="route('files.preview', file.id)" class="h-[65vh] w-full rounded bg-white" />
                        <p v-else-if="!file.is_indexed" class="text-sm text-gray-500 dark:text-gray-400">{{ t('preview.no_preview', 'لا تتوفر معاينة لهذا النوع من الملفات. الرجاء تنزيله للاطلاع عليه.') }}</p>
                    </div>

                    <div v-if="file.is_indexed" class="border-t border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                        <button
                            @click="toggleExtractedText"
                            class="flex w-full items-center justify-between text-xs font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            <span class="flex items-center gap-1.5">
                                <DocumentMagnifyingGlassIcon class="h-4 w-4" /> {{ t('preview.extracted_text', 'النص المستخرج من المستند') }}
                            </span>
                            <ChevronUpIcon v-if="showExtractedText" class="h-4 w-4" />
                            <ChevronDownIcon v-else class="h-4 w-4" />
                        </button>
                        <div v-if="showExtractedText" class="mt-2">
                            <p v-if="extractedTextLoading" class="text-xs text-gray-400">{{ t('common.loading', 'جارِ التحميل...') }}</p>
                            <p v-else-if="!extractedText" class="text-xs text-gray-400">{{ t('preview.no_extracted_text', 'لا يوجد نص مستخرج لهذا الملف.') }}</p>
                            <pre v-else class="max-h-64 overflow-y-auto whitespace-pre-wrap break-words rounded-md bg-gray-50 p-3 text-xs leading-relaxed text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ extractedText }}</pre>
                        </div>
                    </div>

                    <div v-if="playlist.length > 1 && (kind(file) === 'video' || kind(file) === 'audio')" class="flex items-center justify-center gap-4 border-t border-gray-200 bg-white p-2 dark:border-gray-700 dark:bg-gray-800">
                        <button @click="playPrev" :disabled="!hasPrev" class="rounded-md p-2 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700 dark:text-gray-300" :title="t('preview.prev', 'السابق')">
                            <BackwardIcon class="h-5 w-5" />
                        </button>
                        <label class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                            <input type="checkbox" v-model="autoplayNext" class="rounded border-gray-300" />
                            {{ t('preview.autoplay_next', 'تشغيل تلقائي للتالي') }}
                        </label>
                        <button @click="playNext" :disabled="!hasNext" class="rounded-md p-2 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700 dark:text-gray-300" :title="t('preview.next', 'التالي')">
                            <ForwardIcon class="h-5 w-5" />
                        </button>
                    </div>

                    <div v-if="kind(file) === 'video'" class="border-t border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                        <p class="mb-2 text-xs font-medium text-gray-600 dark:text-gray-400">{{ t('preview.subtitles_title', 'الترجمة') }}</p>
                        <div v-if="subtitles.length" class="mb-2 flex flex-wrap gap-2">
                            <span
                                v-for="subtitle in subtitles"
                                :key="subtitle.id"
                                class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-300"
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
                                class="text-xs dark:text-gray-300"
                            />
                            <input
                                v-model="subtitleForm.language"
                                :placeholder="t('preview.subtitle_language_placeholder', 'رمز اللغة (ar, en...)')"
                                class="w-28 rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                maxlength="10"
                            />
                            <input
                                v-model="subtitleForm.label"
                                :placeholder="t('preview.subtitle_label_placeholder', 'اسم الترجمة')"
                                class="w-32 rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                                maxlength="100"
                            />
                            <button
                                type="submit"
                                :disabled="subtitleForm.processing || !subtitleForm.subtitle"
                                class="rounded-md bg-gray-800 px-3 py-1.5 text-xs text-white hover:bg-gray-900 disabled:opacity-50"
                            >
                                {{ t('security.add', 'إضافة') }}
                            </button>
                        </form>
                        <p v-if="subtitleForm.errors.subtitle" class="mt-1 text-xs text-red-600">{{ subtitleForm.errors.subtitle }}</p>
                    </div>

                    <!-- Interactive video question manager -->
                    <div v-if="kind(file) === 'video' && interactiveVideoEnabled && can.manage_questions" class="border-t border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                        <button
                            @click="showQuestionManager = !showQuestionManager; if (showQuestionManager) resetQuestionForm()"
                            class="flex w-full items-center justify-between text-xs font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            <span class="flex items-center gap-1.5">
                                <QuestionMarkCircleIcon class="h-4 w-4" /> {{ t('preview.interactive_questions', 'الأسئلة التفاعلية (:count)', { count: questions.length }) }}
                            </span>
                            <ChevronUpIcon v-if="showQuestionManager" class="h-4 w-4" />
                            <ChevronDownIcon v-else class="h-4 w-4" />
                        </button>

                        <div v-if="showQuestionManager" class="mt-3 space-y-3">
                            <ul v-if="questions.length" class="space-y-1">
                                <li
                                    v-for="q in questions"
                                    :key="q.id"
                                    class="flex items-center justify-between gap-2 rounded-md bg-gray-50 px-2.5 py-1.5 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300"
                                >
                                    <button @click="seekTo(q.timestamp_seconds)" class="min-w-0 flex-1 truncate text-right hover:text-[var(--accent-600)]">
                                        <span class="tabular-nums text-gray-400">{{ formatTimestamp(q.timestamp_seconds) }}</span>
                                        — {{ q.question }}
                                    </button>
                                    <button @click="editQuestion(q)" class="shrink-0 text-gray-400 hover:text-[var(--accent-600)]">{{ t('preview.edit', 'تعديل') }}</button>
                                    <button @click="deleteQuestion(q)" class="shrink-0 text-gray-400 hover:text-red-600">{{ t('action.delete', 'حذف') }}</button>
                                </li>
                            </ul>

                            <div v-if="questions.length" class="rounded-lg border border-gray-200 dark:border-gray-700">
                                <button
                                    type="button"
                                    @click="toggleReport"
                                    class="flex w-full items-center justify-between px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                                >
                                    <span>{{ t('preview.report_title', 'تقرير النتائج') }}</span>
                                    <ChevronUpIcon v-if="showReport" class="h-3.5 w-3.5" />
                                    <ChevronDownIcon v-else class="h-3.5 w-3.5" />
                                </button>

                                <div v-if="showReport" class="border-t border-gray-200 p-3 dark:border-gray-700">
                                    <p v-if="reportLoading" class="text-xs text-gray-500 dark:text-gray-400">{{ t('common.loading', 'جارٍ التحميل...') }}</p>
                                    <p v-else-if="reportError" class="text-xs text-red-600">{{ reportError }}</p>
                                    <div v-else-if="reportData" class="space-y-4">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ t('preview.report_respondents', ':count مشاهد أجاب على الأسئلة', { count: reportData.summary.respondents }) }}
                                            <span v-if="reportData.summary.average_percent != null">
                                                {{ t('preview.report_average', '— متوسط الدرجات :percent', { percent: percent(reportData.summary.average_percent) }) }}
                                            </span>
                                        </p>

                                        <div v-if="reportData.questions.length" class="space-y-2">
                                            <div v-for="q in reportData.questions" :key="q.id" class="rounded-md bg-gray-50 p-2 dark:bg-gray-900">
                                                <p class="mb-1 truncate text-xs text-gray-700 dark:text-gray-300">
                                                    <span class="tabular-nums text-gray-400">{{ formatTimestamp(q.timestamp_seconds) }}</span>
                                                    — {{ q.question }}
                                                    <span class="text-gray-400">{{ t('preview.report_answers_count', '(:count إجابة)', { count: q.total_answers }) }}</span>
                                                </p>
                                                <div v-for="choice in q.choices" :key="choice.id" class="mb-1 flex items-center gap-2">
                                                    <span
                                                        class="w-24 shrink-0 truncate text-[11px]"
                                                        :class="choice.is_correct ? 'font-medium text-green-700 dark:text-green-400' : 'text-gray-500 dark:text-gray-400'"
                                                    >{{ choice.label }}</span>
                                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                                        <div
                                                            class="h-full rounded-full"
                                                            :class="choice.is_correct ? 'bg-green-500' : 'bg-gray-400'"
                                                            :style="{ width: percent(choice.percent) === '—' ? '0%' : percent(choice.percent) }"
                                                        ></div>
                                                    </div>
                                                    <span class="w-10 shrink-0 text-left text-[11px] tabular-nums text-gray-500 dark:text-gray-400">{{ percent(choice.percent) }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div v-if="reportData.users.length">
                                            <p class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('preview.report_by_user', 'النتائج حسب المستخدم') }}</p>
                                            <table class="w-full text-xs">
                                                <tbody>
                                                    <tr v-for="u in reportData.users" :key="u.user_id" class="border-t border-gray-100 dark:border-gray-700">
                                                        <td class="py-1 pr-2 text-gray-700 dark:text-gray-300">{{ u.name ?? u.email }}</td>
                                                        <td class="py-1 text-gray-400">{{ u.correct }}/{{ u.total_questions }}</td>
                                                        <td class="py-1 text-left tabular-nums text-gray-600 dark:text-gray-400">{{ percent(u.percent) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <p v-else class="text-xs text-gray-400">{{ t('preview.report_no_answers', 'لم يجب أحد على الأسئلة بعد.') }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-dashed border-gray-300 p-2.5 dark:border-gray-600">
                                <button
                                    type="button"
                                    @click="triggerImportPicker"
                                    :disabled="importBusy"
                                    class="rounded-md border border-gray-300 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
                                >
                                    {{ importBusy ? t('preview.import_button_importing', 'جارٍ الاستيراد...') : t('preview.import_button', 'استيراد أسئلة من ملف (JSON/CSV)') }}
                                </button>
                                <a :href="route('questions.import-template')" class="text-xs text-[var(--accent-600)] hover:underline">
                                    {{ t('preview.download_template', 'تنزيل نموذج CSV') }}
                                </a>
                                <input ref="importFileInput" type="file" accept=".json,.csv,.txt" class="hidden" @change="handleImportFile" />
                            </div>
                            <p v-if="importMessage" class="text-xs text-green-600">{{ importMessage }}</p>
                            <p v-if="importError" class="text-xs text-red-600">{{ importError }}</p>

                            <form @submit.prevent="submitQuestionForm" class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ questionForm.editingId ? t('preview.edit_question_title', 'تعديل سؤال') : t('preview.new_question_title', 'إضافة سؤال جديد') }}</p>
                                <div class="flex items-center gap-2">
                                    <label class="text-xs text-gray-500 shrink-0 dark:text-gray-400">{{ t('preview.appears_at_second', 'يظهر عند الثانية') }}</label>
                                    <input type="number" min="0" v-model.number="questionForm.timestamp_seconds" class="w-24 rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                                    <button type="button" @click="questionForm.timestamp_seconds = Math.floor(player?.currentTime ?? 0)" class="text-xs text-[var(--accent-600)] hover:underline">
                                        {{ t('preview.use_current_time', 'استخدام الوقت الحالي') }}
                                    </button>
                                </div>
                                <textarea v-model="questionForm.question" rows="2" :placeholder="t('preview.question_placeholder', 'نص السؤال')" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" required></textarea>
                                <div v-for="(choice, index) in questionForm.choices" :key="index" class="flex items-center gap-2">
                                    <input type="radio" :value="index" v-model.number="questionForm.correct_index" class="shrink-0 border-gray-300 text-[var(--accent-600)]" />
                                    <input v-model="questionForm.choices[index]" :placeholder="t('preview.choice_placeholder', 'نص الخيار')" class="flex-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" required />
                                    <button v-if="questionForm.choices.length > 2" type="button" @click="removeChoiceField(index)" class="shrink-0 text-gray-400 hover:text-red-600">
                                        <XMarkIcon class="h-4 w-4" />
                                    </button>
                                </div>
                                <button v-if="questionForm.choices.length < 6" type="button" @click="addChoiceField" class="flex items-center gap-1 text-xs text-[var(--accent-600)] hover:underline">
                                    <PlusIcon class="h-3.5 w-3.5" /> {{ t('preview.add_choice', 'إضافة خيار') }}
                                </button>
                                <p v-if="questionFormError" class="text-xs text-red-600">{{ questionFormError }}</p>
                                <div class="flex items-center gap-2 pt-1">
                                    <button
                                        type="submit"
                                        :disabled="questionFormBusy"
                                        class="rounded-md bg-[var(--accent-600)] px-3 py-1.5 text-xs font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50"
                                    >
                                        {{ questionForm.editingId ? t('preview.save_edit', 'حفظ التعديل') : t('preview.add_question', 'إضافة السؤال') }}
                                    </button>
                                    <button v-if="questionForm.editingId" type="button" @click="resetQuestionForm" class="text-xs text-gray-500 hover:underline dark:text-gray-400">
                                        {{ t('preview.cancel_edit', 'إلغاء التعديل') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Comments -->
                    <div v-if="kind(file) === 'video'" class="border-t border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                        <p class="mb-2 flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-400">
                            <ChatBubbleLeftIcon class="h-4 w-4" /> {{ t('preview.comments_title', 'التعليقات (:count)', { count: comments.length }) }}
                        </p>
                        <ul v-if="comments.length" class="mb-3 max-h-40 space-y-2 overflow-y-auto">
                            <li v-for="comment in comments" :key="comment.id" class="flex items-start justify-between gap-2 text-sm">
                                <div class="min-w-0">
                                    <button
                                        v-if="comment.timestamp_seconds != null"
                                        @click="seekTo(comment.timestamp_seconds)"
                                        class="me-1 shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-xs tabular-nums text-gray-500 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-400 dark:hover:bg-gray-600"
                                    >
                                        {{ formatTimestamp(comment.timestamp_seconds) }}
                                    </button>
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ comment.user?.name }}:</span>
                                    <span class="text-gray-600 dark:text-gray-400">{{ comment.body }}</span>
                                </div>
                                <button
                                    v-if="comment.user_id === currentUserId"
                                    @click="deleteComment(comment)"
                                    class="shrink-0 text-gray-400 hover:text-red-600"
                                >
                                    <TrashIcon class="h-3.5 w-3.5" />
                                </button>
                            </li>
                        </ul>
                        <form v-if="can.comment" @submit.prevent="submitComment" class="space-y-1.5">
                            <textarea v-model="commentForm.body" rows="2" :placeholder="t('preview.comment_placeholder', 'أضف تعليقاً...')" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                            <div class="flex items-center justify-between">
                                <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    <input type="checkbox" v-model="anchorCommentToTime" class="rounded border-gray-300" />
                                    {{ t('preview.anchor_comment', 'ربط التعليق بلحظة :time الحالية', { time: formatTimestamp(Math.floor(player?.currentTime ?? 0)) }) }}
                                </label>
                                <button
                                    type="submit"
                                    :disabled="commentForm.processing || !commentForm.body.trim()"
                                    class="rounded-md bg-gray-800 px-3 py-1.5 text-xs text-white hover:bg-gray-900 disabled:opacity-50"
                                >
                                    {{ t('preview.post', 'نشر') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div v-if="showPlaylist && playlist.length > 1" class="w-64 shrink-0 overflow-y-auto border-r border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <ul>
                        <li v-for="item in playlist" :key="item.id">
                            <button
                                @click="playFromList(item)"
                                class="block w-full truncate px-3 py-2 text-right text-sm hover:bg-gray-50 dark:hover:bg-gray-700"
                                :class="item.id === file.id ? 'bg-orange-50 font-medium text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' : 'text-gray-700 dark:text-gray-300'"
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
