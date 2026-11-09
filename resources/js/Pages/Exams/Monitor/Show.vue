<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import ServerHealth from '@/Components/Exams/ServerHealth.vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, ArrowPathIcon, PrinterIcon, MegaphoneIcon, KeyIcon, ExclamationTriangleIcon, PauseCircleIcon, QrCodeIcon, CameraIcon, TableCellsIcon, MapIcon, HandRaisedIcon, ClockIcon, CheckIcon, XMarkIcon, LockClosedIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ exam: Object, data: Object, computers: { type: Array, default: () => [] } });
const { t } = useTrans();

// ---- Live rows: polled every 5 seconds (it is a LAN; no sockets needed) ----
const live = ref(props.data);
const lastUpdate = ref(Date.now());
const stale = ref(false);
async function refresh() {
    try {
        live.value = (await window.axios.get(route('exams.monitor.data', props.exam.id))).data;
        if ((live.value.counts.help ?? 0) > lastHelp) beep();
        lastHelp = live.value.counts.help ?? 0;
        lastUpdate.value = Date.now();
        stale.value = false;
    } catch {
        stale.value = true;
    }
}
let poll;
let tick;
const now = ref(Date.now());
onMounted(() => {
    poll = setInterval(refresh, 5000);
    tick = setInterval(() => (now.value = Date.now()), 1000);
});
onBeforeUnmount(() => { clearInterval(poll); clearInterval(tick); });

// Seconds left run down between polls.
const left = (r) => {
    if (r.attempt?.seconds_left == null) return null;
    return Math.max(0, r.attempt.seconds_left - Math.floor((now.value - lastUpdate.value) / 1000));
};
const clock = (s) => (s === null ? '—' : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`);
const since = (iso) => (iso ? Math.max(0, Math.round((now.value - new Date(iso).getTime()) / 1000)) : null);

const STATUS = {
    not_started: ['لم يبدأ', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
    working: ['يؤدّي', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'],
    disconnected: ['منقطع', 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'],
    time_up: ['انتهى وقته', 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'],
    submitted: ['سلّم', 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300'],
    absent: ['غائب', 'bg-gray-200 text-gray-500 dark:bg-gray-700 dark:text-gray-400'],
};
const EVENTS = { hidden: 'غادر الصفحة', blur: 'ترك النافذة', paste: 'لصق', copy: 'محاولة نسخ', ip_changed: 'تغيّر الجهاز' };

const filter = ref('all');
const search = ref('');
const rows = computed(() => live.value.rows.filter((r) =>
    (filter.value === 'all' || r.status === filter.value) &&
    (!search.value || (r.user.name + ' ' + r.user.email + ' ' + (r.seat ?? '')).toLowerCase().includes(search.value.toLowerCase()))));

// ---- The hall map: each room's computers where they stand, coloured by the student at each ----
const view = ref((() => { try { return localStorage.getItem('monitor-view') || 'table'; } catch { return 'table'; } })());
function setView(v) { view.value = v; try { localStorage.setItem('monitor-view', v); } catch {} }
const byNumber = (a, b) => a.label.localeCompare(b.label, undefined, { numeric: true });
const mapRooms = computed(() => {
    const atPc = {};
    live.value.rows.forEach((r) => { if (r.computer) atPc[r.computer.id] = r; });
    const rooms = {};
    props.computers.forEach((c) => (rooms[c.room || ''] ??= []).push(c));
    return Object.entries(rooms).map(([room, pcs]) => {
        pcs = [...pcs].sort(byNumber);
        const placed = pcs.filter((p) => p.seat_row && p.seat_col);
        const loose = pcs.filter((p) => !(p.seat_row && p.seat_col));
        // Computers not placed yet go in rows after the placed ones.
        const cols = Math.max(0, ...placed.map((p) => p.seat_col)) || Math.min(8, Math.max(1, Math.ceil(Math.sqrt(pcs.length * 1.5))));
        const after = Math.max(0, ...placed.map((p) => p.seat_row));
        const cells = [
            ...placed.map((p) => ({ pc: p, row: p.seat_row, col: p.seat_col })),
            ...loose.map((p, i) => ({ pc: p, row: after + 1 + Math.floor(i / cols), col: (i % cols) + 1 })),
        ].map((c) => ({ ...c, r: atPc[c.pc.id] ?? null }));
        return { room, cols, cells };
    });
});
const unseated = computed(() => live.value.rows.filter((r) => !r.computer && r.status !== 'absent'));
const cellTitle = (c) => !c.r ? c.pc.label + ' — ' + t('exam.seat_empty', 'فارغة')
    : [c.pc.label, c.r.user.name, t('exam.status_' + c.r.status, STATUS[c.r.status][0]),
        c.r.attempt ? c.r.attempt.answered + '/' + c.r.attempt.questions : null].filter(Boolean).join(' · ');

// ---- Attendance by login card: a USB scanner types the card into this box, or the camera reads it ----
// The address students type (or scan with a phone), shown with the code for the whole hall.
const address = window.location.origin;
const addressQr = ref(null);
import('qrcode').then(({ default: QRCode }) => QRCode.toDataURL(address, { margin: 0, width: 256 })).then((url) => (addressQr.value = url)).catch(() => {});
const photoOf = ref(null);   // the row whose photo is shown large
const scanCode = ref('');
const scanMsg = ref(null);   // { ok, text }
async function scanCard(code = scanCode.value) {
    if (!code.trim()) return;
    try {
        const res = await window.axios.post(route('exams.monitor.scan', props.exam.id), { code });
        scanMsg.value = { ok: true, text: res.data.name + (res.data.seat ? ' — ' + res.data.seat : ''), photo: res.data.photo };
        await refresh();
    } catch (e) {
        scanMsg.value = { ok: false, text: e.response?.data?.message || t('exam.scan_failed', 'تعذّرت قراءة البطاقة.') };
    }
    scanCode.value = '';
}
const canCamera = typeof window !== 'undefined' && window.isSecureContext && !!navigator.mediaDevices?.getUserMedia;
const cameraOpen = ref(false);
const video = ref(null);
let stream = null;
let lastRead = '';
async function openCamera() {
    cameraOpen.value = true;
    const { default: jsQR } = await import('jsqr');
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    } catch {
        scanMsg.value = { ok: false, text: t('exam.camera_failed', 'تعذّر فتح الكاميرا.') };
        cameraOpen.value = false;
        return;
    }
    video.value.srcObject = stream;
    await video.value.play();
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const frame = () => {
        if (!cameraOpen.value) return;
        if (video.value.videoWidth) {
            canvas.width = video.value.videoWidth; canvas.height = video.value.videoHeight;
            ctx.drawImage(video.value, 0, 0);
            const found = jsQR(ctx.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height);
            if (found?.data && found.data !== lastRead) { lastRead = found.data; scanCard(found.data); setTimeout(() => (lastRead = ''), 4000); }
        }
        requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
}
function closeCamera() {
    cameraOpen.value = false;
    stream?.getTracks().forEach((tr) => tr.stop());
    stream = null;
}
onBeforeUnmount(closeCamera);

// ---- Actions ----
const busy = ref(false);
async function act(url, body = {}) {
    busy.value = true;
    try { await window.axios.post(url, body); await refresh(); } finally { busy.value = false; }
}
const setPresent = (r, present) => act(route('exams.monitor.attendance', [props.exam.id, r.user.id]), { present, seat: r.seat, note: r.note });
const saveSeat = (r, seat) => act(route('exams.monitor.attendance', [props.exam.id, r.user.id]), { present: r.present ?? true, seat, note: r.note });

const dialog = ref(null);   // { kind: 'extend'|'reopen'|'submit'|'submit_all', row }
const minutes = ref(10);
const hallRoom = ref('');
async function confirmDialog() {
    const d = dialog.value;
    if (d.kind === 'extend') await act(route('exams.monitor.extend', d.row.attempt.id), { minutes: minutes.value });
    if (d.kind === 'reopen') await act(route('exams.monitor.reopen', d.row.attempt.id), { minutes: minutes.value });
    if (d.kind === 'submit') await act(route('exams.monitor.submit', d.row.attempt.id));
    if (d.kind === 'submit_all') await act(route('exams.monitor.submit-all', props.exam.id));
    if (d.kind === 'extend_all') await act(route('exams.monitor.extend-all', props.exam.id), { minutes: minutes.value, room: hallRoom.value || null });
    dialog.value = null;
}

const code = ref(props.exam.access_code);
async function newCode(clear = false) {
    code.value = (await window.axios.post(route('exams.monitor.access-code', props.exam.id), { clear })).data.access_code;
}
const note = ref('');
async function announce() {
    if (!note.value.trim()) return;
    await act(route('exams.monitor.announce', props.exam.id), { body: note.value });
    note.value = '';
}
const showCode = ref(false);

// Hall switches during the exam.
const switches = ref({ resume_code_required: props.exam.resume_code_required, bind_computers: props.exam.bind_computers });
async function toggle(key) {
    switches.value = (await window.axios.post(route('exams.monitor.settings', props.exam.id), { [key]: !switches.value[key] })).data;
    refresh();
}
const unlock = (r) => act(route('exams.monitor.unlock', r.attempt.id));
const helpDone = (r) => act(route('exams.monitor.help-done', r.attempt.id));
const seat = (r, id) => act(route('exams.monitor.seat', [props.exam.id, r.user.id]), { computer_id: id ? Number(id) : null });
const rooms = computed(() => [...new Set(props.computers.map((c) => c.room).filter(Boolean))]);
const distributeRoom = ref('');
// Sittings: one student at a time, or all of them evenly.
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleString(document.documentElement.lang || 'ar', { dateStyle: 'short', timeStyle: 'short' }) : '');
const setSession = (r, v) => act(route('exams.monitor.session', [props.exam.id, r.user.id]), { session: v ? Number(v) : null });
const splitSessions = (order) => act(route('exams.monitor.distribute-sessions', props.exam.id), { order });
const distributeMsg = ref('');
async function distribute(order) {
    const res = await window.axios.post(route('exams.monitor.distribute', props.exam.id), { room: distributeRoom.value || null, order });
    distributeMsg.value = t('exam.distributed', 'وُزّع :s من :n طالباً على :c حاسبة.', { s: res.data.seated, n: res.data.students, c: res.data.computers });
    refresh();
}
// A raised hand is heard, not only seen.
let lastHelp = props.data.counts.help ?? 0;
function beep() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const o = ctx.createOscillator();
        o.frequency.value = 880;
        o.connect(ctx.destination);
        o.start();
        o.stop(ctx.currentTime + 0.25);
    } catch { /* no sound */ }
}

// An emergency answers file from a student whose network did not come back.
const fileMsg = ref('');
async function uploadOffline(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    const data = new FormData();
    data.append('file', file);
    data.append('submit', '1');
    try {
        const res = await window.axios.post(route('exams.monitor.offline-file', props.exam.id), data);
        fileMsg.value = t('exam.offline_file_ok', 'أُدخلت :n إجابة لـ :s وسُلّمت.', { n: res.data.answers, s: res.data.student });
        refresh();
    } catch (e) {
        fileMsg.value = e.response?.data?.message ?? t('exam.upload_failed', 'تعذّر رفع الملف.');
    }
}
</script>

<template>
    <Head :title="t('exam.monitor', 'المراقبة') + ' — ' + exam.title" />
    <AppLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-2">
                <Link :href="route('exams.monitor.index')" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="min-w-0 flex-1 truncate text-lg font-semibold" dir="auto">{{ exam.title }}</h2>
                <span v-if="stale" class="flex items-center gap-1 text-xs text-red-600"><ExclamationTriangleIcon class="h-4 w-4" /> {{ t('exam.monitor_stale', 'لا اتصال بالسيرفر') }}</span>
                <button @click="refresh" class="rounded p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('exam.refresh', 'تحديث')"><ArrowPathIcon class="h-4 w-4" /></button>
                <Link :href="route('exams.monitor.sheet', exam.id)" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600"><PrinterIcon class="h-4 w-4" /> {{ t('exam.attendance_sheet', 'كشف الحضور') }}</Link>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <!-- Counts -->
            <div class="grid grid-cols-3 gap-2 sm:grid-cols-7">
                <button v-for="k in ['all', 'not_started', 'working', 'disconnected', 'time_up', 'submitted', 'absent']" :key="k" @click="filter = k"
                    class="rounded-xl border bg-white p-2 text-center dark:bg-gray-800" :class="filter === k ? 'border-[var(--accent-600)] ring-1 ring-[var(--accent-500)]' : 'border-gray-200 dark:border-gray-700'">
                    <p class="text-xl font-semibold">{{ k === 'all' ? live.counts.total : live.counts[k] ?? 0 }}</p>
                    <p class="text-xs text-gray-500">{{ k === 'all' ? t('exam.all', 'الكل') : t('exam.status_' + k, STATUS[k][0]) }}</p>
                </button>
            </div>

            <!-- Hall tools -->
            <div class="grid gap-3 lg:grid-cols-3">
                <div class="flex items-center gap-2 rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <KeyIcon class="h-5 w-5 text-gray-400" />
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-gray-500">{{ t('exam.access_code_short', 'رمز الدخول') }}</p>
                        <button @click="showCode = true" class="font-mono text-lg font-bold tracking-widest" :title="t('exam.show_big', 'عرض بخط كبير')">{{ code || '—' }}</button>
                    </div>
                    <button @click="newCode()" class="rounded-lg border border-gray-300 px-2 py-1 text-xs dark:border-gray-600">{{ t('exam.new_code', 'رمز جديد') }}</button>
                    <button v-if="code" @click="newCode(true)" class="rounded-lg border border-gray-300 px-2 py-1 text-xs dark:border-gray-600">{{ t('exam.no_code', 'بلا رمز') }}</button>
                </div>
                <form @submit.prevent="announce" class="flex items-center gap-2 rounded-xl border border-gray-200 bg-white p-3 lg:col-span-2 dark:border-gray-700 dark:bg-gray-800">
                    <MegaphoneIcon class="h-5 w-5 text-gray-400" />
                    <input v-model="note" maxlength="500" :placeholder="t('exam.announce_ph', 'رسالة لكل الطلاب في هذا الامتحان (مثلاً: بقيت 10 دقائق)')" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    <button :disabled="busy || !note.trim()" class="rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm text-white disabled:opacity-40">{{ t('exam.send', 'إرسال') }}</button>
                </form>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <label class="cursor-pointer rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs text-gray-600 dark:border-gray-600 dark:text-gray-300">
                    <input type="file" accept=".json,application/json" class="sr-only" @change="uploadOffline" />
                    {{ t('exam.offline_file_upload', 'رفع ملف إجابات طارئ من طالب') }}
                </label>
                <span v-if="fileMsg" class="text-xs">{{ fileMsg }}</span>
            </div>
            <div class="grid gap-3 lg:grid-cols-3">
                <label class="flex items-center justify-between gap-2 rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <span>{{ t('exam.resume_code', 'رمز المتابعة عند الخروج') }}<span class="block text-xs text-gray-500">{{ t('exam.resume_code_hint', 'من يغادر الصفحة أو يفتحها من جديد يُقفل حتى تعطيه رمزه') }}</span></span>
                    <input type="checkbox" :checked="switches.resume_code_required" @change="toggle('resume_code_required')" class="h-5 w-5 rounded" />
                </label>
                <label class="flex items-center justify-between gap-2 rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <span>{{ t('exam.bind_computers', 'تثبيت كل طالب على حاسبته') }}<span class="block text-xs text-gray-500">{{ t('exam.bind_computers_hint', 'لا يفتح امتحانه إلا من عنوان حاسبته') }}</span></span>
                    <input type="checkbox" :checked="switches.bind_computers" @change="toggle('bind_computers')" class="h-5 w-5 rounded" />
                </label>
                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <span class="text-xs text-gray-500">{{ t('exam.distribute', 'توزيع على الحواسيب') }}</span>
                    <select v-model="distributeRoom" class="rounded-lg border-gray-300 py-1 text-xs dark:border-gray-600 dark:bg-gray-700">
                        <option value="">{{ t('exam.all_rooms', 'كل القاعات') }}</option>
                        <option v-for="r in rooms" :key="r" :value="r">{{ r }}</option>
                    </select>
                    <button @click="distribute('name')" :disabled="!computers.length" class="rounded border border-gray-300 px-2 py-1 text-xs disabled:opacity-40 dark:border-gray-600">{{ t('exam.by_name', 'بالاسم') }}</button>
                    <button @click="distribute('random')" :disabled="!computers.length" class="rounded border border-gray-300 px-2 py-1 text-xs disabled:opacity-40 dark:border-gray-600">{{ t('exam.randomly', 'عشوائياً') }}</button>
                    <button @click="distribute('mixed')" :disabled="!computers.length" class="rounded border border-gray-300 px-2 py-1 text-xs disabled:opacity-40 dark:border-gray-600" :title="t('exam.mixed_hint', 'طلاب المجموعات (الشعب) بالتناوب حتى لا يجلس زميلا شعبة متجاورين')">{{ t('exam.mixed', 'خلط الشعب') }}</button>
                    <Link :href="route('exams.monitor.computers')" class="text-xs text-[var(--accent-600)] underline">{{ t('exam.computers', 'الحواسيب') }}</Link>
                    <span v-if="distributeMsg" class="w-full text-xs text-gray-500">{{ distributeMsg }}</span>
                </div>
            </div>
            <div v-if="exam.sessions.length" class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                <span class="text-xs text-gray-500">{{ t('exam.sessions_short', 'الجلسات') }}:</span>
                <span v-for="s in exam.sessions" :key="s.number" class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs dark:bg-gray-700">{{ s.label }} · {{ fmtTime(s.opens_at) }}–{{ fmtTime(s.closes_at) }} ({{ live.rows.filter((r) => r.session === s.number).length }})</span>
                <span class="flex-1" />
                <button @click="splitSessions('name')" class="rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600">{{ t('exam.split_by_name', 'توزيع بالاسم') }}</button>
                <button @click="splitSessions('random')" class="rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600">{{ t('exam.randomly', 'عشوائياً') }}</button>
            </div>
            <p v-if="live.counts.help" class="rounded-lg bg-amber-100 px-3 py-2 text-sm font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200"><HandRaisedIcon class="inline h-4 w-4 align-text-bottom" /> {{ t('exam.help_waiting', ':n طالب يطلب المساعدة', { n: live.counts.help }) }}</p>
            <p v-if="live.announcements.length" class="text-xs text-gray-500">{{ t('exam.last_announcement', 'آخر رسالة') }}: <span dir="auto">{{ live.announcements[0].body }}</span></p>

            <ServerHealth compact />

            <!-- Attendance by login card -->
            <form @submit.prevent="scanCard()" class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                <QrCodeIcon class="h-5 w-5 text-gray-400" />
                <input v-model="scanCode" dir="ltr" :placeholder="t('exam.scan_ph', 'امسح بطاقة الطالب هنا لتسجيل حضوره')" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                <button v-if="canCamera" type="button" @click="cameraOpen ? closeCamera() : openCamera()" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-600"><CameraIcon class="h-4 w-4" /> {{ t('exam.camera', 'الكاميرا') }}</button>
                <span v-if="scanMsg" class="flex items-center gap-1 text-sm" :class="scanMsg.ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                    <img v-if="scanMsg.photo" :src="scanMsg.photo" alt="" class="h-12 w-9 rounded object-cover" />
                    <CheckIcon v-if="scanMsg.ok" class="h-4 w-4" /><XMarkIcon v-else class="h-4 w-4" /> {{ scanMsg.text }}</span>
                <video v-show="cameraOpen" ref="video" playsinline muted class="w-full max-w-xs rounded-lg" />
            </form>

            <!-- Students -->
            <div class="flex flex-wrap items-center gap-2">
                <div v-if="computers.length" class="flex overflow-hidden rounded-lg border border-gray-300 text-sm dark:border-gray-600">
                    <button @click="setView('table')" class="flex items-center gap-1 px-3 py-1.5" :class="view === 'table' ? 'bg-[var(--accent-600)] text-white' : ''"><TableCellsIcon class="h-4 w-4" /> {{ t('exam.view_table', 'جدول') }}</button>
                    <button @click="setView('map')" class="flex items-center gap-1 px-3 py-1.5" :class="view === 'map' ? 'bg-[var(--accent-600)] text-white' : ''"><MapIcon class="h-4 w-4" /> {{ t('exam.view_map', 'خريطة القاعة') }}</button>
                </div>
                <input v-model="search" :placeholder="t('exam.search_student', 'بحث بالاسم أو المقعد')" class="w-64 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                <span class="flex-1" />
                <button v-if="(live.counts.working ?? 0) + (live.counts.disconnected ?? 0) + (live.counts.time_up ?? 0)" @click="minutes = 10; hallRoom = ''; dialog = { kind: 'extend_all' }"
                    class="flex items-center gap-1 rounded-lg border border-sky-300 px-3 py-1.5 text-sm text-sky-700 dark:border-sky-700 dark:text-sky-300"><PauseCircleIcon class="h-4 w-4" /> {{ t('exam.extend_all', 'توقّف القاعة: وقت لكل الطلاب') }}</button>
                <button v-if="(live.counts.working ?? 0) + (live.counts.disconnected ?? 0) + (live.counts.time_up ?? 0)" @click="dialog = { kind: 'submit_all' }"
                    class="rounded-lg border border-red-300 px-3 py-1.5 text-sm text-red-700 dark:border-red-700 dark:text-red-300">{{ t('exam.submit_all', 'تسليم كل المحاولات الجارية') }}</button>
            </div>
            <!-- The hall map -->
            <div v-if="view === 'map' && computers.length" class="space-y-4">
                <section v-for="m in mapRooms" :key="m.room" class="overflow-x-auto rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="mb-2 text-sm font-semibold">{{ m.room || t('exam.no_room', 'بلا قاعة') }}</p>
                    <p class="mb-3 rounded bg-gray-100 py-1 text-center text-xs text-gray-500 dark:bg-gray-700">{{ t('exam.hall_front', 'مقدمة القاعة') }}</p>
                    <div class="grid gap-2" :style="{ gridTemplateColumns: `repeat(${m.cols}, minmax(6.5rem, 1fr))` }">
                        <div v-for="c in m.cells" :key="c.pc.id" :style="{ gridRow: c.row, gridColumn: c.col }" :title="cellTitle(c)"
                            class="relative min-h-[4.5rem] rounded-lg border p-1.5 text-xs"
                            :class="[c.r ? STATUS[c.r.status][1] + ' border-transparent' : 'border-dashed border-gray-300 text-gray-400 dark:border-gray-600',
                                c.r?.attempt?.help ? 'ring-2 ring-amber-400' : '', search && c.r && !rows.includes(c.r) ? 'opacity-30' : '']">
                            <p class="flex items-center gap-1 font-mono font-semibold">{{ c.pc.label }}
                                <HandRaisedIcon v-if="c.r?.attempt?.help" class="h-3.5 w-3.5 text-amber-600" />
                                <LockClosedIcon v-if="c.r?.attempt?.locked" class="h-3.5 w-3.5 text-red-600" />
                                <ExclamationTriangleIcon v-if="c.r?.attempt?.ip && c.r.attempt.ip !== c.pc.ip" class="h-3.5 w-3.5 text-red-600" />
                            </p>
                            <template v-if="c.r">
                                <img v-if="c.r.user.photo" :src="c.r.user.photo" alt="" loading="lazy" class="float-end ms-1 h-9 w-7 cursor-zoom-in rounded object-cover" @click="photoOf = c.r" />
                                <p class="truncate font-medium">{{ c.r.user.name }}</p>
                                <p v-if="c.r.attempt && !c.r.attempt.submitted_at">{{ c.r.attempt.answered }}/{{ c.r.attempt.questions }}<span v-if="c.r.attempt.variant"> · {{ c.r.attempt.variant }}</span></p>
                                <p v-else class="opacity-75">{{ t('exam.status_' + c.r.status, STATUS[c.r.status][0]) }}</p>
                            </template>
                            <p v-else>{{ t('exam.seat_empty', 'فارغة') }}</p>
                        </div>
                    </div>
                </section>
                <p v-if="unseated.length" class="text-sm text-gray-600 dark:text-gray-300">{{ t('exam.unseated', 'بلا حاسبة') }} ({{ unseated.length }}): {{ unseated.map((r) => r.user.name).join('، ') }}</p>
                <div class="flex flex-wrap gap-2 text-xs"><span v-for="(s, k) in STATUS" :key="k" class="rounded-full px-2 py-0.5" :class="s[1]">{{ t('exam.status_' + k, s[0]) }}</span></div>
            </div>

            <div v-show="view === 'table' || !computers.length" class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ t('exam.student', 'الطالب') }}</th>
                            <th class="px-2 py-2">{{ t('exam.present', 'حاضر') }}</th>
                            <th class="px-2 py-2">{{ t('exam.pc_name', 'الحاسبة') }}</th>
                            <th class="px-2 py-2">{{ t('exam.status', 'الحالة') }}</th>
                            <th class="px-2 py-2">{{ t('exam.progress', 'الإنجاز') }}</th>
                            <th class="px-2 py-2">{{ t('exam.time_left', 'المتبقي') }}</th>
                            <th class="px-2 py-2">{{ t('exam.alerts', 'تنبيهات') }}</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.user.id" class="border-t border-gray-100 dark:border-gray-700">
                            <td class="px-3 py-2"><div class="flex items-start gap-2">
                                <button v-if="r.user.photo" type="button" @click="photoOf = r" class="shrink-0" :title="t('exam.photo_big', 'تكبير الصورة')"><img :src="r.user.photo" alt="" loading="lazy" class="h-12 w-9 rounded object-cover" /></button>
                                <div class="min-w-0">
                                <p class="font-medium">{{ r.user.name }} <span v-if="r.user.extra_time" class="text-xs text-sky-600" :title="t('exam.extra_time', 'وقت إضافي')"><ClockIcon class="inline h-3.5 w-3.5 align-text-bottom" /></span></p>
                                <p class="text-xs text-gray-400" dir="ltr"><span v-if="r.user.number" class="font-mono text-gray-600 dark:text-gray-300">{{ r.user.number }} · </span>{{ r.user.email }}<span v-if="r.attempt?.ip"> · {{ r.attempt.ip }}</span></p>
                                <p v-if="r.attempt?.variant || r.attempt?.receipt" class="text-xs text-gray-500"><span v-if="r.attempt.variant" class="rounded bg-violet-100 px-1.5 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200">{{ t('exam.variant_short', 'نسخة') }} {{ r.attempt.variant }}</span>
                                    <span v-if="r.attempt.receipt" class="ms-1 font-mono" dir="ltr" :title="t('exam.receipt_code', 'رمز الإيصال')">{{ r.attempt.receipt }}</span></p>
                                </div></div>
                            </td>
                            <td class="px-2 py-2 text-center whitespace-nowrap">
                                <button @click="setPresent(r, true)" class="rounded px-1.5 py-0.5 text-xs" :class="r.present === true ? 'bg-emerald-600 text-white' : 'border border-gray-300 dark:border-gray-600'"><CheckIcon class="h-4 w-4" /></button>
                                <button @click="setPresent(r, false)" class="ms-1 rounded px-1.5 py-0.5 text-xs" :class="r.present === false ? 'bg-red-600 text-white' : 'border border-gray-300 dark:border-gray-600'"><XMarkIcon class="h-4 w-4" /></button>
                            </td>
                            <td class="px-2 py-2 text-center">
                                <select v-if="exam.sessions.length" :value="r.session ?? ''" @change="setSession(r, $event.target.value)" class="mb-1 block w-full rounded border-gray-300 py-0.5 text-xs dark:border-gray-600 dark:bg-gray-700" :title="t('exam.session', 'الجلسة')">
                                    <option value="">{{ t('exam.no_session', 'بلا جلسة') }}</option>
                                    <option v-for="s in exam.sessions" :key="s.number" :value="s.number">{{ s.label }}</option>
                                </select>
                                <select v-if="computers.length" :value="r.computer?.id ?? ''" @change="seat(r, $event.target.value)" class="rounded border-gray-300 py-0.5 text-xs dark:border-gray-600 dark:bg-gray-700">
                                    <option value="">—</option>
                                    <option v-for="c in computers" :key="c.id" :value="c.id">{{ c.label }}</option>
                                </select>
                                <input v-else :value="r.seat" @change="saveSeat(r, $event.target.value)" class="w-16 rounded border-gray-300 py-0.5 text-center text-xs dark:border-gray-600 dark:bg-gray-700" />
                                <p v-if="r.computer && r.attempt?.ip && r.attempt.ip !== r.computer.ip" class="text-[10px] text-red-600" :title="r.computer.ip">{{ t('exam.other_pc', 'من حاسبة أخرى') }}</p>
                            </td>
                            <td class="px-2 py-2 text-center">
                                <span class="rounded-full px-2 py-0.5 text-xs whitespace-nowrap" :class="STATUS[r.status][1]">{{ t('exam.status_' + r.status, STATUS[r.status][0]) }}</span>
                                <p v-if="r.attempt?.help" class="mt-1"><button @click="helpDone(r)" class="rounded-full bg-amber-400 px-2 py-0.5 text-xs font-semibold text-amber-950" :title="t('exam.help_done', 'تمّت المساعدة')"><HandRaisedIcon class="inline h-3.5 w-3.5 align-text-bottom" /> {{ t('exam.help', 'مساعدة') }}</button></p>
                                <p v-if="r.attempt?.locked" class="mt-1 text-xs text-red-700 dark:text-red-300"><LockClosedIcon class="inline h-3.5 w-3.5 align-text-bottom" /> {{ r.attempt.locked.reason === 'left' ? t('exam.locked_left', 'غادر الصفحة') : t('exam.locked_reopened', 'فتحها من جديد') }} ·
                                    <b class="font-mono text-sm tracking-widest">{{ r.attempt.locked.code }}</b>
                                    <button @click="unlock(r)" class="ms-1 underline">{{ t('exam.unlock', 'فتح') }}</button></p>
                                <p v-if="r.attempt?.clock" class="mt-1 flex items-center justify-center gap-1 text-[10px] text-amber-700 dark:text-amber-300" :title="t('exam.clock_hint', 'ساعة هذه الحاسبة غير مضبوطة؛ المؤقت يعتمد السيرفر، لكن اضبطها.')">
                                    <ClockIcon class="h-3 w-3" /> {{ r.attempt.clock > 0 ? t('exam.clock_ahead', 'ساعتها متقدمة :m د', { m: Math.round(r.attempt.clock / 60) }) : t('exam.clock_behind', 'ساعتها متأخرة :m د', { m: Math.round(-r.attempt.clock / 60) }) }}</p>
                                <p v-if="r.status === 'disconnected'" class="text-[10px] text-gray-400">{{ Math.round(since(r.attempt.last_seen) / 60) }} {{ t('exam.min_ago', 'د') }}</p>
                            </td>
                            <td class="px-2 py-2 text-center text-xs">
                                <template v-if="r.attempt">
                                    {{ r.attempt.answered }} / {{ r.attempt.questions }}
                                    <div class="mx-auto mt-1 h-1.5 w-16 rounded-full bg-gray-200 dark:bg-gray-700"><div class="h-1.5 rounded-full bg-[var(--accent-500)]" :style="{ width: Math.min(100, (r.attempt.answered / Math.max(1, r.attempt.questions)) * 100) + '%' }" /></div>
                                </template>
                            </td>
                            <td class="px-2 py-2 text-center font-mono text-xs" :class="left(r) !== null && left(r) < 300 ? 'text-red-600' : ''">{{ clock(left(r)) }}</td>
                            <td class="px-2 py-2 text-xs">
                                <span v-for="(n, type) in r.attempt?.events ?? {}" :key="type" class="me-1 inline-block rounded bg-red-50 px-1.5 text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ t('exam.event_' + type, EVENTS[type] ?? type) }} ×{{ n }}</span>
                            </td>
                            <td class="px-3 py-2 text-end whitespace-nowrap">
                                <template v-if="r.attempt && r.status !== 'submitted'">
                                    <button @click="minutes = 10; dialog = { kind: 'extend', row: r }" class="rounded border border-gray-300 px-2 py-0.5 text-xs dark:border-gray-600">{{ t('exam.extend', '+ وقت') }}</button>
                                    <button @click="dialog = { kind: 'submit', row: r }" class="ms-1 rounded border border-red-300 px-2 py-0.5 text-xs text-red-700 dark:border-red-700 dark:text-red-300">{{ t('exam.force_submit', 'تسليم') }}</button>
                                </template>
                                <button v-else-if="r.status === 'submitted'" @click="minutes = 10; dialog = { kind: 'reopen', row: r }" class="rounded border border-gray-300 px-2 py-0.5 text-xs dark:border-gray-600">{{ t('exam.reopen', 'إعادة فتح') }}</button>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="p-6 text-center text-sm text-gray-500">{{ t('exam.monitor_no_students', 'لا طلاب هنا. امتحان بلا مجموعة يُظهر من بدأه أو سُجّل حضوره.') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal :show="!!dialog" @close="dialog = null" max-width="sm">
            <div v-if="dialog" class="space-y-4 p-6">
                <p class="text-sm font-medium">
                    <template v-if="dialog.kind === 'extend'">{{ t('exam.extend_q', 'وقت إضافي لـ :n', { n: dialog.row.user.name }) }}</template>
                    <template v-else-if="dialog.kind === 'reopen'">{{ t('exam.reopen_q', 'إعادة فتح محاولة :n؟ تُلغى درجتها حتى يسلّم من جديد.', { n: dialog.row.user.name }) }}</template>
                    <template v-else-if="dialog.kind === 'submit'">{{ t('exam.submit_q', 'تسليم محاولة :n الآن بإجاباتها المحفوظة؟', { n: dialog.row.user.name }) }}</template>
                    <template v-else-if="dialog.kind === 'extend_all'">{{ t('exam.extend_all_q', 'كم دقيقة توقّف الامتحان؟ تُضاف لكل محاولة جارية.') }}</template>
                    <template v-else>{{ t('exam.submit_all_q', 'تسليم كل المحاولات الجارية الآن؟ (نهاية الجلسة)') }}</template>
                </p>
                <label v-if="dialog.kind === 'extend_all' && rooms.length" class="block text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.room', 'القاعة') }}</span>
                    <select v-model="hallRoom" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                        <option value="">{{ t('exam.all_rooms', 'كل القاعات') }}</option>
                        <option v-for="r in rooms" :key="r" :value="r">{{ r }}</option>
                    </select></label>
                <label v-if="dialog.kind === 'extend' || dialog.kind === 'reopen' || dialog.kind === 'extend_all'" class="block text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.minutes_label', 'دقائق') }}</span>
                    <input v-model.number="minutes" type="number" min="1" max="600" class="w-28 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                <div class="flex justify-end gap-2">
                    <button @click="dialog = null" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button @click="confirmDialog" :disabled="busy" class="rounded-lg px-4 py-2 text-sm text-white" :class="dialog.kind.startsWith('submit') ? 'bg-red-600' : 'bg-[var(--accent-600)]'">{{ t('action.confirm', 'تأكيد') }}</button>
                </div>
            </div>
        </Modal>

        <!-- The code, big enough for the whole hall -->
        <div v-if="showCode" @click="showCode = false" class="fixed inset-0 z-50 flex cursor-pointer flex-col items-center justify-center bg-white dark:bg-gray-900">
            <p class="text-2xl text-gray-500">{{ exam.title }}</p>
            <p class="mt-6 font-mono text-[18vw] font-bold leading-none tracking-widest">{{ code || '—' }}</p>
            <p class="mt-6 text-lg text-gray-400">{{ t('exam.access_code_short', 'رمز الدخول') }}</p>
            <div class="mt-6 flex items-center gap-4">
                <img v-if="addressQr" :src="addressQr" alt="" class="h-32 w-32" />
                <p class="font-mono text-3xl font-semibold" dir="ltr">{{ address }}</p>
            </div>
        </div>
        <!-- A student's photo, large, to compare with the face -->
        <div v-if="photoOf" class="fixed inset-0 z-50 flex cursor-pointer flex-col items-center justify-center bg-black/70 p-6" @click="photoOf = null">
            <img :src="photoOf.user.photo" alt="" class="max-h-[70vh] rounded-xl bg-white object-contain shadow-2xl" />
            <p class="mt-4 text-xl font-semibold text-white">{{ photoOf.user.name }}</p>
            <p class="font-mono text-white/80" dir="ltr">{{ photoOf.user.number || photoOf.user.email }}<template v-if="photoOf.computer"> · {{ photoOf.computer.label }}</template></p>
        </div>
    </AppLayout>
</template>
