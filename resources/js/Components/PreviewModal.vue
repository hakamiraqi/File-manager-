<script setup>
import { ref, reactive, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useForm, router, usePage, Link } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import SaveButton from '@/Components/SaveButton.vue';
import { defineAsyncComponent } from 'vue';
// Loaded only when a PDF is opened on a phone (pdf.js is large).
const PdfViewer = defineAsyncComponent(() => import('@/Components/PdfViewer.vue'));
import { BookOpenIcon,
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
    CheckCircleIcon,
    XCircleIcon,
    ClockIcon,
    MapPinIcon,
    PaperAirplaneIcon,
    SparklesIcon,
    LightBulbIcon,
    PencilSquareIcon,
    MagnifyingGlassPlusIcon,
    MagnifyingGlassMinusIcon,
} from '@heroicons/vue/24/outline';
import { useVideoZoom, ZOOM_PRESETS } from '@/Composables/useVideoZoom';
import Plyr from 'plyr';
import 'plyr/dist/plyr.css';
// Plyr's own dist/plyr.svg isn't in its package "exports" map so Vite can't
// import it directly; a local copy also avoids depending on Plyr's CDN
// (cdn.plyr.io) at runtime, which may be unreachable behind a firewall.
import plyrIconUrl from '@/vendor/plyr-icons.svg?url';
import WaveSurfer from 'wavesurfer.js';

// Phones show a PDF in an <iframe> as its first page only, so touch screens get PdfViewer.
const touchScreen = typeof window !== 'undefined' && !!window.matchMedia?.('(pointer: coarse)').matches;
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

// Shown as PDF converted on the server by LibreOffice (FileController::documentPreview).
const OFFICE_EXTENSIONS = ['doc', 'docx', 'odt', 'rtf', 'ppt', 'pptx', 'odp', 'xls', 'xlsx', 'ods'];
const OFFICE_EDIT_EXT = ['docx', 'odt', 'rtf', 'txt', 'doc', 'docm', 'dotx', 'xlsx', 'ods', 'csv', 'xls', 'xlsm', 'pptx', 'odp', 'ppt', 'ppsx', 'pdf'];
const editableInOffice = (f) => !!page.props.appSettings?.officeEditor && OFFICE_EDIT_EXT.includes((f?.extension || f?.name?.split('.').pop() || '').toLowerCase());
const officePreviewEnabled = computed(() => page.props.appSettings?.officePreviewEnabled ?? false);

function kind(file) {
    if (!file) return null;
    if (file.mime_type?.startsWith('image/')) return 'image';
    if (file.mime_type?.startsWith('video/')) return 'video';
    if (file.mime_type?.startsWith('audio/')) return 'audio';
    if (file.mime_type === 'application/pdf') return 'pdf';
    if (['text/plain', 'text/csv', 'application/json'].includes(file.mime_type)) return 'text';
    if (officePreviewEnabled.value && OFFICE_EXTENSIONS.includes((file.extension ?? file.name?.split('.').pop() ?? '').toLowerCase())) return 'document';
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

const pageDir = typeof document !== 'undefined' ? document.documentElement.dir || 'rtl' : 'rtl';
const pageLang = typeof document !== 'undefined' ? document.documentElement.lang || 'ar' : 'ar';
const ARABIC_LETTERS = ['أ', 'ب', 'ج', 'د', 'هـ', 'و'];
const choiceLetter = (i) => (pageLang.startsWith('ar') ? ARABIC_LETTERS[i % 6] : String.fromCharCode(65 + i));
const questionNumber = computed(() => (activeQuestion.value ? questions.value.findIndex((q) => q.id === activeQuestion.value.id) + 1 : 0));

function choiceState(choice) {
    const fb = answerFeedback.value;
    if (!fb) return selectedChoiceId.value === choice.id ? 'selected' : 'idle';
    if (choice.id === fb.correct_choice_id) return 'correct';
    if (choice.id === selectedChoiceId.value) return 'wrong';
    return 'dim';
}

function choiceClasses(choice) {
    return {
        idle: 'border-gray-200 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-white/5',
        selected: 'border-[var(--accent-500)] bg-[var(--accent-50)] dark:bg-white/10',
        correct: 'border-green-500 bg-green-50 dark:bg-green-500/15',
        wrong: 'border-red-500 bg-red-50 dark:bg-red-500/15',
        dim: 'border-gray-200 opacity-50 dark:border-gray-700',
    }[choiceState(choice)];
}

function choiceBadgeClasses(choice) {
    return {
        idle: 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-200',
        selected: 'bg-[var(--accent-600)] text-white',
        correct: 'bg-green-600 text-white',
        wrong: 'bg-red-600 text-white',
        dim: 'bg-gray-100 text-gray-400 dark:bg-white/5',
    }[choiceState(choice)];
}

function questionSegmentClass(q) {
    if (activeQuestion.value && q.id === activeQuestion.value.id) return 'bg-[var(--accent-500)]';
    if (q.answered_choice_id != null) return q.answered_correctly === false ? 'bg-red-400' : 'bg-green-400';
    return 'bg-gray-200 dark:bg-white/10';
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
const videoTime = ref(0);
const sortedComments = computed(() => [...comments.value].sort((a, b) => new Date(b.created_at) - new Date(a.created_at)));

function avatarColor(name = '') {
    let hash = 0;
    for (const ch of name) hash = (hash * 31 + ch.codePointAt(0)) | 0;
    return `hsl(${Math.abs(hash) % 360} 45% 45%)`;
}

const initialOf = (name) => (name?.trim() ? [...name.trim()][0].toUpperCase() : '?');

function relativeTime(iso) {
    const then = new Date(iso);
    const seconds = Math.round((then - Date.now()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(pageLang, { numeric: 'auto' });
    const steps = [
        [60, 'second'],
        [3600, 'minute'],
        [86400, 'hour'],
        [2592000, 'day'],
    ];
    const abs = Math.abs(seconds);
    if (abs < 60) return rtf.format(0, 'second');
    for (let i = 1; i < steps.length; i++) {
        if (abs < steps[i][0]) return rtf.format(Math.round(seconds / steps[i - 1][0]), steps[i][1]);
    }
    return then.toLocaleDateString(pageLang);
}

function canDeleteComment(comment) {
    return comment.user_id === currentUserId.value || !!page.props.auth?.user?.is_admin;
}

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
const playerBox = ref(null);
let player = null;

// Zoom (2026-11-13): a ratio from the menu, two-finger pinch, Ctrl + wheel, drag to move.
const vz = useVideoZoom(playerBox, videoEl);
const zoomMenu = ref(false);
const zoomPresets = ZOOM_PRESETS;

// Quality (2026-11-13): the original is always one of the choices («الأصلية»), beside the
// smaller copies the server made; the last choice is remembered in this browser.
const ORIGINAL_UNKNOWN = 10000;
const QUALITY_KEY = 'filenest:video-quality';
const originalSize = (file) => file.video_height || ORIGINAL_UNKNOWN;

function videoSources(file) {
    const orig = originalSize(file);
    const smaller = (file.video_qualities || []).filter((q) => q.height < orig);

    return [
        { src: route('files.preview', file.id), type: file.mime_type, size: orig },
        ...smaller.map((q) => ({ src: q.src, type: 'video/mp4', size: q.height })),
    ];
}

function preferredQuality(file) {
    const sizes = videoSources(file).map((s) => s.size);
    let saved = null;
    try {
        saved = window.localStorage.getItem(QUALITY_KEY);
    } catch {
        // nothing remembered
    }
    if (saved === 'original') return sizes[0];
    const want = Number(saved) || 720;
    // The largest copy not above the wanted height; else the smallest there is.
    const fit = sizes.slice(1).filter((h) => h <= want).sort((a, b) => b - a)[0];
    if (fit) return fit;
    if (!saved) return sizes[0];

    return sizes.length > 1 ? Math.min(...sizes.slice(1)) : sizes[0];
}

function rememberQuality(size) {
    try {
        window.localStorage.setItem(QUALITY_KEY, size === originalSize(props.file) ? 'original' : String(size));
    } catch {
        // nothing to remember with
    }
}

function destroyPlyr() {
    rememberPosition();
    vz.forget();
    zoomMenu.value = false;
    clearUpNext();
    resumedFrom.value = null;
    if (player) {
        player.destroy();
        player = null;
    }
}

// Where each video was left off (this browser only), so reopening it carries on.
const resumedFrom = ref(null);
let resumeTimer = null;
let lastSavedAt = 0;
const positionKey = (id) => `filenest:video-position:${id}`;

function rememberPosition(force = true) {
    if (!player || !props.file) return;
    const now = Date.now();
    if (!force && now - lastSavedAt < 5000) return;
    lastSavedAt = now;
    const position = Math.floor(player.currentTime || 0);
    const duration = Math.floor(player.duration || 0);
    try {
        if (position < 10 || (duration > 0 && position >= duration - 15)) {
            window.localStorage.removeItem(positionKey(props.file.id));
        } else {
            window.localStorage.setItem(positionKey(props.file.id), String(position));
        }
    } catch {
        // Private mode or storage switched off: nothing to remember with.
    }
}

function offerResume() {
    if (!player || !props.file) return;
    let saved = null;
    try {
        saved = Number(window.localStorage.getItem(positionKey(props.file.id))) || null;
    } catch {
        saved = null;
    }
    if (!saved || (player.duration && saved >= player.duration - 15)) return;
    player.currentTime = saved;
    resumedFrom.value = saved;
    clearTimeout(resumeTimer);
    resumeTimer = setTimeout(() => (resumedFrom.value = null), 7000);
}

function startOver() {
    if (player) player.currentTime = 0;
    resumedFrom.value = null;
}

// Autoplay-next waits a few seconds on a card that can be cancelled.
const upNextSeconds = ref(null);
let upNextTimer = null;
const nextItem = computed(() => (hasNext.value ? playlist.value[currentIndex.value + 1] : null));

function clearUpNext() {
    clearInterval(upNextTimer);
    upNextTimer = null;
    upNextSeconds.value = null;
}

function startUpNext() {
    clearUpNext();
    upNextSeconds.value = 5;
    upNextTimer = setInterval(() => {
        upNextSeconds.value -= 1;
        if (upNextSeconds.value <= 0) {
            clearUpNext();
            playNext();
        }
    }, 1000);
}

function playNextNow() {
    clearUpNext();
    playNext();
}

function plyrLabels() {
    return {
        restart: t('player.restart', 'من البداية'),
        rewind: t('player.rewind', 'رجوع {seektime} ث'),
        play: t('player.play', 'تشغيل'),
        pause: t('player.pause', 'إيقاف مؤقت'),
        fastForward: t('player.fast_forward', 'تقديم {seektime} ث'),
        seek: t('player.seek', 'انتقال'),
        seekLabel: t('player.seek_label', '{currentTime} من {duration}'),
        played: t('player.played', 'تم تشغيله'),
        buffered: t('player.buffered', 'محمّل'),
        currentTime: t('player.current_time', 'الوقت الحالي'),
        duration: t('player.duration', 'المدة'),
        volume: t('player.volume', 'الصوت'),
        mute: t('player.mute', 'كتم الصوت'),
        unmute: t('player.unmute', 'إلغاء الكتم'),
        enableCaptions: t('player.enable_captions', 'إظهار الترجمة'),
        disableCaptions: t('player.disable_captions', 'إخفاء الترجمة'),
        enterFullscreen: t('player.enter_fullscreen', 'ملء الشاشة'),
        exitFullscreen: t('player.exit_fullscreen', 'الخروج من ملء الشاشة'),
        captions: t('preview.subtitles_title', 'الترجمة'),
        settings: t('player.settings', 'الإعدادات'),
        pip: t('player.pip', 'صورة داخل صورة'),
        menuBack: t('player.menu_back', 'رجوع'),
        speed: t('preview.speed', 'السرعة'),
        normal: t('player.speed_normal', 'عادية'),
        quality: t('player.quality', 'الجودة'),
        download: t('action.download', 'تنزيل'),
        disabled: t('player.off', 'إيقاف'),
        enabled: t('player.on', 'تشغيل'),
    };
}

function mountPlyr() {
    destroyPlyr();
    nextTick(() => {
        if (!videoEl.value || !props.file || kind(props.file) !== 'video') return;

        const settings = ['captions', 'speed'];
        const sources = videoSources(props.file);
        if (sources.length > 1) settings.splice(1, 0, 'quality');
        const orig = originalSize(props.file);

        player = new Plyr(videoEl.value, {
            iconUrl: plyrIconUrl,
            // Plyr's default points at its CDN; nothing must load from the
            // internet on the offline server.
            blankVideo: '',
            // Phones (2026-11-12): with every button the timeline had no room left and vanished,
            // so the video couldn't be seeked. There the volume slider (the phone has buttons),
            // ±10s (double-tap does it), download (in the header) and PiP are left out, and the
            // timeline gets its own row (CSS below).
            controls: window.matchMedia('(max-width: 640px)').matches
                ? ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'captions', 'settings', 'fullscreen']
                : ['play-large', 'rewind', 'play', 'fast-forward', 'progress', 'current-time', 'duration', 'mute', 'volume', 'captions', 'settings', 'download', 'pip', 'fullscreen'],
            urls: { download: route('files.download', props.file.id) },
            seekTime: 10,
            keyboard: { focused: true, global: false },
            tooltips: { controls: true, seek: true },
            speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2] },
            i18n: {
                ...plyrLabels(),
                qualityLabel: { [orig]: orig === ORIGINAL_UNKNOWN ? t('player.original', 'الأصلية') : `${t('player.original', 'الأصلية')} · ${orig}p` },
            },
            // Fullscreen takes the whole frame, so the zoom button and our notes stay visible.
            fullscreen: { enabled: true, fallback: true, iosNative: false, container: '.fn-player' },
            // Interactive questions shown as marks on the timeline.
            markers: {
                enabled: questions.value.length > 0 || comments.value.some((c) => c.timestamp_seconds != null),
                points: [
                    ...questions.value.map((q) => ({ time: q.timestamp_seconds, label: t('preview.interactive_question_label', 'سؤال تفاعلي') })),
                    ...comments.value
                        .filter((c) => c.timestamp_seconds != null)
                        .map((c) => ({ time: c.timestamp_seconds, label: `💬 ${c.user?.name ?? ''}: ${c.body.length > 40 ? c.body.slice(0, 40) + '…' : c.body}` })),
                ],
            },
            settings,
            captions: { active: subtitles.value.length > 0, update: true },
            ...(sources.length > 1
                ? { quality: { default: preferredQuality(props.file), options: sources.map((q) => q.size) } }
                : {}),
        });

        player.on('ended', () => {
            try {
                window.localStorage.removeItem(positionKey(props.file.id));
            } catch {
                // nothing remembered
            }
            if (autoplayNext.value && hasNext.value) startUpNext();
        });
        player.once('loadedmetadata', offerResume);
        player.on('timeupdate', () => {
            rememberPosition(false);
            videoTime.value = player?.currentTime ?? 0;
        });
        player.on('play', clearUpNext);
        player.on('qualitychange', (e) => {
            if (e.detail?.quality) rememberQuality(e.detail.quality);
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
    clearTimeout(resumeTimer);
    destroyPlyr();
    destroyWavesurfer();
});
</script>

<template>
    <Modal :show="show" @close="handleClose" max-width="4xl">
        <div v-if="file" class="flex max-h-[85vh] flex-col dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-700">
                <div class="min-w-0">
                    <h3 class="truncate font-medium text-gray-900 dark:text-gray-100"><span dir="auto" class="inline-block max-w-full truncate align-bottom">{{ file.name }}</span></h3>
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
                    <SaveButton v-if="currentUserId" type="file" :id="file.id" class="!rounded-md !p-2" />
                    <Link
                        v-if="currentUserId && (kind(file) === 'pdf' || kind(file) === 'document')"
                        :href="route('reader.show', file.id)"
                        class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                        :title="t('reader.open_full', 'فتح في القارئ')"
                    >
                        <BookOpenIcon class="h-5 w-5" /> <span class="hidden sm:inline">{{ t('reader.read', 'قراءة') }}</span>
                    </Link>
                    <Link
                        v-if="currentUserId && editableInOffice(file)"
                        :href="route('office.edit', file.id)"
                        class="inline-flex items-center gap-1.5 rounded-md bg-[var(--accent-600)] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[var(--accent-700)]"
                    >
                        <PencilSquareIcon class="h-4 w-4" /> <span class="hidden sm:inline">{{ t('office.edit', 'تحرير') }}</span>
                    </Link>
                    <a :href="route('files.download', file.id)" class="rounded-md p-2 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('action.download', 'تنزيل')">
                        <ArrowDownTrayIcon class="h-5 w-5 dark:text-gray-300" />
                    </a>
                    <button @click="handleClose" class="rounded-md p-2 hover:bg-gray-100 dark:hover:bg-gray-700">
                        <XMarkIcon class="h-5 w-5 dark:text-gray-300" />
                    </button>
                </div>
            </div>

            <div class="flex flex-1 flex-col overflow-hidden md:flex-row">
                <div class="flex flex-1 flex-col overflow-auto bg-gray-50 dark:bg-gray-900">
                    <div class="flex flex-1 items-center justify-center p-4">
                        <img v-if="kind(file) === 'image'" :src="route('files.preview', file.id)" class="max-h-[65vh] rounded" />

                        <!-- Media controls stay left-to-right in Arabic too, like every major player. -->
                        <div
                            v-else-if="kind(file) === 'video'"
                            ref="playerBox"
                            dir="ltr"
                            class="fn-player relative w-full max-h-[65vh] overflow-hidden rounded-xl bg-black shadow-lg ring-1 ring-black/10"
                            style="touch-action: pan-x pan-y"
                            @pointerdown="vz.onPointerDown"
                            @pointermove="vz.onPointerMove"
                            @pointerup="vz.onPointerUp"
                            @pointercancel="vz.onPointerUp"
                            @click.capture="vz.onClickCapture"
                            @wheel="vz.onWheel"
                        >
                            <!-- Zoom: a ratio, or pinch / Ctrl + wheel; drag to move around once zoomed. -->
                            <div class="fn-zoom absolute right-2 top-2 z-[6] flex flex-col items-end gap-1.5" @click.stop>
                                <button
                                    type="button"
                                    class="flex items-center gap-1 rounded-full bg-black/60 px-2.5 py-1.5 text-xs font-semibold text-white backdrop-blur transition hover:bg-black/80"
                                    :title="t('player.zoom', 'تكبير وتصغير')"
                                    @click="zoomMenu = !zoomMenu"
                                >
                                    <MagnifyingGlassPlusIcon class="h-4 w-4" />
                                    <span v-if="vz.fill.value">{{ t('player.zoom_fill', 'ملء') }}</span>
                                    <span v-else-if="vz.zoom.value !== 1">{{ vz.percent.value }}%</span>
                                </button>
                                <div v-if="zoomMenu" class="w-44 rounded-xl bg-gray-900/95 p-2 text-sm text-white shadow-xl ring-1 ring-white/10">
                                    <div class="mb-2 flex items-center justify-between gap-1">
                                        <button type="button" class="rounded-lg p-1.5 hover:bg-white/10 disabled:opacity-40" :disabled="vz.zoom.value <= 1" :title="t('player.zoom_out', 'تصغير')" @click="vz.step(-1)">
                                            <MagnifyingGlassMinusIcon class="h-5 w-5" />
                                        </button>
                                        <span class="text-xs tabular-nums">{{ vz.fill.value ? t('player.zoom_fill', 'ملء') : vz.percent.value + '%' }}</span>
                                        <button type="button" class="rounded-lg p-1.5 hover:bg-white/10 disabled:opacity-40" :disabled="vz.zoom.value >= 5" :title="t('player.zoom_in', 'تكبير')" @click="vz.step(1)">
                                            <MagnifyingGlassPlusIcon class="h-5 w-5" />
                                        </button>
                                    </div>
                                    <div class="grid grid-cols-3 gap-1">
                                        <button
                                            v-for="z in zoomPresets"
                                            :key="z"
                                            type="button"
                                            class="rounded-lg px-1 py-1 text-xs"
                                            :class="!vz.fill.value && vz.zoom.value === z ? 'bg-[var(--accent-600)]' : 'bg-white/5 hover:bg-white/15'"
                                            @click="vz.setZoom(z)"
                                        >
                                            {{ z === 1 ? t('player.zoom_fit', 'ملائمة') : Math.round(z * 100) + '%' }}
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg px-1 py-1 text-xs"
                                            :class="vz.fill.value ? 'bg-[var(--accent-600)]' : 'bg-white/5 hover:bg-white/15'"
                                            :title="t('player.zoom_fill_hint', 'يملأ الإطار ويقصّ الأطراف')"
                                            @click="vz.setFill()"
                                        >
                                            {{ t('player.zoom_fill', 'ملء') }}
                                        </button>
                                    </div>
                                    <p class="mt-2 text-[11px] leading-snug text-white/60" dir="auto">{{ t('player.zoom_hint', 'أو بإصبعين على الشاشة، أو Ctrl مع عجلة الفأرة. اسحب لتحريك الصورة بعد التكبير.') }}</p>
                                </div>
                            </div>
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

                            <button
                                v-if="resumedFrom && !upNextSeconds"
                                type="button"
                                @click="startOver"
                                class="absolute bottom-16 left-3 z-[5] flex items-center gap-2 rounded-full bg-black/80 px-3.5 py-2 text-xs text-white shadow-lg backdrop-blur transition hover:bg-black"
                            >
                                <span dir="auto">{{ t('player.resumed_from', 'أكملنا من :time', { time: formatTime(resumedFrom) }) }}</span>
                                <span class="font-semibold text-[var(--accent-500)]">{{ t('player.start_over', 'من البداية') }}</span>
                            </button>

                            <div
                                v-if="upNextSeconds && nextItem"
                                class="absolute bottom-16 right-3 z-[5] w-64 rounded-2xl border border-white/10 bg-gray-900/95 p-3 text-white shadow-2xl backdrop-blur"
                            >
                                <div class="mb-2 flex items-center gap-2 text-xs text-gray-300">
                                    <svg class="h-5 w-5 -rotate-90" viewBox="0 0 36 36">
                                        <circle cx="18" cy="18" r="15" fill="none" stroke="rgba(255,255,255,.15)" stroke-width="4" />
                                        <circle
                                            cx="18" cy="18" r="15" fill="none" stroke="var(--accent-500)" stroke-width="4" stroke-linecap="round"
                                            :stroke-dasharray="94.2" :stroke-dashoffset="94.2 * (1 - upNextSeconds / 5)"
                                            class="transition-all duration-1000 ease-linear"
                                        />
                                    </svg>
                                    <span dir="auto">{{ t('player.up_next_in', 'التالي خلال :seconds ث', { seconds: upNextSeconds }) }}</span>
                                </div>
                                <p dir="auto" class="mb-3 line-clamp-2 text-sm font-semibold">{{ nextItem.name }}</p>
                                <div class="flex gap-2">
                                    <button type="button" @click="clearUpNext" class="flex-1 rounded-lg border border-white/20 px-3 py-1.5 text-xs hover:bg-white/10">
                                        {{ t('action.cancel', 'إلغاء') }}
                                    </button>
                                    <button type="button" @click="playNextNow" class="flex flex-1 items-center justify-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-xs font-semibold hover:bg-[var(--accent-500)]">
                                        <PlayIcon class="h-3.5 w-3.5" /> {{ t('player.play_now', 'شغّل الآن') }}
                                    </button>
                                </div>
                            </div>

                            <!-- Interactive question: blocks the video until answered -->
                            <div v-if="activeQuestion" :dir="pageDir" class="absolute inset-0 z-10 flex items-center justify-center overflow-y-auto bg-black/75 p-3 backdrop-blur-sm sm:p-6">
                                <div :key="activeQuestion.id" class="fn-pop my-auto w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl ring-1 ring-black/5 dark:bg-gray-900 dark:ring-white/10">
                                    <div class="mb-3 flex items-center gap-2">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[var(--accent-50)] px-2.5 py-1 text-xs font-semibold text-[var(--accent-700)] dark:bg-white/10 dark:text-white">
                                            <QuestionMarkCircleIcon class="h-4 w-4" />
                                            {{ t('player.question_n_of', 'سؤال :n من :total', { n: questionNumber, total: questions.length }) }}
                                        </span>
                                        <span dir="ltr" class="ms-auto inline-flex items-center gap-1 text-xs tabular-nums text-gray-400">
                                            <ClockIcon class="h-3.5 w-3.5" /> {{ formatTimestamp(activeQuestion.timestamp_seconds) }}
                                        </span>
                                    </div>
                                    <div v-if="questions.length > 1" class="mb-4 flex gap-1">
                                        <span v-for="q in questions" :key="q.id" class="h-1 flex-1 rounded-full transition" :class="questionSegmentClass(q)"></span>
                                    </div>
                                    <p dir="auto" class="mb-4 text-lg font-bold leading-relaxed text-gray-900 dark:text-white">{{ activeQuestion.question }}</p>
                                    <div class="space-y-2.5">
                                        <button
                                            v-for="(choice, i) in activeQuestion.choices"
                                            :key="choice.id"
                                            type="button"
                                            :disabled="!!answerFeedback"
                                            @click="selectedChoiceId = choice.id"
                                            class="flex w-full items-center gap-3 rounded-xl border-2 p-3 text-start text-sm transition"
                                            :class="choiceClasses(choice)"
                                        >
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold transition" :class="choiceBadgeClasses(choice)">{{ choiceLetter(i) }}</span>
                                            <span dir="auto" class="flex-1 text-gray-800 dark:text-gray-100">{{ choice.label }}</span>
                                            <CheckCircleIcon v-if="choiceState(choice) === 'correct'" class="h-6 w-6 shrink-0 text-green-500" />
                                            <XCircleIcon v-else-if="choiceState(choice) === 'wrong'" class="h-6 w-6 shrink-0 text-red-500" />
                                        </button>
                                    </div>
                                    <div
                                        v-if="answerFeedback"
                                        class="mt-4 flex items-center gap-3 rounded-xl p-3"
                                        :class="answerFeedback.is_correct ? 'bg-green-50 text-green-700 dark:bg-green-500/15 dark:text-green-300' : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300'"
                                    >
                                        <SparklesIcon v-if="answerFeedback.is_correct" class="h-7 w-7 shrink-0" />
                                        <LightBulbIcon v-else class="h-7 w-7 shrink-0" />
                                        <div>
                                            <p class="font-bold">{{ answerFeedback.is_correct ? t('preview.correct_answer', 'إجابة صحيحة!') : t('preview.wrong_answer', 'إجابة غير صحيحة.') }}</p>
                                            <p class="text-xs opacity-80">{{ t('preview.your_score', 'درجتك: :correct من :total', { correct: score.correct, total: score.total }) }}</p>
                                        </div>
                                    </div>
                                    <button
                                        v-if="!answerFeedback"
                                        @click="submitAnswer"
                                        :disabled="selectedChoiceId == null || answering"
                                        class="mt-4 w-full rounded-xl bg-[var(--accent-600)] py-3 text-sm font-bold text-white shadow-sm transition hover:bg-[var(--accent-700)] disabled:opacity-40"
                                    >
                                        {{ answering ? t('common.loading', 'جارِ التحميل...') : t('preview.submit_answer', 'إرسال الإجابة') }}
                                    </button>
                                    <button
                                        v-else
                                        @click="continueAfterQuestion"
                                        class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--accent-600)] py-3 text-sm font-bold text-white shadow-sm transition hover:bg-[var(--accent-700)]"
                                    >
                                        <PlayIcon class="h-4 w-4" /> {{ t('preview.continue_watching', 'متابعة المشاهدة') }}
                                    </button>
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

                        <!-- The reader (2026-11-12): the same on every screen, with notes and bookmarks. -->
                        <PdfViewer
                            v-else-if="kind(file) === 'pdf' || kind(file) === 'document'"
                            :key="file.id"
                            :src="kind(file) === 'pdf' ? route('files.preview', file.id) : route('files.document-preview', file.id)"
                            :file-id="file.id"
                            :file-name="file.name"
                            :download-url="route('files.download', file.id)"
                            :reader-href="route('reader.show', file.id)"
                            :can-create="!!currentUserId"
                        />
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
                            <pre v-else dir="auto" class="max-h-64 overflow-y-auto whitespace-pre-wrap break-words rounded-md bg-gray-50 p-3 text-xs leading-relaxed text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ extractedText }}</pre>
                        </div>
                    </div>

                    <div v-if="playlist.length > 1 && (kind(file) === 'video' || kind(file) === 'audio')" class="flex flex-wrap items-center gap-3 border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                        <div dir="ltr" class="flex items-center gap-1.5">
                            <button
                                @click="playPrev"
                                :disabled="!hasPrev"
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-700 transition hover:bg-gray-200 disabled:opacity-30 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                                :title="t('preview.prev', 'السابق')"
                            >
                                <BackwardIcon class="h-4 w-4" />
                            </button>
                            <button
                                @click="playNext"
                                :disabled="!hasNext"
                                class="flex h-9 w-9 items-center justify-center rounded-full bg-[var(--accent-600)] text-white transition hover:bg-[var(--accent-500)] disabled:opacity-30"
                                :title="t('preview.next', 'التالي')"
                            >
                                <ForwardIcon class="h-4 w-4" />
                            </button>
                        </div>
                        <div v-if="nextItem" class="min-w-0 flex-1">
                            <p class="text-[11px] uppercase tracking-wide text-gray-400">{{ t('player.next', 'التالي') }}</p>
                            <p dir="auto" class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ nextItem.name }}</p>
                        </div>
                        <div v-else class="flex-1 text-sm text-gray-400">{{ t('player.end_of_list', 'آخر مقطع في القائمة') }}</div>
                        <label class="flex cursor-pointer select-none items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                            {{ t('preview.autoplay_next', 'تشغيل تلقائي للتالي') }}
                            <span class="relative inline-flex h-5 w-9 items-center">
                                <input type="checkbox" v-model="autoplayNext" class="peer sr-only" />
                                <span class="absolute inset-0 rounded-full bg-gray-300 transition peer-checked:bg-[var(--accent-600)] dark:bg-gray-600"></span>
                                <span class="absolute start-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4 rtl:peer-checked:-translate-x-4"></span>
                            </span>
                        </label>
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
                    <div v-if="kind(file) === 'video'" class="border-t border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <div class="mb-3 flex items-center gap-2">
                            <ChatBubbleLeftIcon class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ t('player.comments', 'التعليقات') }}</h4>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ comments.length }}</span>
                        </div>

                        <form
                            v-if="can.comment"
                            @submit.prevent="submitComment"
                            class="mb-4 rounded-2xl border border-gray-200 bg-gray-50 p-2 transition focus-within:border-[var(--accent-500)] focus-within:ring-2 focus-within:ring-[var(--accent-500)]/20 dark:border-gray-700 dark:bg-gray-900/40"
                        >
                            <textarea
                                v-model="commentForm.body"
                                rows="2"
                                dir="auto"
                                :placeholder="t('preview.comment_placeholder', 'أضف تعليقاً...')"
                                @keydown.ctrl.enter.prevent="submitComment"
                                @keydown.meta.enter.prevent="submitComment"
                                class="w-full resize-none border-0 bg-transparent px-2 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-0 dark:text-gray-100"
                            ></textarea>
                            <div class="flex items-center gap-2 px-1">
                                <button
                                    type="button"
                                    @click="anchorCommentToTime = !anchorCommentToTime"
                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs transition"
                                    :class="anchorCommentToTime ? 'bg-[var(--accent-600)] text-white' : 'bg-gray-200/70 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300'"
                                >
                                    <MapPinIcon class="h-3.5 w-3.5" />
                                    <span v-if="anchorCommentToTime">{{ t('player.at_time', 'عند :time', { time: formatTimestamp(Math.floor(videoTime)) }) }}</span>
                                    <span v-else>{{ t('player.anchor_current_time', 'ربط بالوقت الحالي') }}</span>
                                </button>
                                <button
                                    type="submit"
                                    :disabled="commentForm.processing || !commentForm.body.trim()"
                                    class="ms-auto inline-flex items-center gap-1.5 rounded-full bg-[var(--accent-600)] px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-[var(--accent-700)] disabled:opacity-40"
                                >
                                    <PaperAirplaneIcon class="h-3.5 w-3.5 rtl:-scale-x-100" /> {{ t('preview.post', 'نشر') }}
                                </button>
                            </div>
                        </form>

                        <p v-if="!comments.length" class="py-6 text-center text-sm text-gray-400">{{ t('player.no_comments_yet', 'لا توجد تعليقات بعد. كن أول من يعلّق.') }}</p>
                        <ul v-else class="max-h-80 space-y-3 overflow-y-auto pe-1">
                            <li v-for="comment in sortedComments" :key="comment.id" class="group flex gap-3">
                                <span
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white"
                                    :style="{ background: avatarColor(comment.user?.name) }"
                                >{{ initialOf(comment.user?.name) }}</span>
                                <div class="min-w-0 flex-1 rounded-2xl rounded-ss-sm bg-gray-50 px-3 py-2 dark:bg-gray-700/50">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ comment.user?.name ?? '—' }}</span>
                                        <span class="shrink-0 text-[11px] text-gray-400">{{ relativeTime(comment.created_at) }}</span>
                                        <button
                                            v-if="canDeleteComment(comment)"
                                            @click="deleteComment(comment)"
                                            :title="t('action.delete', 'حذف')"
                                            class="ms-auto shrink-0 rounded p-1 text-gray-400 transition hover:bg-red-50 hover:text-red-600 sm:opacity-0 sm:group-hover:opacity-100 dark:hover:bg-red-500/10"
                                        >
                                            <TrashIcon class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                    <button
                                        v-if="comment.timestamp_seconds != null"
                                        @click="seekTo(comment.timestamp_seconds)"
                                        dir="ltr"
                                        class="mt-1 inline-flex items-center gap-1 rounded-full bg-[var(--accent-50)] px-2 py-0.5 text-xs font-semibold tabular-nums text-[var(--accent-700)] transition hover:bg-[var(--accent-600)] hover:text-white dark:bg-white/10 dark:text-white"
                                    >
                                        <PlayIcon class="h-3 w-3" /> {{ formatTimestamp(comment.timestamp_seconds) }}
                                    </button>
                                    <p dir="auto" class="mt-1 whitespace-pre-line break-words text-sm leading-relaxed text-gray-700 dark:text-gray-200">{{ comment.body }}</p>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <div
                    v-if="showPlaylist && playlist.length > 1"
                    class="max-h-72 w-full shrink-0 overflow-y-auto border-t border-gray-200 bg-white md:max-h-none md:w-80 md:border-s md:border-t-0 dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="sticky top-0 z-[1] flex items-center gap-2 border-b border-gray-100 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-700 dark:bg-gray-800/95">
                        <QueueListIcon class="h-5 w-5 text-gray-500" />
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ t('preview.playlist_title', 'قائمة التشغيل') }}</span>
                        <span dir="ltr" class="ms-auto rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ currentIndex + 1 }}/{{ playlist.length }}</span>
                    </div>
                    <ul class="space-y-1 p-2">
                        <li v-for="(item, i) in playlist" :key="item.id">
                            <button
                                @click="playFromList(item)"
                                class="group flex w-full items-center gap-3 rounded-xl p-2 text-start transition"
                                :class="item.id === file.id ? 'bg-[var(--accent-50)] ring-1 ring-[var(--accent-500)] dark:bg-white/10 dark:ring-[var(--accent-500)]' : 'hover:bg-gray-50 dark:hover:bg-gray-700/60'"
                            >
                                <span class="w-5 shrink-0 text-center text-xs text-gray-400">
                                    <span v-if="item.id === file.id" class="fn-eq inline-flex h-3 items-end gap-[2px]"><i></i><i></i><i></i></span>
                                    <template v-else>{{ i + 1 }}</template>
                                </span>
                                <span class="relative h-12 w-20 shrink-0 overflow-hidden rounded-lg bg-gray-200 dark:bg-gray-700">
                                    <img v-if="item.thumbnail_path" :src="route('files.thumbnail', item.id)" loading="lazy" class="h-full w-full object-cover" alt="" />
                                    <MusicalNoteIcon v-else-if="kind(item) === 'audio'" class="absolute inset-0 m-auto h-5 w-5 text-gray-400" />
                                    <PlayIcon v-else class="absolute inset-0 m-auto h-5 w-5 text-gray-400" />
                                    <span v-if="item.id !== file.id" class="absolute inset-0 hidden items-center justify-center bg-black/40 group-hover:flex">
                                        <PlayIcon class="h-5 w-5 text-white" />
                                    </span>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span
                                        dir="auto"
                                        class="line-clamp-2 block text-sm"
                                        :class="item.id === file.id ? 'font-semibold text-[var(--accent-700)] dark:text-[var(--accent-500)]' : 'text-gray-700 dark:text-gray-200'"
                                    >{{ item.name }}</span>
                                    <span v-if="item.id === file.id" class="text-[11px] font-medium text-[var(--accent-600)] dark:text-[var(--accent-500)]">{{ t('player.now_playing', 'يُعرض الآن') }}</span>
                                    <span v-else-if="item.human_size" class="text-[11px] text-gray-400">{{ item.human_size }}</span>
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </Modal>
</template>

<style>
/* Zoom button fades with Plyr's controls; fullscreen takes the whole frame. */
.fn-player:has(.plyr--hide-controls) .fn-zoom {
    opacity: 0;
    pointer-events: none;
}
.fn-zoom {
    transition: opacity 0.25s;
}
.fn-player:fullscreen,
.fn-player.plyr--fullscreen-fallback {
    max-height: none;
    border-radius: 0;
}
.fn-player:fullscreen .plyr,
.fn-player.plyr--fullscreen-fallback .plyr,
.fn-player:fullscreen video,
.fn-player.plyr--fullscreen-fallback video {
    max-height: 100% !important;
    height: 100%;
    border-radius: 0;
}
.fn-player.plyr--fullscreen-fallback {
    position: fixed;
    inset: 0;
    z-index: 10000000;
}

/* Player look: accent-coloured timeline, glassy big play button, rounded menus. */
.fn-player .plyr {
    --plyr-color-main: var(--accent-500, #3b82f6);
    --plyr-video-control-color: #fff;
    --plyr-video-controls-background: linear-gradient(transparent, rgba(0, 0, 0, 0.85));
    --plyr-range-track-height: 4px;
    --plyr-range-thumb-height: 14px;
    --plyr-control-radius: 10px;
    --plyr-menu-radius: 12px;
    --plyr-menu-background: rgba(24, 28, 35, 0.96);
    --plyr-menu-color: #f3f4f6;
    --plyr-menu-back-border-color: rgba(255, 255, 255, 0.1);
    --plyr-tooltip-background: rgba(24, 28, 35, 0.96);
    --plyr-tooltip-color: #fff;
    --plyr-tooltip-radius: 8px;
    --plyr-badge-background: var(--accent-600, #2563eb);
    border-radius: 0.75rem;
    font-family: inherit;
}
.fn-player .plyr__control--overlaid {
    padding: 22px;
    background: rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(8px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
    transition: transform 0.2s ease, background 0.2s ease;
}
.fn-player .plyr__control--overlaid:hover {
    background: var(--accent-600, #2563eb);
    transform: translate(-50%, -50%) scale(1.06);
}
.fn-player .plyr__control--overlaid svg {
    width: 26px;
    height: 26px;
}
.fn-player .plyr--video .plyr__controls {
    padding: 28px 12px 10px;
    gap: 2px;
}
.fn-player .plyr__progress input[type='range'] {
    transition: height 0.15s;
}
.fn-player .plyr__progress__marker {
    background: #fbbf24;
    width: 4px;
}
.fn-player .plyr__captions .plyr__caption {
    border-radius: 6px;
    padding: 0.2em 0.6em;
    background: rgba(0, 0, 0, 0.75);
}
.fn-pop {
    animation: fn-pop 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes fn-pop {
    from { opacity: 0; transform: scale(0.92); }
    to { opacity: 1; transform: scale(1); }
}
/* Phones: the timeline on its own full-width row above the buttons, like YouTube. */
@media (max-width: 640px) {
    .fn-player .plyr--video .plyr__controls {
        flex-wrap: wrap;
        padding: 20px 8px 6px;
        row-gap: 2px;
    }
    .fn-player .plyr__controls .plyr__progress__container {
        order: -1;
        flex: 1 0 100%;
        margin: 0 4px 2px;
        padding: 0;
    }
    /* play and the time on the left, the other buttons on the right */
    .fn-player .plyr__controls .plyr__controls__item:first-child {
        margin-right: 0;
    }
    .fn-player .plyr__controls .plyr__time--current {
        margin-right: auto;
    }
}
/* Little "now playing" bars in the queue. */
.fn-eq i {
    display: block;
    width: 3px;
    background: var(--accent-500, #3b82f6);
    border-radius: 1px;
    animation: fn-eq 0.9s ease-in-out infinite;
}
.fn-eq i:nth-child(2) {
    animation-delay: 0.2s;
}
.fn-eq i:nth-child(3) {
    animation-delay: 0.4s;
}
@keyframes fn-eq {
    0%, 100% { height: 30%; }
    50% { height: 100%; }
}
</style>
