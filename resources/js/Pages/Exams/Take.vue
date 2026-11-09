<script setup>
import RichText from '@/Components/Exams/RichText.vue';
import DragText from '@/Components/Exams/DragText.vue';
import DragImage from '@/Components/Exams/DragImage.vue';
import AudioRecorder from '@/Components/Exams/AudioRecorder.vue';
import DrawingPad from '@/Components/Exams/DrawingPad.vue';
import Calculator from '@/Components/Exams/Calculator.vue';
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import { useTrans } from '@/Composables/useTrans';
import { ClockIcon, FlagIcon, ChevronLeftIcon, ChevronRightIcon, ArrowUpIcon, ArrowDownIcon, CheckIcon, CalculatorIcon, PencilSquareIcon, HandRaisedIcon, StopIcon, SpeakerWaveIcon, LockClosedIcon, BellAlertIcon, XMarkIcon, LanguageIcon, BookOpenIcon } from '@heroicons/vue/24/outline';
import OnScreenKeyboard from '@/Components/Exams/OnScreenKeyboard.vue';

const props = defineProps({ attempt: Object, exam: Object, questions: Array, answers: Object, offline_token: String, hall: { type: Object, default: () => ({}) } });

// ---- The hall: a locked attempt (left the page / opened again) waits for the invigilator's code ----
const locked = ref(!!props.hall.locked);
const help = ref(!!props.hall.help);
const unlockCode = ref('');
const unlockError = ref('');
async function unlockAttempt() {
    unlockError.value = '';
    try {
        await window.axios.post(route('exams.attempts.unlock', props.attempt.id), { code: unlockCode.value });
        locked.value = false;
        unlockCode.value = '';
        resend();
    } catch (e) {
        unlockError.value = e.response?.data?.message ?? t('exam.unlock_failed', 'تعذّر الفتح.');
    }
}
async function toggleHelp() {
    help.value = (await window.axios.post(route('exams.attempts.help', props.attempt.id), { on: !help.value })).data.help;
}

// ---- Reading: font, size and direction, kept on this device ----
const PREFS = 'exam-reading';
const reading = reactive(Object.assign({ font: '', size: 100, dir: '' }, (() => { try { return JSON.parse(localStorage.getItem(PREFS) || '{}'); } catch { return {}; } })()));
const FONTS = [['', 'افتراضي'], ['Tahoma, sans-serif', 'Tahoma'], ['Arial, sans-serif', 'Arial'], ["'Times New Roman', serif", 'Times'], ["'Segoe UI', sans-serif", 'Segoe UI'], ["'Courier New', monospace", 'Courier']];
function saveReading() { try { localStorage.setItem(PREFS, JSON.stringify(reading)); } catch {} }
const readingStyle = computed(() => ({ fontFamily: reading.font || undefined, '--rs': reading.size / 100 }));
const readingOpen = ref(false);
const oskOpen = ref(false);
// Open-book: the allowed files, read beside the questions.
const bookOpen = ref(false);
const bookIndex = ref(0);
const { t } = useTrans();

// ---- Answers (kept here, saved as they change) ----
const state = reactive({});
const touched = reactive({});
const flags = reactive({});
const checks = reactive({});   // practice: the result of «check», once per question
for (const q of props.questions) {
    const saved = props.answers?.[q.id] ?? {};
    flags[q.id] = !!saved.flagged;
    if (saved.checked) checks[q.id] = saved.checked;
    const r = saved.response ?? {};
    if (q.type === 'multiple') state[q.id] = { choices: r.choices ?? [] };
    else if (q.type === 'matching' || q.type === 'classify') state[q.id] = { pairs: r.pairs ?? {} };
    else if (['upload', 'audio', 'drawing'].includes(q.type)) state[q.id] = { name: r.name ?? null, size: r.size ?? null };
    else if (q.type === 'drag_text') state[q.id] = { blanks: { ...(r.blanks ?? {}) } };
    else if (q.type === 'drag_image') state[q.id] = { places: { ...(r.places ?? {}) } };
    else if (q.type === 'hotspot') state[q.id] = { x: r.x ?? null, y: r.y ?? null };
    else if (q.type === 'ordering') {
        state[q.id] = { order: r.order?.length ? r.order : q.choices.map((c) => c.id) };
        touched[q.id] = !!r.order?.length;
    }
    else if (q.type === 'numeric' || q.type === 'calculated') state[q.id] = { value: r.value ?? '' };
    else if (q.type === 'cloze') state[q.id] = { blanks: { ...(r.blanks ?? {}) } };
    else if (q.type === 'short' || q.type === 'essay') state[q.id] = { text: r.text ?? '' };
    else state[q.id] = { choice: r.choice ?? null };
}

const graded = computed(() => props.questions.filter((q) => q.type !== 'description'));
const number = (q) => graded.value.indexOf(q) + 1;
const answered = (q) => {
    const s = state[q.id];
    if (!s) return false;
    if (q.type === 'multiple') return s.choices.length > 0;
    if (q.type === 'matching' || q.type === 'classify') return Object.keys(s.pairs).length > 0;
    if (['upload', 'audio', 'drawing'].includes(q.type)) return !!s.name;
    if (q.type === 'drag_text') return Object.keys(s.blanks).length > 0;
    if (q.type === 'drag_image') return Object.keys(s.places).length > 0;
    if (q.type === 'hotspot') return s.x !== null;
    if (q.type === 'ordering') return touched[q.id];
    if (q.type === 'numeric' || q.type === 'calculated') return String(s.value).trim() !== '';
    if (q.type === 'cloze') return Object.values(s.blanks).some((v) => String(v ?? '').trim() !== '');
    if (q.type === 'short' || q.type === 'essay') return s.text.trim() !== '';
    return s.choice !== null;
};
const answeredCount = computed(() => graded.value.filter(answered).length);
// The summary before submitting: what is left, and a way straight to it.
const unansweredList = computed(() => props.questions.map((q, i) => ({ q, i })).filter(({ q }) => q.type !== 'description' && !answered(q)));
const flaggedList = computed(() => props.questions.map((q, i) => ({ q, i })).filter(({ q }) => flags[q.id]));

// ---- Saving: every change is kept on this device first, then sent; what could not be sent
// is sent again when the network is back (a dropped Wi-Fi never loses an answer). ----
const saving = ref(0);
const failed = ref(false);
const timers = {};
const STORE = 'exam-attempt-' + props.attempt.id;
const pending = reactive(new Set());
function keepLocally() {
    try {
        localStorage.setItem(STORE, JSON.stringify({ at: Date.now(), state, flags, pending: [...pending] }));
    } catch { /* private mode or full: the server copy still counts */ }
}
// Answers this device kept but the server never got (the page was closed while offline).
try {
    const kept = JSON.parse(localStorage.getItem(STORE) || 'null');
    for (const id of kept?.pending ?? []) {
        if (kept.state?.[id] !== undefined && props.questions.some((q) => q.id === Number(id))) {
            state[id] = kept.state[id];
            flags[id] = !!kept.flags?.[id];
            pending.add(Number(id));
        }
    }
} catch { /* nothing kept */ }

async function send(q) {
    saving.value++;
    try {
        const res = await window.axios.post(route('exams.attempts.save', props.attempt.id), { question_id: q.id, response: state[q.id], flagged: flags[q.id] });
        pending.delete(q.id);
        failed.value = pending.size > 0;
        if (res.data.seconds_left !== null && res.data.seconds_left !== undefined) secondsLeft.value = res.data.seconds_left;
    } catch (e) {
        if (e.response?.status === 409) return finish();
        if (e.response?.status === 423) locked.value = true;
        failed.value = true;
    } finally {
        saving.value--;
        keepLocally();
    }
}
function save(q, delay = 0) {
    pending.add(q.id);
    keepLocally();
    clearTimeout(timers[q.id]);
    timers[q.id] = setTimeout(() => send(q), delay);
}
async function resend() {
    for (const id of [...pending]) {
        const q = props.questions.find((x) => x.id === id);
        if (!q) { pending.delete(id); continue; }
        clearTimeout(timers[id]);
        await send(q);
        if (pending.has(id)) break;   // still offline: try again later
    }
}
const online = ref(navigator.onLine);
const onOnline = () => { online.value = true; resend(); };
const onOffline = () => (online.value = false);

// Last resort: the invigilator uploads this file if the network does not come back.
function emergencyFile() {
    const data = {
        kind: 'examnest-offline-answers', version: 1, attempt: props.attempt.id, token: props.offline_token,
        saved_at: new Date().toISOString(), answers: Object.fromEntries(props.questions.map((q) => [q.id, { response: state[q.id], flagged: !!flags[q.id] }])),
    };
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([JSON.stringify(data)], { type: 'application/json' }));
    a.download = `answers-attempt-${props.attempt.id}.json`;
    a.click();
}
function pair(q, itemId, value) {
    const target = Number(value);
    if (target) state[q.id].pairs[itemId] = target;
    else delete state[q.id].pairs[itemId];
    save(q);
}
function toggleMultiple(q, id) {
    const list = state[q.id].choices;
    const i = list.indexOf(id);
    i >= 0 ? list.splice(i, 1) : list.push(id);
    save(q);
}
function move(q, i, step) {
    const order = state[q.id].order;
    const j = i + step;
    if (j < 0 || j >= order.length) return;
    [order[i], order[j]] = [order[j], order[i]];
    touched[q.id] = true;
    save(q, 300);
}
// A file question: sent at once, the server keeps it as the answer.
const uploading = ref(null);
const uploadError = ref('');
async function upload(q, event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (file) sendFile(q, file);
}
async function sendFile(q, file) {
    if (q.max_mb && file.size > q.max_mb * 1024 * 1024) { uploadError.value = t('exam.file_too_big', 'الملف أكبر من المسموح.'); return; }
    uploading.value = q.id;
    uploadError.value = '';
    const form = new FormData();
    form.append('question_id', q.id);
    form.append('file', file);
    try {
        const res = await window.axios.post(route('exams.attempts.upload', props.attempt.id), form);
        state[q.id] = { name: res.data.name, size: res.data.size };
    } catch (e) {
        if (e.response?.status === 409) return finish();
        uploadError.value = e.response?.data?.errors?.file?.[0] ?? e.response?.data?.message ?? t('exam.upload_failed', 'تعذّر رفع الملف.');
    } finally {
        uploading.value = null;
    }
}
const size = (b) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.ceil(b / 1024) + ' KB');
// A picture question: where the student clicked, in % of the picture.
function pick(q, event) {
    if (checks[q.id]) return;
    const box = event.currentTarget.getBoundingClientRect();
    state[q.id] = { x: Math.round(((event.clientX - box.left) / box.width) * 1000) / 10, y: Math.round(((event.clientY - box.top) / box.height) * 1000) / 10 };
    save(q);
}
// Practice: check one answer now; it cannot change afterwards.
const CHECKABLE = ['single', 'multiple', 'true_false', 'short', 'numeric', 'matching', 'ordering', 'cloze', 'classify', 'hotspot', 'calculated', 'drag_text', 'drag_image'];
const canCheck = (q) => props.exam.practice && CHECKABLE.includes(q.type);
const checking = ref(null);
async function check(q) {
    if (checks[q.id] || !answered(q)) return;
    clearTimeout(timers[q.id]);
    checking.value = q.id;
    try {
        const res = await window.axios.post(route('exams.attempts.check', props.attempt.id), { question_id: q.id, response: state[q.id] });
        checks[q.id] = res.data;
    } catch (e) {
        if (e.response?.status === 409) return finish();
        failed.value = true;
    } finally {
        checking.value = null;
    }
}
const choiceText = (q, id) => q.choices.find((c) => c.id === id)?.body ?? '';
const words = (text) => (text.trim() ? text.trim().split(/\s+/).length : 0);

// ---- Navigation: one question at a time ----
// Sequential exams start where the student got to, and never go back.
const index = ref(props.exam.sequential ? Math.min(props.exam.position ?? 0, props.questions.length - 1) : 0);
const furthest = ref(props.exam.position ?? 0);
const canGo = (i) => !props.exam.sequential || i >= furthest.value;
const current = computed(() => props.questions[index.value]);
function go(i) {
    const to = Math.max(0, Math.min(props.questions.length - 1, i));
    if (!canGo(to)) return;
    if (props.exam.sequential && to > furthest.value) {
        const q = props.questions[index.value];
        if (q && pending.has(q.id)) { clearTimeout(timers[q.id]); send(q); }
        furthest.value = to;
        window.axios.post(route('exams.attempts.advance', props.attempt.id), { to }).catch(() => {});
    }
    index.value = to;
    window.scrollTo({ top: 0 });
}

// ---- Tools: calculator, scratch paper (stays on this device), reading aloud ----
const calcOpen = ref(false);
const padOpen = ref(false);
const scratch = ref((() => { try { return localStorage.getItem('exam-scratch-' + props.attempt.id) || ''; } catch { return ''; } })());
const keepScratch = () => { try { localStorage.setItem('exam-scratch-' + props.attempt.id, scratch.value); } catch {} };
const speaking = ref(false);
function readAloud(q) {
    if (!('speechSynthesis' in window)) return;
    if (speaking.value) { speechSynthesis.cancel(); speaking.value = false; return; }
    const div = document.createElement('div');
    div.innerHTML = q.html ? q.body : '';
    const text = [q.html ? div.textContent : q.body, ...(q.choices ?? []).map((c, i) => `${i + 1}. ${c.body}`)].join('. ');
    const u = new SpeechSynthesisUtterance(text);
    u.lang = /[\u0600-\u06FF]/.test(text) ? 'ar' : 'en';
    u.onend = () => (speaking.value = false);
    speaking.value = true;
    speechSynthesis.speak(u);
}
function toggleFlag(q) {
    flags[q.id] = !flags[q.id];
    save(q);
}

// ---- Timer (the server's deadline is what counts) ----
const secondsLeft = ref(props.attempt.seconds_left);
let tick;
const clock = computed(() => {
    if (secondsLeft.value === null) return null;
    const s = Math.max(0, secondsLeft.value);
    // An assignment due in days: the date and the days left, not a ticking clock.
    if (s >= 86400) {
        const days = Math.floor(s / 86400);
        const due = props.exam.deadline ? new Date(props.exam.deadline).toLocaleString(document.documentElement.lang || 'ar', { dateStyle: 'medium', timeStyle: 'short' }) : '';
        return t('exam.due_in_days', ':d يوم — حتى :at', { d: days, at: due });
    }
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
});

// ---- Submitting ----
const confirmOpen = ref(false);
const submitting = ref(false);
async function finish(closedByServer = false) {
    if (submitting.value) return;
    submitting.value = true;
    if (closedByServer === true) return router.visit(route('exams.attempts.result', props.attempt.id));
    // Let pending saves land first.
    for (const id of Object.keys(timers)) clearTimeout(timers[id]);
    await Promise.allSettled(props.questions.map((q) => window.axios.post(route('exams.attempts.save', props.attempt.id), { question_id: q.id, response: state[q.id], flagged: flags[q.id] }).catch(() => null)));
    router.post(route('exams.attempts.submit', props.attempt.id), {}, { onSuccess: () => { try { localStorage.removeItem(STORE); } catch {} } });
}

// ---- Heartbeat: the invigilator sees the student connected; more time and announcements come back ----
const announcements = ref([]);
const seenNotes = new Set();
async function ping() {
    try {
        const res = await window.axios.post(route('exams.attempts.ping', props.attempt.id), { client_time: Date.now() });
        if (res.data.closed) return finish(true);
        locked.value = !!res.data.locked;
        help.value = !!res.data.help;
        if (res.data.seconds_left !== null && res.data.seconds_left !== undefined) secondsLeft.value = res.data.seconds_left;
        for (const a of res.data.announcements ?? []) {
            if (!seenNotes.has(a.id)) { seenNotes.add(a.id); announcements.value.push(a); }
        }
    } catch (e) { /* the next ping, or the autosave, will tell */ }
}
let heartbeat;

// ---- What the browser reports (shown to the examiner) ----
function report(type, meta = null) {
    window.axios.post(route('exams.attempts.event', props.attempt.id), { type, meta }).catch(() => {});
}
const onVisibility = () => (document.visibilityState === 'hidden' ? report('hidden') : setTimeout(ping, 500));
const onBlur = () => report('blur');
const onPaste = () => report('paste');

// ---- Protection: the student's name across the page, and no copying the questions out ----
const watermarkStyle = computed(() => {
    if (!props.hall.protect || !props.hall.watermark) return null;
    const esc = props.hall.watermark.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]));
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="340" height="170"><text x="170" y="85" text-anchor="middle" dominant-baseline="middle" transform="rotate(-25 170 85)" font-family="sans-serif" font-size="15" fill="rgba(110,110,110,0.14)">${esc}</text></svg>`;
    return { backgroundImage: `url("data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}")` };
});
const editable = (el) => !!el?.closest?.('input, textarea, [contenteditable="true"], canvas');
let copyReported = 0;
function guard(e) {
    if (editable(e.target)) return;
    e.preventDefault();
    if ((e.type === 'copy' || e.type === 'cut') && Date.now() - copyReported > 30000) { copyReported = Date.now(); report('copy'); }
}
const GUARDED = ['copy', 'cut', 'contextmenu', 'dragstart'];

// ---- Warnings before the time runs out (5 and 1 minutes), with a sound the student can turn off ----
const warning = ref(null);
const warnSound = ref((() => { try { return localStorage.getItem('exam-warn-sound') !== '0'; } catch { return true; } })());
function toggleWarnSound() { warnSound.value = !warnSound.value; try { localStorage.setItem('exam-warn-sound', warnSound.value ? '1' : '0'); } catch {} }
function beep() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        [0, 0.5].forEach((at) => {
            const o = ctx.createOscillator(); const g = ctx.createGain();
            o.frequency.value = 880; g.gain.value = 0.12; o.connect(g); g.connect(ctx.destination);
            o.start(ctx.currentTime + at); o.stop(ctx.currentTime + at + 0.3);
        });
        setTimeout(() => ctx.close(), 1500);
    } catch {}
}
const warned = new Set();
function checkWarnings() {
    const s = secondsLeft.value;
    if (s === null) return;
    for (const m of [5, 1]) {
        if (s > m * 60) { warned.delete(m); continue; }   // time was given back: warn again later
        if (s > m * 60 - 30 && !warned.has(m)) {
            warned.add(m);
            warning.value = m;
            if (warnSound.value) beep();
            setTimeout(() => { if (warning.value === m) warning.value = null; }, 20000);
        }
    }
}
const onLeave = (e) => { if (saving.value) { e.preventDefault(); e.returnValue = ''; } };

onMounted(() => {
    heartbeat = setInterval(() => { ping(); if (pending.size) resend(); }, 20000);
    ping();
    if (pending.size) resend();
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    tick = setInterval(() => {
        if (secondsLeft.value === null) return;
        secondsLeft.value--;
        checkWarnings();
        if (secondsLeft.value <= 0) {
            clearInterval(tick);
            finish();
        }
    }, 1000);
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('blur', onBlur);
    document.addEventListener('paste', onPaste);
    window.addEventListener('beforeunload', onLeave);
    if (props.hall.protect) GUARDED.forEach((ev) => document.addEventListener(ev, guard));
});
onBeforeUnmount(() => {
    clearInterval(tick);
    clearInterval(heartbeat);
    window.removeEventListener('online', onOnline);
    window.removeEventListener('offline', onOffline);
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('blur', onBlur);
    document.removeEventListener('paste', onPaste);
    window.removeEventListener('beforeunload', onLeave);
    GUARDED.forEach((ev) => document.removeEventListener(ev, guard));
});
</script>

<template>
    <Head :title="exam.title" />
    <div class="min-h-screen bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100" :class="{ 'exam-protect': hall.protect }" :dir="exam.dir" :lang="exam.lang">
        <div v-if="watermarkStyle" class="pointer-events-none fixed inset-0 z-[5] print:hidden" :style="watermarkStyle" aria-hidden="true" />
        <header class="sticky top-0 z-10 flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="min-w-0 flex-1 truncate font-semibold">{{ exam.title }}</h1>
            <span class="hidden text-xs text-gray-500 sm:inline">{{ answeredCount }} / {{ graded.length }} {{ t('exam.answered', 'مُجاب') }}</span>
            <span class="text-xs" :class="failed || !online ? 'text-red-600' : 'text-gray-400'">
                {{ failed || !online ? t('exam.kept_on_device', 'بلا اتصال — :n محفوظة على هذا الجهاز وستُرسل تلقائياً', { n: pending.size }) : saving ? t('exam.saving', 'يحفظ…') : t('exam.saved', 'محفوظ') }}
            </span>
            <button v-if="failed || !online" @click="emergencyFile" class="rounded border border-red-300 px-2 py-0.5 text-xs text-red-700" :title="t('exam.emergency_hint', 'إذا لم يعد الاتصال: نزّل هذا الملف وأعطه للمراقب')">{{ t('exam.emergency_file', 'ملف طارئ') }}</button>
            <span v-if="clock" class="flex items-center gap-1 rounded-lg px-2.5 py-1 font-mono text-sm font-semibold" :class="secondsLeft < 300 ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : 'bg-gray-100 dark:bg-gray-700'" role="timer" aria-live="off">
                <ClockIcon class="h-4 w-4" /> {{ clock }}
            </span>
            <span v-if="hall.variant" class="rounded bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-800 dark:bg-violet-900/40 dark:text-violet-200" :title="t('exam.variant', 'نسخة الامتحان')">{{ t('exam.variant_short', 'نسخة') }} {{ hall.variant }}</span>
            <span v-if="hall.computer" class="hidden rounded bg-gray-100 px-2 py-0.5 font-mono text-xs sm:inline dark:bg-gray-700" :title="t('exam.your_pc', 'حاسبتك')">{{ hall.computer }}</span>
            <button v-if="exam.calculator && exam.calculator !== 'none'" @click="calcOpen = !calcOpen" class="rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-600" :title="t('exam.calculator', 'آلة حاسبة')"><CalculatorIcon class="h-4 w-4" /></button>
            <button @click="padOpen = !padOpen" class="rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-600" :title="t('exam.scratch', 'مسودة')"><PencilSquareIcon class="h-4 w-4" /></button>
            <button v-if="exam.resources?.length" @click="bookOpen = !bookOpen" class="flex items-center gap-1 rounded-lg border px-2 py-1 text-sm" :class="bookOpen ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-300 dark:border-gray-600'" :title="t('exam.allowed_files', 'المصادر المسموحة')"><BookOpenIcon class="h-4 w-4" /></button>
            <button @click="oskOpen = !oskOpen" class="rounded-lg border px-2 py-1 text-sm" :class="oskOpen ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-300 dark:border-gray-600'" :title="t('exam.osk', 'لوحة مفاتيح على الشاشة (عربي / English)')"><LanguageIcon class="h-4 w-4" /></button>
            <button @click="readingOpen = !readingOpen" class="rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-600" :title="t('exam.reading', 'الخط والاتجاه')">Aa</button>
            <button @click="toggleHelp" class="rounded-lg px-2.5 py-1 text-sm" :class="help ? 'bg-amber-400 font-semibold text-amber-950' : 'border border-gray-300 dark:border-gray-600'" :title="t('exam.help_hint', 'يظهر طلبك عند المراقب')"><HandRaisedIcon class="inline h-4 w-4 align-text-bottom" /> {{ help ? t('exam.help_sent', 'طُلبت المساعدة') : t('exam.ask_help', 'مساعدة') }}</button>
            <button @click="confirmOpen = true" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)]">{{ t('exam.submit', 'تسليم') }}</button>
        </header>
        <div v-if="readingOpen" class="flex flex-wrap items-center gap-3 border-b border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
            <label class="flex items-center gap-1">{{ t('exam.font', 'الخط') }}
                <select v-model="reading.font" @change="saveReading" class="rounded border-gray-300 py-0.5 text-sm dark:border-gray-600 dark:bg-gray-700"><option v-for="[v, l] in FONTS" :key="l" :value="v">{{ l === 'افتراضي' ? t('exam.font_default', 'افتراضي') : l }}</option></select></label>
            <span class="flex items-center gap-1">{{ t('exam.font_size', 'الحجم') }}
                <button @click="reading.size = Math.max(80, reading.size - 10); saveReading()" class="rounded border border-gray-300 px-2 dark:border-gray-600">A−</button>
                <span class="w-12 text-center">{{ reading.size }}%</span>
                <button @click="reading.size = Math.min(200, reading.size + 10); saveReading()" class="rounded border border-gray-300 px-2 dark:border-gray-600">A+</button></span>
            <label class="flex items-center gap-1">{{ t('exam.text_dir', 'الاتجاه') }}
                <select v-model="reading.dir" @change="saveReading" class="rounded border-gray-300 py-0.5 text-sm dark:border-gray-600 dark:bg-gray-700">
                    <option value="">{{ t('exam.dir_auto_short', 'تلقائي') }}</option><option value="rtl">{{ t('exam.dir_rtl', 'من اليمين لليسار') }}</option><option value="ltr">{{ t('exam.dir_ltr', 'من اليسار لليمين') }}</option></select></label>
            <label class="flex cursor-pointer items-center gap-1"><input type="checkbox" :checked="warnSound" @change="toggleWarnSound" class="rounded" /> {{ t('exam.warn_sound', 'صوت التنبيه قبل نهاية الوقت') }}</label>
        </div>
        <FlashMessages />
        <div v-if="warning" class="mx-auto mt-3 max-w-6xl px-4" role="alert">
            <p class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold" :class="warning === 1 ? 'bg-red-600 text-white' : 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100'">
                <BellAlertIcon class="h-5 w-5" />
                <span class="flex-1">{{ warning === 1 ? t('exam.warn_1', 'بقيت دقيقة واحدة. يُسلَّم الامتحان تلقائياً عند انتهاء الوقت.') : t('exam.warn_5', 'بقيت 5 دقائق على نهاية الوقت.') }}</span>
                <button @click="warning = null" :aria-label="t('action.close', 'إغلاق')"><XMarkIcon class="h-4 w-4" /></button>
            </p>
        </div>
        <div v-if="announcements.length" class="mx-auto mt-3 max-w-6xl space-y-1 px-4" role="status" aria-live="polite">
            <p v-for="a in announcements" :key="a.id" class="flex items-start gap-2 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:bg-sky-900/40 dark:text-sky-100">
                <span class="font-semibold">{{ t('exam.announcement', 'من المراقب') }}:</span> <span class="flex-1" dir="auto">{{ a.body }}</span>
                <button @click="announcements.splice(announcements.indexOf(a), 1)" class="text-sky-500" :aria-label="t('action.close', 'إغلاق')"><XMarkIcon class="h-4 w-4" /></button>
            </p>
        </div>

        <div class="mx-auto grid max-w-6xl gap-4 p-4 lg:grid-cols-4">
            <!-- Question map -->
            <nav class="order-2 rounded-xl border border-gray-200 bg-white p-3 lg:order-1 dark:border-gray-700 dark:bg-gray-800" :aria-label="t('exam.questions', 'الأسئلة')">
                <div class="grid grid-cols-6 gap-1.5 lg:grid-cols-5">
                    <button v-for="(q, i) in questions" :key="q.id" @click="go(i)" :disabled="!canGo(i)"
                        class="relative flex h-9 items-center justify-center rounded-md border text-sm"
                        :class="[i === index ? 'border-[var(--accent-600)] ring-2 ring-[var(--accent-500)]' : 'border-gray-200 dark:border-gray-600',
                                 q.type === 'description' ? 'text-gray-400' : answered(q) ? 'bg-[var(--accent-50)] font-semibold text-[var(--accent-700)] dark:bg-gray-700 dark:text-white' : '']">
                        {{ q.type === 'description' ? 'i' : number(q) }}
                        <FlagIcon v-if="flags[q.id]" class="absolute -top-1 h-3.5 w-3.5 text-amber-500 ltr:-right-1 rtl:-left-1" />
                    </button>
                </div>
            </nav>

            <!-- The question -->
            <main class="exam-reading order-1 space-y-4 lg:order-2 lg:col-span-3" :style="readingStyle" :dir="reading.dir || undefined">
                <article v-if="current" class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-3 flex items-center justify-between gap-2 text-xs text-gray-500">
                        <span v-if="current.type !== 'description'">{{ t('exam.question', 'السؤال') }} {{ number(current) }} · {{ current.points }} {{ t('exam.points', 'درجة') }}</span>
                        <span v-else />
                        <span class="flex-1" />
                        <button @click="readAloud(current)" class="hover:text-gray-700" :title="t('exam.read_aloud', 'قراءة السؤال بصوت')"><StopIcon v-if="speaking" class="h-4 w-4" /><SpeakerWaveIcon v-else class="h-4 w-4" /></button>
                        <button v-if="current.type !== 'description'" @click="toggleFlag(current)" class="flex items-center gap-1" :class="flags[current.id] ? 'text-amber-600' : 'hover:text-gray-700'">
                            <FlagIcon class="h-4 w-4" /> {{ t('exam.flag', 'راجع لاحقاً') }}
                        </button>
                    </div>
                    <RichText v-if="current.type !== 'cloze' && current.type !== 'drag_text'" :text="current.body" :html="current.html" class="text-base leading-relaxed" />
                    <!-- Fill in the blanks: the text with its boxes and lists in place -->
                    <p v-else class="whitespace-pre-line text-base leading-loose" dir="auto">
                        <template v-for="(part, k) in current.parts" :key="k">
                            <span v-if="part.blank === undefined">{{ part.text }}</span>
                            <select v-else-if="part.kind === 'select'" v-model="state[current.id].blanks[part.blank]" @change="save(current)" :disabled="!!checks[current.id]"
                                :aria-label="t('exam.blank', 'فراغ') + ' ' + (part.blank + 1)"
                                class="mx-1 inline-block rounded-md border-gray-300 py-0.5 text-sm dark:border-gray-600 dark:bg-gray-700">
                                <option :value="undefined">—</option>
                                <option v-for="o in part.options" :key="o" :value="o">{{ o }}</option>
                            </select>
                            <input v-else v-model="state[current.id].blanks[part.blank]" @input="save(current, 800)" type="text" dir="auto" :disabled="!!checks[current.id]"
                                :aria-label="t('exam.blank', 'فراغ') + ' ' + (part.blank + 1)"
                                class="mx-1 inline-block w-36 rounded-md border-gray-300 py-0.5 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        </template>
                    </p>

                    <fieldset class="mt-4" :disabled="!!checks[current.id]">
                        <!-- One answer / true-false -->
                        <fieldset v-if="['single', 'true_false'].includes(current.type)" class="space-y-2">
                            <label v-for="c in current.choices" :key="c.id" class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                                :class="state[current.id].choice === c.id ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-200 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700'">
                                <input type="radio" :name="'q' + current.id" :value="c.id" v-model="state[current.id].choice" @change="save(current)" class="text-[var(--accent-600)]" />
                                <RichText :text="c.body" tag="span" />
                            </label>
                        </fieldset>

                        <!-- Several answers -->
                        <fieldset v-else-if="current.type === 'multiple'" class="space-y-2">
                            <p class="text-xs text-gray-500">{{ t('exam.pick_all', 'اختر كل الإجابات الصحيحة.') }}</p>
                            <label v-for="c in current.choices" :key="c.id" class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                                :class="state[current.id].choices.includes(c.id) ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-200 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700'">
                                <input type="checkbox" :checked="state[current.id].choices.includes(c.id)" @change="toggleMultiple(current, c.id)" class="rounded text-[var(--accent-600)]" />
                                <RichText :text="c.body" tag="span" />
                            </label>
                        </fieldset>

                        <input v-else-if="current.type === 'short'" v-model="state[current.id].text" @input="save(current, 800)" type="text" dir="auto"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" :placeholder="t('exam.your_answer', 'إجابتك')" />

                        <input v-else-if="current.type === 'numeric' || current.type === 'calculated'" v-model="state[current.id].value" @input="save(current, 800)" type="text" inputmode="decimal" dir="ltr"
                            class="w-48 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" :placeholder="t('exam.number', 'رقم')" />

                        <div v-else-if="current.type === 'essay'">
                            <textarea v-model="state[current.id].text" @input="save(current, 1500)" rows="10" dir="auto" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" />
                            <p class="mt-1 text-xs text-gray-500">
                                {{ words(state[current.id].text) }} {{ t('exam.words', 'كلمة') }}
                                <span v-if="current.min_words"> · {{ t('exam.min', 'الأدنى') }} {{ current.min_words }}</span>
                                <span v-if="current.max_words"> · {{ t('exam.max', 'الأقصى') }} {{ current.max_words }}</span>
                            </p>
                        </div>

                        <!-- Matching and sorting into categories: a list per item -->
                        <div v-else-if="current.type === 'matching' || current.type === 'classify'" class="space-y-2">
                            <p v-if="current.type === 'classify'" class="text-xs text-gray-500">{{ t('exam.classify_hint', 'ضع كل عنصر في فئته.') }}</p>
                            <div v-for="item in current.items" :key="item.id" class="grid items-center gap-2 rounded-lg border border-gray-200 p-3 sm:grid-cols-2 dark:border-gray-600">
                                <RichText :text="item.body" tag="span" class="text-sm" />
                                <select :value="state[current.id].pairs[item.id] ?? ''" @change="pair(current, item.id, $event.target.value)"
                                    class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                                    <option value="">{{ t('exam.choose', '— اختر —') }}</option>
                                    <option v-for="target in current.targets" :key="target.id" :value="target.id">{{ target.body }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- A file -->
                        <div v-else-if="current.type === 'upload'" class="space-y-2">
                            <label class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed border-gray-300 p-6 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                <input type="file" class="sr-only" :accept="current.types ? current.types.split(',').map((x) => '.' + x).join(',') : undefined" @change="upload(current, $event)" :disabled="uploading === current.id" />
                                <span v-if="uploading === current.id">{{ t('exam.uploading', 'يرفع…') }}</span>
                                <span v-else>{{ state[current.id].name ? t('exam.replace_file', 'استبدال الملف') : t('exam.choose_file', 'اختر ملفاً') }}</span>
                                <span class="text-xs text-gray-400">{{ t('exam.max_size', 'الحد') }} {{ current.max_mb }} MB<template v-if="current.types"> · {{ current.types }}</template></span>
                            </label>
                            <p v-if="state[current.id].name" class="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-400" dir="auto"><CheckIcon class="h-4 w-4" /> {{ state[current.id].name }} ({{ size(state[current.id].size) }})</p>
                            <p v-if="uploadError" class="text-sm text-red-600">{{ uploadError }}</p>
                        </div>

                        <!-- Words into blanks -->
                        <DragText v-else-if="current.type === 'drag_text'" :parts="current.parts" :words="current.words" :disabled="!!checks[current.id]"
                            :model-value="state[current.id].blanks" @update:model-value="state[current.id].blanks = $event; save(current)" />

                        <!-- Labels onto a picture -->
                        <DragImage v-else-if="current.type === 'drag_image'" :image="current.image" :labels="current.labels" :disabled="!!checks[current.id]" :zones="checks[current.id]?.zones ?? []"
                            :model-value="state[current.id].places" @update:model-value="state[current.id].places = $event; save(current)" />

                        <!-- A spoken answer -->
                        <div v-else-if="current.type === 'audio'">
                            <AudioRecorder :key="current.id" :max-seconds="current.max_seconds" :saved="state[current.id].name" @recorded="sendFile(current, $event)" />
                            <p v-if="uploading === current.id" class="mt-1 text-xs text-gray-500">{{ t('exam.uploading', 'يرفع…') }}</p>
                            <p v-if="uploadError" class="mt-1 text-sm text-red-600">{{ uploadError }}</p>
                        </div>

                        <!-- Drawing / handwriting -->
                        <div v-else-if="current.type === 'drawing'">
                            <DrawingPad :key="current.id" :saved="state[current.id].name" @drawn="sendFile(current, $event)" />
                            <p v-if="uploadError" class="mt-1 text-sm text-red-600">{{ uploadError }}</p>
                        </div>

                        <!-- A place on a picture -->
                        <div v-else-if="current.type === 'hotspot'">
                            <p class="mb-2 text-xs text-gray-500">{{ t('exam.hotspot_hint', 'اضغط على المكان الصحيح في الصورة.') }}</p>
                            <div class="relative inline-block max-w-full cursor-crosshair select-none" @click="pick(current, $event)">
                                <img :src="current.image" alt="" class="block max-h-[70vh] max-w-full rounded-lg" draggable="false" />
                                <span v-for="(z, k) in checks[current.id]?.zones ?? []" :key="k" class="pointer-events-none absolute border-2 border-emerald-500 bg-emerald-400/20"
                                    :style="{ left: z.x + '%', top: z.y + '%', width: z.w + '%', height: z.h + '%' }" />
                                <span v-if="state[current.id].x !== null" class="pointer-events-none absolute h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-[var(--accent-600)] shadow"
                                    :style="{ left: state[current.id].x + '%', top: state[current.id].y + '%' }" />
                            </div>
                        </div>

                        <!-- Ordering: move items up and down (keyboard and screen readers too) -->
                        <ol v-else-if="current.type === 'ordering'" class="space-y-2">
                            <li v-for="(id, i) in state[current.id].order" :key="id" class="flex items-center gap-2 rounded-lg border border-gray-200 p-2.5 text-sm dark:border-gray-600">
                                <span class="w-6 text-center text-xs text-gray-400">{{ i + 1 }}</span>
                                <RichText :text="choiceText(current, id)" tag="span" class="flex-1" />
                                <button @click="move(current, i, -1)" :disabled="i === 0" class="rounded p-1 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" :aria-label="t('exam.move_up', 'إلى الأعلى')"><ArrowUpIcon class="h-4 w-4" /></button>
                                <button @click="move(current, i, 1)" :disabled="i === state[current.id].order.length - 1" class="rounded p-1 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" :aria-label="t('exam.move_down', 'إلى الأسفل')"><ArrowDownIcon class="h-4 w-4" /></button>
                            </li>
                        </ol>
                    </fieldset>

                    <!-- Practice: check now -->
                    <div v-if="canCheck(current)" class="mt-4">
                        <button v-if="!checks[current.id]" @click="check(current)" :disabled="!answered(current) || checking === current.id"
                            class="rounded-lg border border-[var(--accent-600)] px-4 py-1.5 text-sm text-[var(--accent-700)] disabled:opacity-40 dark:text-white">
                            {{ checking === current.id ? t('exam.checking', 'يتحقّق…') : t('exam.check', 'تحقّق') }}
                        </button>
                        <div v-else class="rounded-lg p-3 text-sm" :class="checks[current.id].fraction >= 1 ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200' : checks[current.id].fraction > 0 ? 'bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200' : 'bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-200'">
                            <p class="font-medium">{{ checks[current.id].fraction >= 1 ? t('exam.right', 'إجابة صحيحة') : checks[current.id].fraction > 0 ? t('exam.partly_right', 'صحيحة جزئياً') : t('exam.wrong', 'إجابة خاطئة') }}</p>
                            <p v-if="checks[current.id].correct && checks[current.id].correct.length" class="mt-1">{{ t('exam.correct_answer', 'الإجابة الصحيحة') }}: <span dir="auto">{{ checks[current.id].correct.join(' · ') }}</span></p>
                            <p v-if="checks[current.id].feedback" class="mt-1 whitespace-pre-line" dir="auto">{{ checks[current.id].feedback }}</p>
                        </div>
                    </div>
                </article>

                <div class="flex justify-between">
                    <button v-if="!exam.sequential" @click="go(index - 1)" :disabled="index === 0" class="flex items-center gap-1 rounded-lg border border-gray-300 px-4 py-2 text-sm disabled:opacity-40 dark:border-gray-600">
                        <ChevronRightIcon class="h-4 w-4 ltr:rotate-180" /> {{ t('exam.previous', 'السابق') }}
                    </button>
                    <span v-if="exam.sequential" class="text-xs text-amber-600">{{ t('exam.no_going_back', 'لا رجوع إلى الأسئلة السابقة') }}</span>
                    <button v-if="index < questions.length - 1" @click="go(index + 1)" class="flex items-center gap-1 rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">
                        {{ t('exam.next', 'التالي') }} <ChevronLeftIcon class="h-4 w-4 ltr:rotate-180" />
                    </button>
                    <button v-else @click="confirmOpen = true" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white">
                        <CheckIcon class="h-4 w-4" /> {{ t('exam.submit', 'تسليم') }}
                    </button>
                </div>
            </main>
        </div>

        <div v-if="calcOpen || padOpen" class="fixed bottom-4 z-30 flex flex-col items-end gap-2 ltr:right-4 rtl:left-4">
            <Calculator v-if="calcOpen" :scientific="exam.calculator === 'scientific'" />
            <div v-if="padOpen" class="w-72 rounded-xl border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                <p class="mb-1 text-xs text-gray-500">{{ t('exam.scratch_hint', 'مسودة على هذا الجهاز فقط؛ لا تُرسل ولا تُصحَّح.') }}</p>
                <textarea v-model="scratch" @input="keepScratch" rows="8" dir="auto" class="w-full rounded border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
            </div>
        </div>

        <!-- Locked: the invigilator's code opens it again -->
        <div v-if="locked" class="fixed inset-0 z-40 flex items-center justify-center bg-gray-900/80 p-4" role="dialog" aria-modal="true">
            <form @submit.prevent="unlockAttempt" class="w-full max-w-sm space-y-4 rounded-xl bg-white p-6 text-center dark:bg-gray-800">
                <LockClosedIcon class="mx-auto h-12 w-12" />
                <p class="font-semibold">{{ t('exam.locked_title', 'المحاولة مقفلة') }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('exam.locked_text', 'خرجت من صفحة الامتحان أو فتحتها من جديد. اطلب من المراقب رمز المتابعة. إجاباتك محفوظة، والوقت مستمر.') }}</p>
                <input v-model="unlockCode" inputmode="numeric" autocomplete="off" dir="ltr" class="w-40 rounded-lg border-gray-300 text-center font-mono text-2xl tracking-[0.4em] dark:border-gray-600 dark:bg-gray-700" />
                <p v-if="unlockError" class="text-sm text-red-600">{{ unlockError }}</p>
                <div class="flex justify-center gap-2">
                    <button type="button" @click="toggleHelp" class="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><HandRaisedIcon class="inline h-4 w-4 align-text-bottom" /> {{ help ? t('exam.help_sent', 'طُلبت المساعدة') : t('exam.ask_help', 'مساعدة') }}</button>
                    <button :disabled="!unlockCode" class="rounded-lg bg-[var(--accent-600)] px-5 py-2 text-sm text-white disabled:opacity-40">{{ t('exam.continue', 'متابعة') }}</button>
                </div>
                <p v-if="clock" class="font-mono text-sm text-gray-500">{{ clock }}</p>
            </form>
        </div>

        <aside v-if="bookOpen && exam.resources?.length" class="fixed inset-y-0 end-0 z-30 flex w-full max-w-xl flex-col border-s border-gray-300 bg-white shadow-2xl dark:border-gray-600 dark:bg-gray-800">
            <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2 dark:border-gray-700">
                <BookOpenIcon class="h-5 w-5 text-gray-500" />
                <select v-model="bookIndex" class="min-w-0 flex-1 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700">
                    <option v-for="(r, k) in exam.resources" :key="k" :value="k">{{ r.name }}</option>
                </select>
                <button @click="bookOpen = false" :aria-label="t('action.close', 'إغلاق')"><XMarkIcon class="h-5 w-5" /></button>
            </div>
            <iframe :src="exam.resources[bookIndex].url + '#toolbar=0&navpanes=0'" class="min-h-0 w-full flex-1" :title="exam.resources[bookIndex].name" />
        </aside>
        <OnScreenKeyboard v-if="oskOpen" @close="oskOpen = false" />
        <div v-if="oskOpen" class="h-72" aria-hidden="true" />

        <Modal :show="confirmOpen" @close="confirmOpen = false" max-width="md">
            <div class="space-y-4 p-6" :dir="exam.dir">
                <h3 class="text-lg font-semibold">{{ t('exam.confirm_submit', 'تسليم الامتحان؟') }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ t('exam.answered_of', 'أجبت عن :a من :n.', { a: answeredCount, n: graded.length }) }}
                    <span v-if="answeredCount < graded.length" class="font-medium text-amber-600">{{ t('exam.unanswered_warn', 'بقيت أسئلة بلا إجابة.') }}</span>
                    {{ t('exam.no_changes_after', 'لا يمكن تعديل الإجابات بعد التسليم.') }}
                </p>
                <div v-if="unansweredList.length" class="space-y-1">
                    <p class="text-xs font-medium text-gray-500">{{ t('exam.left_unanswered', 'بلا إجابة') }} ({{ unansweredList.length }})</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="u in unansweredList" :key="u.q.id" type="button" :disabled="!canGo(u.i)" @click="confirmOpen = false; go(u.i)"
                            class="h-8 min-w-8 rounded-lg border border-amber-300 bg-amber-50 px-2 text-sm text-amber-800 disabled:opacity-40 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">{{ u.i + 1 }}</button>
                    </div>
                </div>
                <div v-if="flaggedList.length" class="space-y-1">
                    <p class="flex items-center gap-1 text-xs font-medium text-gray-500"><FlagIcon class="h-3.5 w-3.5" /> {{ t('exam.flagged_review', 'معلّمة للمراجعة') }} ({{ flaggedList.length }})</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="u in flaggedList" :key="u.q.id" type="button" :disabled="!canGo(u.i)" @click="confirmOpen = false; go(u.i)"
                            class="h-8 min-w-8 rounded-lg border border-sky-300 bg-sky-50 px-2 text-sm text-sky-800 disabled:opacity-40 dark:border-sky-700 dark:bg-sky-900/30 dark:text-sky-200">{{ u.i + 1 }}</button>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button @click="confirmOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button @click="finish" :disabled="submitting" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('exam.submit', 'تسليم') }}</button>
                </div>
            </div>
        </Modal>
    </div>
</template>

<style>
/* Student's chosen text size: Tailwind sizes are rem-based, so scale them inside the exam area */
.exam-reading { font-size: calc(1rem * var(--rs, 1)); }
.exam-reading .text-xs { font-size: calc(.75rem * var(--rs, 1)); line-height: calc(1rem * var(--rs, 1)); }
.exam-reading .text-sm { font-size: calc(.875rem * var(--rs, 1)); line-height: calc(1.25rem * var(--rs, 1)); }
.exam-reading .text-base { font-size: calc(1rem * var(--rs, 1)); line-height: calc(1.5rem * var(--rs, 1)); }
.exam-reading .text-lg { font-size: calc(1.125rem * var(--rs, 1)); line-height: calc(1.75rem * var(--rs, 1)); }
.exam-reading .text-xl { font-size: calc(1.25rem * var(--rs, 1)); line-height: calc(1.75rem * var(--rs, 1)); }
.exam-reading .text-2xl { font-size: calc(1.5rem * var(--rs, 1)); line-height: calc(2rem * var(--rs, 1)); }
.exam-reading input, .exam-reading textarea, .exam-reading select { font-size: inherit; }
/* Protected exams: the questions cannot be selected; the student's own answer fields still can */
.exam-protect { -webkit-user-select: none; user-select: none; }
.exam-protect input, .exam-protect textarea, .exam-protect [contenteditable="true"] { -webkit-user-select: text; user-select: text; }
</style>
