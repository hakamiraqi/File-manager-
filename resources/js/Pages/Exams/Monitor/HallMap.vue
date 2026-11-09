<script setup>
// «خريطة القاعات» (2026-11-09, by request): FileNest's library map (same code, same idea) for
// the exam halls. Every computer takes the colour of what is happening on it now: off, on, a
// student working, help asked, locked, cut off, the wrong student, a wrong clock, finished.
// Tap a computer (or a problem in the list) and the map shows the way to it from where you are,
// Waze style; scan the QR sticker on any computer or wall to say where you are.
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import HallMap3D from '@/Components/Exams/HallMap/HallMap3D.vue';
import HallMap2D from '@/Components/Exams/HallMap/HallMap2D.vue';
import Maneuver from '@/Components/Exams/HallMap/Maneuver.vue';
import { findRoute, isLift, directions, pointAt, standingPoints, hallAt, isHall, storeyOf, routeOnStorey, outline } from '@/Components/Exams/HallMap/route.js';
import { createStepTracker, requestSensors, sensorsSupported, stepLength as stepLengthStore, compassOffset, planBearing, angleDiff, circularMean, toPlanHeading } from '@/Components/Exams/HallMap/stepTracker.js';
import QrScanner from '@/Components/Exams/HallMap/QrScanner.vue';
import { isSticker, nearestStickers } from '@/Components/Exams/HallMap/stickers.js';
import { useTrans } from '@/Composables/useTrans';
import { useAlerts } from '@/Composables/useAlerts';
import {
    MagnifyingGlassIcon, XMarkIcon, PlayIcon, PauseIcon, ArrowPathIcon, MapIcon, PencilSquareIcon,
    ViewfinderCircleIcon, ChevronLeftIcon, ChevronRightIcon, CheckCircleIcon, QrCodeIcon, MapPinIcon, ExclamationTriangleIcon, MinusIcon, PlusIcon,
    Squares2X2Icon, CubeIcon, ArrowsPointingOutIcon, ArrowsPointingInIcon, ComputerDesktopIcon, BellAlertIcon, BellSlashIcon, ClockIcon, HandRaisedIcon, LockOpenIcon, ArrowTopRightOnSquareIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ floor: Object, shelves: Array, live: Object, exams: Array, examId: { type: Number, default: null }, canEdit: Boolean, startAt: { type: Number, default: null }, target: { type: Number, default: null } });
const embedded = false;
const Layout = AppLayout;

const map = ref(null);

// «خريطة مسطحة» (2026-11-06, by request): a light 2D plan for weak phones, or by choice.
// Chosen by itself when the phone can't draw 3D or is a low-end one; the choice is remembered.
const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch {} },
};
function weakDevice() {
    try {
        if ((navigator.deviceMemory && navigator.deviceMemory <= 2) || (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 2)) return true;
        const c = document.createElement('canvas');
        return !(c.getContext('webgl2') || c.getContext('webgl'));
    } catch { return false; }
}
const saved2d = store.get('hallmap.flat');
const flat = ref(saved2d === null ? weakDevice() : saved2d === '1');
function setFlat(v) {
    flat.value = v;
    store.set('hallmap.flat', v ? '1' : '0');
}
// «ملء الشاشة» (2026-11-07, by request): the map takes the whole screen, without the side bar,
// the top bar and the browser's own bars where the browser allows it.
const full = ref(false);
function toggleFull() {
    full.value = !full.value;
    try {
        if (full.value && !document.fullscreenElement) document.documentElement.requestFullscreen?.()?.catch?.(() => {});
        else if (!full.value && document.fullscreenElement) document.exitFullscreen?.()?.catch?.(() => {});
    } catch {}
    setTimeout(() => (viewHighlight.value ? map.value?.focusShelf(viewHighlight.value.id) : map.value?.overview()), 250);
}
const onFullChange = () => { if (!document.fullscreenElement && full.value) toggleFull(); };
if (typeof document !== 'undefined') document.addEventListener('fullscreenchange', onFullChange);
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', onFullChange));
// «بدون درج (مصعد فقط)»: the way to another floor goes by a lift only (wheelchair, trolley).
const liftOnly = ref(store.get('hallmap.lift_only') === '1');
watch(liftOnly, (v) => store.set('hallmap.lift_only', v ? '1' : '0'));
const shelfById = computed(() => Object.fromEntries(props.shelves.map((s) => [s.id, s])));
const bookcases = computed(() => props.shelves.filter((s) => s.kind === 'pc'));
// «قاعات وغرف» (2026-11-06, by request): a big library is several halls and rooms.
// Since then also corridors, buildings of several floors and «كل قاعة مستقلة».
const halls = computed(() => props.shelves.filter(isHall));
const storeys = computed(() => props.floor.storeys?.length ? props.floor.storeys : ['']);
const multiStorey = computed(() => storeys.value.length > 1);
const storeyName = (n) => storeys.value[n] || t('library.map.storey_n', 'الطابق :n', { n });
const hallOf = (p) => hallAt(halls.value.filter((h) => storeyOf(h) === storeyOf(p)), p);
const hallName = (shelfId) => {
    const s = shelfById.value[shelfId];
    if (!s) return '';
    const h = halls.value.length > 1 ? hallOf(s)?.code ?? '' : '';
    return [h, multiStorey.value ? storeyName(storeyOf(s)) : ''].filter(Boolean).join(' · ');
};

// Live state of the computers ------------------------------------------------------------------
const STATES = {
    help: { color: '#f59e0b', label: () => t('hallmap.st.help', 'يطلب مساعدة'), alert: true },
    locked: { color: '#dc2626', label: () => t('hallmap.st.locked', 'محاولة مقفلة'), alert: true },
    wrong_pc: { color: '#db2777', label: () => t('hallmap.st.wrong_pc', 'طالب على حاسبة غير حاسبته'), alert: true },
    disconnected: { color: '#ef4444', label: () => t('hallmap.st.disconnected', 'انقطع الاتصال'), alert: true },
    time_up: { color: '#f97316', label: () => t('hallmap.st.time_up', 'انتهى الوقت ولم يُسلَّم'), alert: true },
    off_expected: { color: '#7f1d1d', label: () => t('hallmap.st.off_expected', 'مطفأة وعليها طالب مخصّص'), alert: true },
    working: { color: '#2563eb', label: () => t('hallmap.st.working', 'طالب يؤدي الامتحان') },
    waiting: { color: '#06b6d4', label: () => t('hallmap.st.waiting', 'شغّالة، بانتظار الطالب') },
    submitted: { color: '#10b981', label: () => t('hallmap.st.submitted', 'سلّم') },
    on: { color: '#84cc16', label: () => t('hallmap.st.on', 'شغّالة، فارغة') },
    off: { color: '#9ca3af', label: () => t('hallmap.st.off', 'مطفأة أو بلا اتصال') },
};
const PROBLEMS = {
    help: () => t('hallmap.pr.help', 'الطالب يطلب المساعدة'),
    locked: () => t('hallmap.pr.locked', 'المحاولة مقفلة (غادر الصفحة أو فتحها من جديد)'),
    wrong_pc: () => t('hallmap.pr.wrong_pc', 'الطالب ليس على الحاسبة المخصّصة له'),
    student_elsewhere: () => t('hallmap.pr.student_elsewhere', 'الطالب المخصّص لهذه الحاسبة جالس على حاسبة أخرى'),
    disconnected: () => t('hallmap.pr.disconnected', 'لا اتصال من الحاسبة منذ أكثر من دقيقة ونصف (الشبكة أو الكيبل أو الحاسبة)'),
    time_up: () => t('hallmap.pr.time_up', 'انتهى الوقت والمحاولة لم تُسلَّم'),
    off_expected: () => t('hallmap.pr.off_expected', 'الحاسبة مطفأة أو بلا شبكة وعليها طالب مخصّص'),
    clock: () => t('hallmap.pr.clock', 'ساعة الحاسبة غير مضبوطة'),
};
const liveData = ref(props.live ?? { computers: {}, counts: {} });
const examFilter = ref(props.examId ?? '');
const pcOf = (item) => (item?.computer_id ? liveData.value.computers?.[item.computer_id] ?? null : null);
const statuses = computed(() => {
    const out = {};
    for (const s of props.shelves) {
        if (s.kind !== 'pc') continue;
        const pc = pcOf(s);
        if (!s.computer_id) { out[s.id] = { color: '#e2e8f0', alert: false }; continue; }
        const st = STATES[pc?.state] ?? STATES.off;
        out[s.id] = { color: st.color, alert: !!(pc?.problems?.length) };
    }
    return out;
});
const itemOfPc = computed(() => Object.fromEntries(props.shelves.filter((s) => s.kind === 'pc' && s.computer_id).map((s) => [s.computer_id, s])));
const problemList = computed(() => Object.values(liveData.value.computers ?? {}).filter((c) => c.problems?.length)
    .map((c) => ({ ...c, item: itemOfPc.value[c.id] ?? null }))
    .sort((a, b) => (a.problems.includes('help') ? -1 : 0) - (b.problems.includes('help') ? -1 : 0) || String(a.label).localeCompare(String(b.label), undefined, { numeric: true })));
const legend = computed(() => Object.entries(STATES).map(([k, v]) => ({ key: k, ...v, n: liveData.value.counts?.[k] ?? 0 })).filter((x) => x.n));
const unplaced = computed(() => Object.values(liveData.value.computers ?? {}).filter((c) => !itemOfPc.value[c.id]).length);
const showProblems = ref(false);
const clockText = (sec) => {
    const a = Math.abs(sec);
    const v = a < 120 ? t('hallmap.sec', ':n ثانية', { n: a }) : a < 7200 ? t('hallmap.min', ':n دقيقة', { n: Math.round(a / 60) }) : t('hallmap.hour', ':n ساعة', { n: Math.round(a / 3600) });
    return sec > 0 ? t('hallmap.clock_ahead', 'متقدمة :v', { v }) : t('hallmap.clock_behind', 'متأخرة :v', { v });
};
const fmtLeft = (s) => (s === null || s === undefined ? '' : `${Math.floor(Math.max(0, s) / 60)}:${String(Math.max(0, s) % 60).padStart(2, '0')}`);
const ago = (iso) => {
    if (!iso) return t('hallmap.never', 'لم تُرَ بعد');
    const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
    return s < 60 ? t('hallmap.ago_s', 'قبل :n ثانية', { n: s }) : s < 3600 ? t('hallmap.ago_m', 'قبل :n دقيقة', { n: Math.round(s / 60) }) : new Date(iso).toLocaleString();
};

// Alerts: a sound, a vibration and (in the background) a notification for every new problem.
const alerts = useAlerts();
let known = null;
function watchProblems(data) {
    const now = new Set();
    const fresh = [];
    for (const c of Object.values(data.computers ?? {})) for (const p of c.problems ?? []) {
        const k = c.id + ':' + p;
        now.add(k);
        if (known && !known.has(k)) fresh.push({ c, p });
    }
    known = now;
    if (fresh.length) {
        const first = fresh[0];
        alerts.notify(t('hallmap.alert_title', 'خريطة القاعات: :pc', { pc: first.c.label }), (PROBLEMS[first.p]?.() ?? first.p) + (fresh.length > 1 ? ' ' + t('exam.alert_more', '(و:n تنبيهات أخرى)', { n: fresh.length - 1 }) : ''), route('exams.monitor.halls', { pc: first.c.id }), 'hallmap-' + first.c.id);
    }
}
watchProblems(liveData.value);
let poll = null;
async function refresh() {
    try {
        const { data } = await axios.get(route('exams.monitor.halls.live'), { params: examFilter.value ? { exam: examFilter.value } : {} });
        liveData.value = data;
        watchProblems(data);
    } catch {}
}
onMounted(() => { poll = setInterval(refresh, 5000); });
onBeforeUnmount(() => clearInterval(poll));
watch(examFilter, () => {
    known = null;
    try { const u = new URL(window.location.href); examFilter.value ? u.searchParams.set('exam', examFilter.value) : u.searchParams.delete('exam'); window.history.replaceState(window.history.state, '', u); } catch {}
    refresh();
});
async function act(name, c) {
    try { await axios.post(route(name, c.attempt.id)); } catch {}
    refresh();
}

// Search: a computer by its name or address, or the student at it. -----------------------------
const q = ref('');
const showResults = ref(false);
const results = computed(() => {
    const v = q.value.trim().toLowerCase();
    if (v.length < 1) return [];
    return Object.values(liveData.value.computers ?? {}).filter((c) => [c.label, c.ip, c.room, c.student?.name, c.student?.number, c.expected?.name, c.expected?.number]
        .some((x) => x && String(x).toLowerCase().includes(v))).slice(0, 12).map((c) => ({ ...c, item: itemOfPc.value[c.id] ?? null }));
});
const searching = false;

// Selection: a computer (or anything) tapped on the map. ------------------------------------
const pickedShelf = ref(null);
const targetShelf = computed(() => pickedShelf.value);
const pickedPc = computed(() => pcOf(pickedShelf.value));
function choosePc(c) {
    showResults.value = false;
    showProblems.value = false;
    q.value = '';
    if (!c.item) return;
    pickedShelf.value = c.item;
    stop();
    setTimeout(() => map.value?.focusShelf(c.item.id), 50);
}
function onReady(api) {
    if (pickedShelf.value) setTimeout(() => api.focusShelf(pickedShelf.value.id), 400);
    else if (here.value) setTimeout(() => api.focusShelf(here.value.shelfId), 400);
}
function clearSelection() {
    pickedShelf.value = null;
    stop();
    map.value?.overview();
}
function onPick(id) {
    const s = shelfById.value[id];
    if (!s || walking.value) return;
    pickedShelf.value = s;
    stop();
}
// Opened from an alert (?pc=12): straight to that computer.
if (props.target) { const it = props.shelves.find((s) => s.kind === 'pc' && s.computer_id === props.target); if (it) pickedShelf.value = it; }

// «أنت هنا»: the entrance, or the bookcase whose QR sticker was scanned. ------------------------
const here = ref(null); // { x, y, shelfId, code }
function placeAt(shelf) {
    const p = standingPoints(shelf)[0];
    here.value = { x: p.x, y: p.y, storey: storeyOf(shelf), shelfId: shelf.id, code: shelf.code };
}
if (props.startAt && shelfById.value[props.startAt]) placeAt(shelfById.value[props.startAt]);
const origin = computed(() => here.value ?? { ...props.floor.entrance, storey: 0 });
function resetHere() {
    here.value = null;
    stop();
    try { const u = new URL(window.location.href); u.searchParams.delete('at'); window.history.replaceState(window.history.state, '', u); } catch {}
}

// Route & directions ------------------------------------------------------------------------
const hasLift = computed(() => multiStorey.value && props.shelves.some(isLift));
const routeOpts = computed(() => ({ liftOnly: liftOnly.value && hasLift.value }));
const walkRoute = computed(() => (targetShelf.value && !isHall(targetShelf.value) && targetShelf.value.kind !== 'door' ? findRoute(props.floor, props.shelves, origin.value, targetShelf.value, routeOpts.value) : null));
const otherFloor = computed(() => !!targetShelf.value && storeyOf(targetShelf.value) !== storeyOf(origin.value));
const steps = computed(() => (walkRoute.value ? directions(walkRoute.value, targetShelf.value, halls.value) : []));
const WALK_SPEED = 1.2; // m/s, an easy indoor pace
const metres = (m) => (m < 10 ? Math.max(1, Math.round(m)) : Math.round(m));
const eta = computed(() => {
    const s = Math.round((walkRoute.value?.length ?? 0) / WALK_SPEED);
    return s < 60 ? t('library.map.eta_sec', ':n ثانية', { n: Math.max(5, Math.ceil(s / 5) * 5) }) : t('library.map.eta_min', ':n دقيقة', { n: Math.round(s / 60) });
});

function levelText(level) {
    return level ? t('library.map.level_n', 'الطبقة :n من الأسفل', { n: level }) : '';
}
function stepText(step) {
    const base = baseStepText(step);
    return step.hall && step.type !== 'enter' ? `${base} ${t('library.map.into_hall', 'إلى :hall', { hall: step.hall })}` : base;
}
function baseStepText(step) {
    const d = metres(step.distance);
    const code = targetShelf.value?.code ?? '';
    switch (step.type) {
        case 'stairs_up': if (step.stairs.lift) return t('library.map.lift_up', 'اركب :lift واصعد إلى :storey', { lift: /مصعد/.test(step.stairs.code) ? step.stairs.code : `${t('library.map.lift', 'المصعد')} ${step.stairs.code}`, storey: storeyName(step.storey) });
            return t('library.map.stairs_up', 'اصعد عبر :code إلى :storey', { code: step.stairs.code, storey: storeyName(step.storey) });
        case 'stairs_down': if (step.stairs.lift) return t('library.map.lift_down', 'اركب :lift وانزل إلى :storey', { lift: /مصعد/.test(step.stairs.code) ? step.stairs.code : `${t('library.map.lift', 'المصعد')} ${step.stairs.code}`, storey: storeyName(step.storey) });
            return t('library.map.stairs_down', 'انزل عبر :code إلى :storey', { code: step.stairs.code, storey: storeyName(step.storey) });
        case 'enter': return t('library.map.enter_hall', 'ادخل :hall وامشِ :d م', { hall: step.hall, d });
        case 'start': return t('library.map.go_straight', 'امشِ للأمام :d م', { d });
        case 'right': return t('library.map.turn_right', 'انعطف يميناً ثم امشِ :d م', { d });
        case 'left': return t('library.map.turn_left', 'انعطف يساراً ثم امشِ :d م', { d });
        case 'slight_right': return t('library.map.slight_right', 'مِل قليلاً إلى اليمين :d م', { d });
        case 'slight_left': return t('library.map.slight_left', 'مِل قليلاً إلى اليسار :d م', { d });
        case 'uturn': return t('library.map.uturn', 'استدر للخلف ثم امشِ :d م', { d });
        case 'arrive_right': return t('hallmap.arrive_right', ':code على يمينك', { code });
        case 'arrive_left': return t('hallmap.arrive_left', ':code على يسارك', { code });
        default: return t('hallmap.arrive_ahead', ':code أمامك', { code });
    }
}

// Walking along the route (a preview of the walk: phones can't see where you are indoors). ----
const walking = ref(false);
const showSteps = ref(false);
const paused = ref(false);
const walked = ref(0);
const arrived = ref(false);
let raf = null;
let lastT = 0;
const avatar = computed(() => {
    let pos = null;
    if (walkRoute.value && (walking.value || arrived.value)) pos = pointAt(walkRoute.value, walked.value);
    // Standing still at a scanned sticker: show «أنت هنا», facing the way to go.
    else if (here.value) pos = { x: here.value.x, y: here.value.y, heading: walkRoute.value ? pointAt(walkRoute.value, 0.01).heading : -Math.PI / 2 };
    if (!pos) return null;
    // While walking for real the arrow turns with the phone, once the compass is trusted; where
    // you stand still comes only from your steps along the route.
    const routeHeading = pos.heading;
    const heading = live.value && facingRad.value !== null ? facingRad.value : routeHeading;
    return { ...pos, heading, routeHeading, mapHeading: spinMap.value && walking.value ? heading : null };
});
const currentStep = computed(() => {
    if (!steps.value.length) return null;
    let idx = 0;
    steps.value.forEach((s, i) => { if (s.at <= walked.value + 0.01) idx = i; });
    // Show the coming manoeuvre once you are close to it, like a satnav.
    const next = steps.value[idx + 1];
    if (next && next.at - walked.value < 2.2) idx++;
    return { ...steps.value[idx], index: idx, toNext: Math.max(0, (steps.value[idx].at > walked.value ? steps.value[idx].at : (steps.value[idx + 1]?.at ?? walkRoute.value.length)) - walked.value) };
});

function start() {
    if (!walkRoute.value) return;
    live.value = false;
    walked.value = 0;
    arrived.value = false;
    walking.value = true;
    paused.value = false;
    lastT = performance.now();
    cancelAnimationFrame(raf);
    raf = requestAnimationFrame(step);
}
function step(now) {
    const dt = Math.min(0.25, (now - lastT) / 1000);
    lastT = now;
    if (!paused.value) walked.value = Math.min(walkRoute.value.length, walked.value + dt * WALK_SPEED * 1.6);
    if (walked.value >= walkRoute.value.length) {
        walking.value = false;
        arrived.value = true;
        setTimeout(() => !walking.value && targetShelf.value && map.value?.focusShelf(targetShelf.value.id), 600);
        return;
    }
    raf = requestAnimationFrame(step);
}
function stop() {
    cancelAnimationFrame(raf);
    stopLive();
    walking.value = false;
    arrived.value = false;
    paused.value = false;
    walked.value = 0;
}
function jump(delta) {
    const i = Math.min(steps.value.length - 1, Math.max(0, (currentStep.value?.index ?? 0) + delta));
    walked.value = steps.value[i].at;
}
onBeforeUnmount(() => { cancelAnimationFrame(raf); stopLive(); clearInterval(headingTimer); });

// Live walking: each real step moves the reader along the route. ------------------------------
const canLive = typeof window !== 'undefined' && window.isSecureContext && sensorsSupported();
const live = ref(false);
const stepCount = ref(0);
const stepLen = ref(stepLengthStore.get());
const wrongWay = ref(false);
const sensorError = ref('');
let tracker = null;
let wrongSteps = 0;
let samples = [];
let liveStartedFrom = null;

// «السهم والخريطة يدوران مع اتجاهك» (2026-11-07, by request). The compass only says where the
// phone points once we know how the plan sits against north (learnt from the first steps on a
// straight stretch, and kept fresh on every straight step after). Until then, or while the
// reading is doubtful, the arrow keeps the route's direction, so it never shows a wrong way.
const SPIN_KEY = 'hallmap.spinMap';
const spinMap = ref((() => { try { return localStorage.getItem(SPIN_KEY) !== '0'; } catch { return true; } })());
watch(spinMap, (v) => { try { localStorage.setItem(SPIN_KEY, v ? '1' : '0'); } catch {} });
const compassState = ref('off'); // off | learning | on | doubtful
const facingDeg = ref(null); // smoothed, plan degrees clockwise from plan "up"
const facingRad = computed(() => (compassState.value === 'on' && facingDeg.value !== null ? toPlanHeading(facingDeg.value) : null));
let rawFacing = null;
let lastHeadingAt = 0;
let doubtSince = 0;
let headingTimer = null;
function onHeading(heading, { accuracy, tilt } = {}) {
    const now = performance.now();
    lastHeadingAt = now;
    // iPhone says how sure it is; a phone lying face down points nowhere useful.
    const doubtful = (accuracy !== null && accuracy !== undefined && (accuracy < 0 || accuracy > 35)) || tilt > 165;
    if (doubtful) { doubtSince ||= now; if (now - doubtSince > 600) compassState.value = 'doubtful'; return; }
    doubtSince = 0;
    const offset = compassOffset.get();
    if (offset === null) { compassState.value = 'learning'; return; }
    rawFacing = (heading - offset + 360) % 360;
    if (compassState.value !== 'on') { compassState.value = 'on'; facingDeg.value = rawFacing; }
}
// Ten updates a second, eased, so a shaky hand doesn't spin the arrow.
function easeHeading() {
    if (compassState.value === 'on' && performance.now() - lastHeadingAt > 1500) compassState.value = 'off';
    if (compassState.value !== 'on' || rawFacing === null) return;
    const d = angleDiff(rawFacing, facingDeg.value ?? rawFacing);
    if (Math.abs(d) < 2) return;
    facingDeg.value = ((facingDeg.value ?? rawFacing) + d * (Math.abs(d) > 60 ? 0.5 : 0.3) + 360) % 360;
}

async function startLive() {
    if (!walkRoute.value) return;
    sensorError.value = '';
    if (!(await requestSensors())) {
        sensorError.value = t('library.map.no_sensors', 'لم يُسمح باستخدام حساس الحركة. جرّب «معاينة الطريق» أو اسمح للموقع من إعدادات المتصفح.');
        return;
    }
    cancelAnimationFrame(raf);
    tracker?.stop();
    walked.value = 0;
    arrived.value = false;
    paused.value = false;
    walking.value = true;
    live.value = true;
    stepCount.value = 0;
    wrongWay.value = false;
    wrongSteps = 0;
    samples = [];
    liveStartedFrom = { ...origin.value };
    compassState.value = compassOffset.get() === null ? 'learning' : 'off';
    facingDeg.value = null;
    rawFacing = null;
    clearInterval(headingTimer);
    headingTimer = setInterval(easeHeading, 100);
    tracker = createStepTracker({ onStep, onHeading });
}
function stopLive() {
    tracker?.stop();
    tracker = null;
    clearInterval(headingTimer);
    headingTimer = null;
    compassState.value = 'off';
    facingDeg.value = null;
    live.value = false;
    wrongWay.value = false;
}
function onStep({ heading }) {
    if (!walking.value || paused.value || !walkRoute.value) return;
    const pos = pointAt(walkRoute.value, walked.value + 0.05);
    const segBearing = planBearing(Math.cos(pos.heading), Math.sin(pos.heading));
    let offset = compassOffset.get();
    if (heading !== null && heading !== undefined) {
        // Learn how the plan sits against north from the first straight stretch.
        if (offset === null && walked.value < (steps.value[1]?.at ?? walkRoute.value.length) - 0.5) {
            samples.push(heading);
            if (samples.length >= 5) { offset = (circularMean(samples) - segBearing + 360) % 360; compassOffset.set(offset); }
        }
        if (offset !== null) {
            const facing = (heading - offset + 360) % 360;
            const off = angleDiff(facing, segBearing);
            if (Math.abs(off) > 110) {
                wrongSteps++;
                if (wrongSteps >= 3) wrongWay.value = true;
                return;
            }
            // Walking straight along the route: nudge the learnt offset, so a different library,
            // a phone held a little askew or a nearby steel bookcase doesn't leave it wrong.
            const nearTurn = steps.value.some((st) => Math.abs(st.at - walked.value) < 1.2);
            if (Math.abs(off) < 30 && !nearTurn) compassOffset.set((offset + off * 0.08 + 360) % 360);
        }
    }
    wrongSteps = 0;
    wrongWay.value = false;
    stepCount.value++;
    walked.value = Math.min(walkRoute.value.length, walked.value + stepLen.value);
    if (walked.value >= walkRoute.value.length - 0.4) {
        walked.value = walkRoute.value.length;
        stopLive();
        walking.value = false;
        arrived.value = true;
        navigator.vibrate?.([80, 60, 80]);
        setTimeout(() => targetShelf.value && map.value?.focusShelf(targetShelf.value.id), 600);
    }
}
function changeStep(delta) {
    stepLen.value = Math.round(Math.min(0.95, Math.max(0.4, stepLen.value + delta)) * 100) / 100;
    stepLengthStore.set(stepLen.value);
}

// Scanning a sticker: «أنت هنا» (and, while walking, a correction of the step length). ---------
const scanning = ref(false);
const scanNote = ref('');
/** «أنت الآن عند الرف A3» or, for a wall sticker, «أنت الآن عند ملصق QR5 (بجانب باب 2)». */
const atText = (s) => (s.kind === 'qr'
    ? t('library.map.you_are_at_sticker', 'أنت الآن عند :code', { code: [s.code, s.name].filter(Boolean).join(' · ') })
    : t('hallmap.you_are_at', 'أنت الآن عند :code', { code: s.code }));

// «أين أقرب ملصق؟» (2026-11-06, by request): the stickers around you light up on the map, the
// nearest one with a bouncing arrow and «امسحني», so you know where to point the camera.
const findQr = ref(false);
const qrFrom = computed(() => {
    const a = viewAvatar.value;
    if (a) return a;
    const h = here.value;
    if (h && storeyOf(h) === viewStorey.value) return h;
    if (viewStorey.value === 0) return props.floor.entrance;
    return { x: props.floor.width / 2, y: props.floor.depth / 2 };
});
const qrNearby = computed(() => (findQr.value ? nearestStickers(viewShelves.value.filter(isSticker), qrFrom.value).slice(0, 6) : []));
const beacons = computed(() => (qrNearby.value.length ? { ids: qrNearby.value.map((n) => n.s.id), nearest: qrNearby.value[0].s.id } : null));
function showStickers() {
    scanning.value = false;
    findQr.value = true;
    const n = qrNearby.value[0];
    if (n && !walking.value) setTimeout(() => map.value?.focusShelf(n.s.id), 60);
}
const stickerName = (s) => (s.kind === 'qr' ? [s.code, s.name].filter(Boolean).join(' · ') : t('hallmap.pc_sticker', 'ملصق الحاسبة :code', { code: s.code }));

function onScan(text) {
    scanning.value = false;
    findQr.value = false;
    let id = null;
    try { id = Number(new URL(text, window.location.origin).searchParams.get('at')) || null; } catch {}
    const shelf = id ? shelfById.value[id] : null;
    if (!shelf) { scanNote.value = t('hallmap.scan_unknown', 'هذا الرمز ليس من ملصقات خريطة القاعات.'); return; }
    navigator.vibrate?.(60);
    const wasLive = live.value && walking.value;
    if (wasLive && stepCount.value >= 8 && liveStartedFrom) {
        // We now know how far they really walked: tune the step length to it.
        const real = findRoute(props.floor, props.shelves, liveStartedFrom, shelf, routeOpts.value)?.length;
        const len = real ? real / stepCount.value : null;
        if (len && len >= 0.4 && len <= 0.95) { stepLen.value = Math.round(len * 100) / 100; stepLengthStore.set(stepLen.value); }
    }
    placeAt(shelf);
    scanNote.value = atText(shelf);
    setTimeout(() => (scanNote.value = ''), 3500);
    try { const u = new URL(window.location.href); u.searchParams.set('at', shelf.id); window.history.replaceState(window.history.state, '', u); } catch {}
    if (wasLive) {
        // Carry on from the sticker, with the route redrawn from here.
        walked.value = 0;
        stepCount.value = 0;
        liveStartedFrom = { ...origin.value };
        if (targetShelf.value?.id === shelf.id) { stopLive(); walking.value = false; arrived.value = true; }
    } else {
        stop();
        map.value?.focusShelf(shelf.id);
    }
}

const fromText = computed(() => (here.value ? t('library.map.from_here', ':d م من موقعك', { d: metres(walkRoute.value?.length ?? 0) }) : t('library.map.distance', ':d م مشياً من المدخل', { d: metres(walkRoute.value?.length ?? 0) })));
const highlight = computed(() => (targetShelf.value ? { id: targetShelf.value.id, level: null, cover: null } : null));

// What the 3D view shows: one floor of the building, and with «كل قاعة مستقلة» one hall. -------
const viewStorey = ref(storeyOf(here.value ?? targetShelf.value ?? null));
const viewHall = ref(null); // hall id, or null for the whole floor
const hallMenu = ref(false);
const separate = computed(() => !!props.floor.separate_halls && halls.value.length > 1);
const storeyHalls = computed(() => halls.value.filter((h) => storeyOf(h) === viewStorey.value && h.kind === 'hall'));
function showPlace(p) {
    if (!p) return;
    viewStorey.value = storeyOf(p);
    if (separate.value) viewHall.value = hallOf(p)?.id ?? null;
}
showPlace(here.value ?? targetShelf.value);
watch(targetShelf, (s) => { if (s && !walking.value) showPlace(s); });
watch(here, (h) => h && !walking.value && showPlace(h));
// While walking, the view follows the walker up the stairs and from hall to hall.
watch(() => (walking.value ? avatar.value : null), (a) => {
    if (!a || a.onStairs) return;
    if (storeyOf(a) !== viewStorey.value) viewStorey.value = storeyOf(a);
    if (separate.value) { const h = hallOf(a); if (h && h.id !== viewHall.value) viewHall.value = h.id; }
});
function nearOutline(item, hall) {
    const poly = outline(hall);
    return poly.some((a, i) => {
        const b = poly[(i + 1) % poly.length], dx = b.x - a.x, dy = b.y - a.y;
        const t = Math.max(0, Math.min(1, ((item.x - a.x) * dx + (item.y - a.y) * dy) / (dx * dx + dy * dy || 1)));
        return Math.hypot(item.x - (a.x + t * dx), item.y - (a.y + t * dy)) < 0.5;
    });
}
const viewShelves = computed(() => {
    const onFloor = props.shelves.filter((s) => storeyOf(s) === viewStorey.value);
    const hall = viewHall.value ? shelfById.value[viewHall.value] : null;
    if (!hall || storeyOf(hall) !== viewStorey.value) return onFloor;
    return onFloor.filter((s) => s.id === hall.id || (s.kind === 'door' ? nearOutline(s, hall) : !isHall(s) && hallOf(s)?.id === hall.id));
});
const viewRoute = computed(() => routeOnStorey(walkRoute.value, viewStorey.value));
const viewAvatar = computed(() => (avatar.value && storeyOf(avatar.value) === viewStorey.value ? avatar.value : null));
const viewHighlight = computed(() => (highlight.value && viewShelves.value.some((s) => s.id === highlight.value.id) ? highlight.value : null));
watch([viewStorey, viewHall], () => setTimeout(() => {
    if (walking.value) return;
    if (viewHighlight.value) map.value?.focusShelf(viewHighlight.value.id);
    else map.value?.overview();
}, 80));
</script>

<template>
    <Head :title="t('hallmap.title', 'خريطة القاعات')" />

    <component :is="Layout">
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('exams.monitor.index')" class="text-gray-400 hover:text-gray-600"><ChevronRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('hallmap.title', 'خريطة القاعات') }}</h2>
                <select v-if="exams.length" v-model="examFilter" class="ms-2 max-w-[40vw] rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700">
                    <option value="">{{ t('hallmap.all_exams', 'كل الامتحانات الجارية') }}</option>
                    <option v-for="e in exams" :key="e.id" :value="e.id">{{ e.title }}</option>
                </select>
                <button @click="alerts.toggle()" class="ms-auto rounded-lg border border-gray-300 p-1.5 dark:border-gray-600" :title="alerts.enabled.value ? t('exam.alerts_on', 'التنبيهات مفعّلة') : t('exam.alerts_off', 'التنبيهات متوقفة')">
                    <component :is="alerts.enabled.value ? BellAlertIcon : BellSlashIcon" class="h-4 w-4" />
                </button>
                <Link v-if="canEdit" :href="route('exams.monitor.halls.edit')" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                    <PencilSquareIcon class="h-4 w-4" /> <span class="hidden sm:inline">{{ t('hallmap.edit', 'رسم القاعات') }}</span>
                </Link>
            </div>
        </template>

        <div :class="embedded ? '' : 'space-y-4 p-4 sm:p-6'">

            <div v-if="!bookcases.length" class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-600 dark:bg-gray-800">
                <MapIcon class="mx-auto h-12 w-12 text-gray-300" />
                <p class="mt-3 font-semibold">{{ t('hallmap.empty', 'لم تُرسم خريطة القاعات بعد') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ t('hallmap.empty_text', 'ارسم القاعات وضع الحاسبات في أماكنها، فتظهر هنا ثلاثية الأبعاد بحالة كل حاسبة لحظة بلحظة، مع طريق الوصول إليها.') }}</p>
                <Link v-if="canEdit" :href="route('exams.monitor.halls.edit')" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm font-medium text-white">
                    <PencilSquareIcon class="h-5 w-5" /> {{ t('hallmap.start_editing', 'ابدأ رسم القاعات') }}
                </Link>
            </div>

            <Teleport v-else to="body" :disabled="!full">
            <div class="overflow-hidden bg-gray-100 dark:bg-gray-900" :class="full ? 'fixed inset-0 z-40 h-dvh' : embedded ? 'relative h-dvh' : 'relative h-[calc(100dvh-10rem)] min-h-[460px] sm:h-[calc(100dvh-13rem)] rounded-2xl border border-gray-200 shadow-sm dark:border-gray-700'">
                <HallMap2D v-if="flat" ref="map" :floor="floor" :shelves="viewShelves" :statuses="statuses" :highlight="viewHighlight" :route="viewRoute" :avatar="viewAvatar" :beacons="beacons" :follow="walking && !!viewAvatar" @pick="onPick" @ready="onReady" />
                <HallMap3D v-else ref="map" :floor="floor" :shelves="viewShelves" :statuses="statuses" :highlight="viewHighlight" :route="viewRoute" :avatar="viewAvatar" :beacons="beacons" :follow="walking && !!viewAvatar" @pick="onPick" @ready="onReady" @failed="setFlat(true)" />

                <!-- Search, or the Waze-style instruction banner while walking. -->
                <div class="pointer-events-none absolute inset-x-0 top-0 p-3">
                    <div v-if="walking || arrived" class="pointer-events-auto mx-auto flex max-w-xl items-center gap-3 rounded-2xl px-4 py-3 text-white shadow-lg" :class="arrived ? 'bg-emerald-600' : 'bg-[#1f2a44]'">
                        <Maneuver :type="arrived ? 'arrive' : currentStep?.type" class="h-12 w-12 shrink-0" />
                        <div class="min-w-0 flex-1">
                            <template v-if="arrived">
                                <p class="text-lg font-bold leading-tight">{{ t('library.map.arrived', 'وصلت') }}</p>
                                <p class="text-sm opacity-90">{{ stepText(steps[steps.length - 1]) }}</p>
                            </template>
                            <template v-else>
                                <p v-if="currentStep && !currentStep.type.startsWith('arrive')" class="text-2xl font-extrabold leading-none">{{ metres(currentStep.toNext) }} م</p>
                                <p class="mt-0.5 truncate text-sm font-medium opacity-95">{{ currentStep ? stepText(currentStep) : '' }}</p>
                                <p v-if="live" class="mt-1 flex items-center gap-1.5 text-[11px] opacity-80">
                                    <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-400" />
                                    {{ t('library.map.live_on', 'يتابع خطواتك · :n خطوة', { n: stepCount }) }}
                                    <template v-if="compassState === 'learning'"> · {{ t('library.map.compass_learning', 'امشِ بضع خطوات مستقيمة ليتعرّف على اتجاهك') }}</template>
                                    <template v-else-if="compassState === 'doubtful'"> · {{ t('library.map.compass_doubtful', 'البوصلة غير دقيقة: امسك الهاتف أمامك، أو حرّكه على شكل 8') }}</template>
                                    <template v-else-if="compassState === 'on'"> · {{ t('library.map.compass_on', 'السهم يتبع اتجاهك') }}</template>
                                </p>
                            </template>
                        </div>
                        <button @click="stop" class="rounded-full bg-white/15 p-2 hover:bg-white/25" :aria-label="t('common.close', 'إغلاق')"><XMarkIcon class="h-5 w-5" /></button>
                    </div>
                    <div v-if="wrongWay" class="pointer-events-auto mx-auto mt-2 flex max-w-xl items-center gap-2 rounded-xl bg-amber-500 px-3 py-2 text-sm font-semibold text-white shadow">
                        <ExclamationTriangleIcon class="h-5 w-5 shrink-0" /> {{ t('library.map.wrong_way', 'يبدو أنك تمشي بالاتجاه المعاكس. استدر واتبع السهم.') }}
                    </div>

                    <div v-if="!(walking || arrived)" class="pointer-events-auto relative mx-auto max-w-xl">
                        <div class="flex items-center gap-2 rounded-2xl bg-white/95 px-3 py-2 shadow-lg ring-1 ring-black/5 backdrop-blur dark:bg-gray-800/95 dark:ring-white/10">
                            <MagnifyingGlassIcon class="h-5 w-5 shrink-0 text-gray-400" />
                            <input v-model="q" @focus="showResults = true" type="search" :placeholder="t('hallmap.search', 'ابحث عن حاسبة أو طالب…')"
                                class="min-w-0 flex-1 border-0 bg-transparent p-1 text-sm focus:ring-0 dark:text-gray-100" />
                            <ArrowPathIcon v-if="searching" class="h-4 w-4 animate-spin text-gray-400" />
                            <button @click="showProblems = !showProblems" class="inline-flex items-center gap-1 rounded-xl px-2.5 py-1.5 text-xs font-semibold" :class="problemList.length ? 'bg-red-600 text-white' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'">
                                <ExclamationTriangleIcon v-if="problemList.length" class="h-4 w-4" /><CheckCircleIcon v-else class="h-4 w-4" /> {{ problemList.length }}
                            </button>
                            <button @click="scanning = true" class="-me-1 inline-flex items-center gap-1 rounded-xl bg-gray-100 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200" :title="t('hallmap.scan', 'امسح ملصق أي حاسبة لتحديد موقعك')">
                                <QrCodeIcon class="h-4 w-4" /> <span class="hidden sm:inline">{{ t('library.map.where_am_i', 'أين أنا؟') }}</span>
                            </button>
                        </div>
                        <div v-if="here && !(showResults && q.trim().length >= 2)" class="mt-2 flex w-fit items-center gap-2 rounded-full bg-blue-600 px-3 py-1.5 text-xs font-medium text-white shadow">
                            <MapPinIcon class="h-4 w-4" /> {{ atText(shelfById[here.shelfId] ?? { code: here.code }) }}
                            <button @click="resetHere" class="-me-1 rounded-full p-0.5 hover:bg-white/20" :aria-label="t('library.map.from_entrance', 'من المدخل')"><XMarkIcon class="h-4 w-4" /></button>
                        </div>
                        <div v-if="showResults && q.trim().length >= 1" class="absolute inset-x-0 top-full z-20 mt-2 max-h-[50vh] overflow-y-auto rounded-2xl bg-white shadow-xl ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <p v-if="!results.length" class="p-4 text-center text-sm text-gray-500">{{ t('library.no_results', 'لا توجد نتائج') }}</p>
                            <button v-for="c in results" :key="c.id" @click="choosePc(c)" class="flex w-full items-center gap-3 border-b border-gray-100 px-3 py-2.5 text-start last:border-0 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/60">
                                <span class="h-3 w-3 shrink-0 rounded-full" :style="{ background: (STATES[c.state] ?? STATES.off).color }" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold">{{ c.label }} <span class="font-mono text-xs font-normal text-gray-500" dir="ltr">{{ c.ip }}</span></span>
                                    <span class="block truncate text-xs text-gray-500">{{ [(STATES[c.state] ?? STATES.off).label(), c.student?.name ?? c.expected?.name, c.room].filter(Boolean).join(' · ') }}</span>
                                    <span v-if="!c.item" class="text-[11px] text-amber-600">{{ t('hallmap.not_on_map', 'غير موضوعة على الخريطة') }}</span>
                                </span>
                            </button>
                        </div>
                        <!-- The problems now -->
                        <div v-if="showProblems" class="absolute inset-x-0 top-full z-20 mt-2 max-h-[55vh] overflow-y-auto rounded-2xl bg-white shadow-xl ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <p v-if="!problemList.length" class="flex items-center justify-center gap-2 p-4 text-sm text-emerald-700 dark:text-emerald-300"><CheckCircleIcon class="h-5 w-5" /> {{ t('hallmap.no_problems', 'لا مشاكل الآن') }}</p>
                            <button v-for="c in problemList" :key="c.id" @click="choosePc(c)" class="flex w-full items-start gap-3 border-b border-gray-100 px-3 py-2.5 text-start last:border-0 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/60">
                                <span class="mt-1 h-3 w-3 shrink-0 rounded-full" :style="{ background: (STATES[c.state] ?? STATES.off).color }" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold">{{ c.label }}<span v-if="c.room" class="font-normal text-gray-500"> · {{ c.room }}</span><span v-if="c.student || c.expected" class="font-normal text-gray-500"> · {{ (c.student ?? c.expected).name }}</span></span>
                                    <span v-for="p in c.problems" :key="p" class="block text-xs text-red-700 dark:text-red-300">{{ PROBLEMS[p]?.() ?? p }}<template v-if="p === 'clock'"> ({{ clockText(c.clock) }})</template></span>
                                    <span v-if="!c.item" class="text-[11px] text-amber-600">{{ t('hallmap.not_on_map', 'غير موضوعة على الخريطة') }}</span>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                <p v-if="scanNote" class="absolute inset-x-0 top-28 z-10 mx-auto w-fit rounded-full bg-gray-900/90 px-4 py-2 text-sm text-white shadow-lg">{{ scanNote }}</p>
                <QrScanner v-if="scanning" @result="onScan" @close="scanning = false" @where="showStickers" />
                <!-- «أين أقرب ملصق؟» -->
                <div v-if="findQr" class="absolute inset-x-0 z-10 mx-auto max-w-xl px-3" :class="walking || arrived ? 'top-36' : 'top-20'">
                    <div class="flex items-center gap-3 rounded-2xl bg-orange-500 px-3 py-2.5 text-white shadow-lg">
                        <span class="relative flex h-9 w-9 shrink-0 items-center justify-center">
                            <span class="absolute inset-0 animate-ping rounded-full bg-white/40" />
                            <QrCodeIcon class="relative h-6 w-6" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <template v-if="qrNearby.length">
                                <p class="text-sm font-bold leading-tight">{{ t('library.map.nearest_sticker', 'أقرب ملصق: :name', { name: stickerName(qrNearby[0].s) }) }}</p>
                                <p class="text-xs opacity-90">{{ t('library.map.nearest_sticker_hint', 'حوالي :d م · المكان المضيء بالبرتقالي على الخريطة، والدائرة على الأرض حيث تقف.', { d: metres(qrNearby[0].d) }) }}</p>
                            </template>
                            <p v-else class="text-sm">{{ t('library.map.no_stickers_here', 'لا توجد ملصقات في هذا الجزء من الخريطة.') }}</p>
                        </div>
                        <button v-if="qrNearby.length" @click="scanning = true" class="shrink-0 rounded-full bg-white px-3 py-2 text-xs font-bold text-orange-600">{{ t('library.map.open_camera', 'امسح') }}</button>
                        <button @click="findQr = false" class="shrink-0 rounded-full bg-white/20 p-1.5" :aria-label="t('common.close', 'إغلاق')"><XMarkIcon class="h-4 w-4" /></button>
                    </div>
                </div>

                <!-- Floor of the building and, with «كل قاعة مستقلة», the hall shown. -->
                <div v-if="(multiStorey || separate) && !walking" class="absolute start-3 top-24 flex max-w-[45%] flex-col items-start gap-1.5">
                    <div v-if="multiStorey" class="flex flex-col overflow-hidden rounded-xl bg-white/95 shadow ring-1 ring-black/5 dark:bg-gray-800/95 dark:ring-white/10">
                        <button v-for="(n, i) in storeys" :key="i" @click="viewStorey = storeys.length - 1 - i; viewHall = null"
                            class="px-3 py-1.5 text-start text-xs font-semibold" :class="viewStorey === storeys.length - 1 - i ? 'bg-[#1f2a44] text-white' : 'hover:bg-gray-100 dark:hover:bg-gray-700'">
                            {{ storeyName(storeys.length - 1 - i) }}
                        </button>
                    </div>
                    <div v-if="separate && storeyHalls.length > 1" class="relative">
                        <button @click="hallMenu = !hallMenu" class="flex max-w-full items-center gap-1 truncate rounded-xl bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow" dir="auto">
                            {{ viewHall ? shelfById[viewHall]?.code : t('library.map.all_halls', 'كل القاعات') }} <span class="opacity-80">▾</span>
                        </button>
                        <div v-if="hallMenu" class="mt-1 flex max-h-56 flex-col overflow-y-auto rounded-xl bg-white/95 shadow ring-1 ring-black/5 dark:bg-gray-800/95 dark:ring-white/10">
                            <button @click="viewHall = null; hallMenu = false" class="px-3 py-1.5 text-start text-xs" :class="!viewHall ? 'font-semibold text-blue-600' : 'hover:bg-gray-100 dark:hover:bg-gray-700'">{{ t('library.map.all_halls', 'كل القاعات') }}</button>
                            <button v-for="h in storeyHalls" :key="h.id" @click="viewHall = h.id; hallMenu = false" class="truncate px-3 py-1.5 text-start text-xs" :class="viewHall === h.id ? 'font-semibold text-blue-600' : 'hover:bg-gray-100 dark:hover:bg-gray-700'" dir="auto">{{ h.code }}</button>
                        </div>
                    </div>
                </div>

                <!-- Camera buttons. -->
                <div class="absolute end-3 top-24 flex flex-col gap-2">
                    <button v-if="!embedded" @click="toggleFull" class="rounded-xl bg-white/95 p-2.5 shadow ring-1 ring-black/5 hover:bg-white dark:bg-gray-800/95 dark:ring-white/10" :title="full ? t('library.map.exit_full', 'خروج من ملء الشاشة') : t('library.map.full', 'ملء الشاشة')">
                        <component :is="full ? ArrowsPointingInIcon : ArrowsPointingOutIcon" class="h-5 w-5" />
                    </button>
                    <button v-if="walking" @click="spinMap = !spinMap" class="rounded-xl p-2.5 shadow ring-1" :class="spinMap ? 'bg-blue-600 text-white ring-blue-700' : 'bg-white/95 ring-black/5 hover:bg-white dark:bg-gray-800/95 dark:ring-white/10'"
                        :title="spinMap ? t('library.map.spin_on', 'الخريطة تدور مع اتجاهك (اضغط لتثبيتها)') : t('library.map.spin_off', 'الخريطة ثابتة (اضغط لتدور مع اتجاهك)')" :aria-pressed="spinMap">
                        <svg viewBox="0 0 24 24" class="h-5 w-5"><circle cx="12" cy="12" r="9.5" fill="none" stroke="currentColor" stroke-width="1.5" /><path d="M12 4.5 15 12h-6z" fill="#ef4444" /><path d="M12 19.5 9 12h6z" fill="currentColor" opacity=".55" /></svg>
                    </button>
                    <button @click="setFlat(!flat)" class="rounded-xl bg-white/95 p-2.5 shadow ring-1 ring-black/5 hover:bg-white dark:bg-gray-800/95 dark:ring-white/10" :title="flat ? t('library.map.to_3d', 'عرض ثلاثي الأبعاد') : t('library.map.to_2d', 'خريطة مسطحة (أخف)')">
                        <component :is="flat ? CubeIcon : Squares2X2Icon" class="h-5 w-5" />
                    </button>
                    <button @click="map?.overview()" class="rounded-xl bg-white/95 p-2.5 shadow ring-1 ring-black/5 hover:bg-white dark:bg-gray-800/95 dark:ring-white/10" :title="t('hallmap.overview', 'عرض القاعات كاملة')"><MapIcon class="h-5 w-5" /></button>
                    <button v-if="targetShelf" @click="map?.focusShelf(targetShelf.id)" class="rounded-xl bg-white/95 p-2.5 shadow ring-1 ring-black/5 hover:bg-white dark:bg-gray-800/95 dark:ring-white/10" :title="t('hallmap.show_pc', 'اعرض الحاسبة')"><ViewfinderCircleIcon class="h-5 w-5" /></button>
                </div>

                <!-- Bottom sheet: the chosen book or bookcase, and the route summary. -->
                <div v-if="pickedShelf" class="absolute inset-x-0 bottom-0 p-3">
                    <div class="mx-auto max-w-xl rounded-2xl bg-white p-4 shadow-2xl ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div v-if="!walking" class="flex items-start gap-3">
                            <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white" :style="{ background: pickedShelf.kind === 'pc' ? statuses[pickedShelf.id]?.color : '#64748b' }"><ComputerDesktopIcon class="h-6 w-6" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="font-bold">{{ pickedShelf.code }}<span v-if="pickedShelf.computer" class="ms-1 font-mono text-xs font-normal text-gray-500" dir="ltr">{{ pickedShelf.computer.ip }}</span></p>
                                <p class="truncate text-sm text-gray-500">{{ [pickedShelf.computer?.room ?? pickedShelf.name, hallName(pickedShelf.id)].filter(Boolean).join(' · ') }}</p>
                                <template v-if="pickedShelf.kind === 'pc'">
                                    <p v-if="!pickedShelf.computer_id" class="mt-1 text-sm text-amber-700 dark:text-amber-400">{{ t('hallmap.unlinked', 'هذه الحاسبة على الخريطة غير مربوطة بحاسبة مسجّلة. اربطها من «رسم القاعات».') }}</p>
                                    <template v-else-if="pickedPc">
                                        <p class="mt-1.5 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" :style="{ background: (STATES[pickedPc.state] ?? STATES.off).color }">{{ (STATES[pickedPc.state] ?? STATES.off).label() }}</p>
                                        <ul v-if="pickedPc.problems.length" class="mt-1.5 space-y-0.5 text-sm text-red-700 dark:text-red-300">
                                            <li v-for="p in pickedPc.problems" :key="p" class="flex items-start gap-1"><ExclamationTriangleIcon class="mt-0.5 h-4 w-4 shrink-0" /> {{ PROBLEMS[p]?.() ?? p }}<template v-if="p === 'clock'"> ({{ clockText(pickedPc.clock) }})</template></li>
                                        </ul>
                                        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                            <template v-if="pickedPc.student"><dt class="text-gray-500">{{ t('hallmap.student', 'الطالب') }}</dt><dd class="font-medium">{{ pickedPc.student.name }}<span v-if="pickedPc.student.number" class="text-gray-500"> · {{ pickedPc.student.number }}</span></dd></template>
                                            <template v-if="pickedPc.expected && pickedPc.expected.id !== pickedPc.student?.id"><dt class="text-gray-500">{{ t('hallmap.expected', 'المخصّص لها') }}</dt><dd>{{ pickedPc.expected.name }}</dd></template>
                                            <template v-if="pickedPc.exam"><dt class="text-gray-500">{{ t('hallmap.exam', 'الامتحان') }}</dt><dd class="truncate">{{ pickedPc.exam.title }}</dd></template>
                                            <template v-if="pickedPc.attempt?.seconds_left !== null && pickedPc.attempt?.seconds_left !== undefined"><dt class="text-gray-500">{{ t('hallmap.left', 'الوقت المتبقي') }}</dt><dd class="font-mono" dir="ltr">{{ fmtLeft(pickedPc.attempt.seconds_left) }}</dd></template>
                                            <template v-if="pickedPc.attempt"><dt class="text-gray-500">{{ t('hallmap.answered', 'أجاب عن') }}</dt><dd>{{ pickedPc.attempt.answered }}</dd></template>
                                            <dt class="text-gray-500">{{ t('hallmap.last_seen', 'آخر اتصال') }}</dt><dd>{{ ago(pickedPc.seen) }}</dd>
                                            <template v-if="pickedPc.clock !== null && pickedPc.clock !== undefined"><dt class="text-gray-500"><ClockIcon class="inline h-3.5 w-3.5" /> {{ t('hallmap.clock', 'ساعة الحاسبة') }}</dt><dd :class="Math.abs(pickedPc.clock) > 60 ? 'font-semibold text-red-600' : ''">{{ Math.abs(pickedPc.clock) <= 60 ? t('hallmap.clock_ok', 'مضبوطة') : clockText(pickedPc.clock) }}</dd></template>
                                            <template v-if="pickedPc.attempt?.locked?.code"><dt class="text-gray-500">{{ t('exam.resume_code', 'رمز المتابعة') }}</dt><dd class="font-mono font-bold" dir="ltr">{{ pickedPc.attempt.locked.code }}</dd></template>
                                        </dl>
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            <button v-if="pickedPc.attempt?.help" @click="act('exams.monitor.help-done', pickedPc)" class="inline-flex items-center gap-1 rounded-full bg-amber-400 px-3 py-1 text-xs font-semibold text-amber-950"><HandRaisedIcon class="h-4 w-4" /> {{ t('exam.help_done', 'تمّت المساعدة') }}</button>
                                            <button v-if="pickedPc.attempt?.locked" @click="act('exams.monitor.unlock', pickedPc)" class="inline-flex items-center gap-1 rounded-full bg-red-600 px-3 py-1 text-xs font-semibold text-white"><LockOpenIcon class="h-4 w-4" /> {{ t('exam.unlock', 'فتح') }}</button>
                                            <Link v-if="pickedPc.exam" :href="route('exams.monitor.show', pickedPc.exam.id)" class="inline-flex items-center gap-1 rounded-full border border-gray-300 px-3 py-1 text-xs dark:border-gray-600"><ArrowTopRightOnSquareIcon class="h-4 w-4" /> {{ t('hallmap.open_monitor', 'مراقبة الامتحان') }}</Link>
                                        </div>
                                    </template>
                                </template>
                            </div>
                            <button @click="clearSelection" class="-m-1 rounded-full p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><XMarkIcon class="h-5 w-5" /></button>
                        </div>

                        <div v-if="walkRoute" class="flex items-center gap-3" :class="walking ? '' : 'mt-3 border-t border-gray-100 pt-3 dark:border-gray-700'">
                            <div class="min-w-0 flex-1">
                                <template v-if="walking && live">
                                    <p class="whitespace-nowrap text-lg font-extrabold text-emerald-600 dark:text-emerald-400">{{ metres(Math.max(0, walkRoute.length - walked)) }} م</p>
                                    <p class="whitespace-nowrap text-xs text-gray-500">{{ t('library.map.remaining', 'متبقٍ') }}</p>
                                </template>
                                <template v-else>
                                    <p class="text-lg font-extrabold text-emerald-600 dark:text-emerald-400">{{ eta }}</p>
                                    <p class="text-xs text-gray-500">{{ fromText }}</p>
                                </template>
                            </div>
                            <template v-if="walking && live">
                                <div class="flex items-center rounded-full border border-gray-300 text-xs dark:border-gray-600" :title="t('library.map.step_length', 'طول خطوتك')">
                                    <button @click="changeStep(-0.05)" class="p-1.5"><MinusIcon class="h-3.5 w-3.5" /></button>
                                    <span dir="ltr" class="tabular-nums">{{ stepLen.toFixed(2) }} m</span>
                                    <button @click="changeStep(0.05)" class="p-1.5"><PlusIcon class="h-3.5 w-3.5" /></button>
                                </div>
                                <button @click="scanning = true" class="rounded-full border border-gray-300 p-2 dark:border-gray-600" :title="t('hallmap.scan', 'امسح ملصق أي حاسبة لتحديد موقعك')"><QrCodeIcon class="h-5 w-5" /></button>
                                <button @click="jump(1)" class="rounded-full bg-[#1f2a44] px-3 py-2 text-xs font-semibold text-white">{{ t('library.map.at_turn', 'وصلت للانعطاف') }}</button>
                            </template>
                            <template v-else-if="walking">
                                <button @click="jump(-1)" class="rounded-full border border-gray-300 p-2 dark:border-gray-600" :aria-label="t('library.map.prev', 'الخطوة السابقة')"><ChevronRightIcon class="h-5 w-5 ltr:rotate-180" /></button>
                                <button @click="paused = !paused" class="rounded-full bg-[#1f2a44] p-2.5 text-white"><component :is="paused ? PlayIcon : PauseIcon" class="h-5 w-5" /></button>
                                <button @click="jump(1)" class="rounded-full border border-gray-300 p-2 dark:border-gray-600" :aria-label="t('library.map.next', 'الخطوة التالية')"><ChevronLeftIcon class="h-5 w-5 ltr:rotate-180" /></button>
                            </template>
                            <template v-else>
                                <button v-if="canLive" @click="start" class="rounded-full border border-gray-300 px-3 py-2 text-xs font-medium dark:border-gray-600">{{ t('library.map.preview', 'معاينة') }}</button>
                                <button @click="canLive ? startLive() : start()" class="inline-flex items-center gap-2 rounded-full bg-[#33ccff] px-5 py-2.5 text-sm font-bold text-[#0b1f33] shadow hover:brightness-105">
                                    <component :is="arrived ? ArrowPathIcon : PlayIcon" class="h-5 w-5" /> {{ arrived ? t('library.map.again', 'أعد الطريق') : canLive ? t('library.map.walk', 'ابدأ المشي') : t('library.map.go', 'خذني إليه') }}
                                </button>
                            </template>
                        </div>
                        <label v-if="hasLift && otherFloor && !walking" class="mt-2 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <input v-model="liftOnly" type="checkbox" class="rounded border-gray-300" /> ♿ {{ t('library.map.lift_only', 'بدون درج (مصعد فقط)') }}
                        </label>
                        <p v-if="targetShelf?.kind === 'pc' && !walkRoute && !walking" class="mt-2 text-sm text-amber-700 dark:text-amber-400">{{ routeOpts.liftOnly ? t('hallmap.no_lift_route', 'لا يوجد طريق بالمصعد إلى هذه الحاسبة.') : t('hallmap.no_route', 'تعذّر إيجاد طريق إلى هذه الحاسبة.') }}</p>
                        <p v-if="sensorError" class="mt-2 text-xs text-red-600">{{ sensorError }}</p>
                        <p v-if="walkRoute && !walking && !arrived && canLive" class="mt-2 text-[11px] leading-5 text-gray-500">{{ t('hallmap.live_hint', 'امسك الهاتف أمامك وامشِ: السهم يتقدم مع خطواتك. للدقة امسح ملصق QR على أي حاسبة تمر بها.') }}</p>

                        <!-- All the steps, Waze's list view. -->
                        <button v-if="walkRoute && !walking" @click="showSteps = !showSteps" class="mt-2 text-xs font-medium text-blue-600 sm:hidden dark:text-blue-400">{{ showSteps ? t('library.map.hide_steps', 'إخفاء الخطوات') : t('library.map.show_steps', 'عرض الخطوات (:n)', { n: steps.length }) }}</button>
                        <ol v-if="walkRoute && !walking" class="mt-2 max-h-36 space-y-1.5 overflow-y-auto text-sm sm:mt-3 sm:block" :class="showSteps ? 'block' : 'hidden'">
                            <li v-for="(s, i) in steps" :key="i" class="flex items-center gap-2.5">
                                <Maneuver :type="s.type" class="h-5 w-5 shrink-0" :class="s.type.startsWith('arrive') ? 'text-red-500' : 'text-blue-600 dark:text-blue-400'" />
                                <span class="flex-1">{{ stepText(s) }}</span>
                                <CheckCircleIcon v-if="arrived" class="h-4 w-4 text-emerald-500" />
                            </li>
                        </ol>
                    </div>
                </div>

                <!-- The colours, with how many computers are in each state. -->
                <div v-else class="pointer-events-none absolute inset-x-0 bottom-0 p-3">
                    <div class="pointer-events-auto mx-auto flex max-w-3xl flex-wrap justify-center gap-1.5 rounded-2xl bg-white/90 px-3 py-2 text-xs shadow dark:bg-gray-800/90">
                        <span v-for="l in legend" :key="l.key" class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full" :style="{ background: l.color }" /> {{ l.label() }} <b>{{ l.n }}</b></span>
                        <span v-if="!legend.length" class="text-gray-500">{{ t('hallmap.no_computers', 'لا توجد حاسبات مسجّلة بعد') }}</span>
                        <span v-if="unplaced" class="text-amber-600">· {{ t('hallmap.unplaced', ':n حاسبة غير موضوعة على الخريطة', { n: unplaced }) }}</span>
                    </div>
                </div>
            </div>
            </Teleport>
        </div>
    </component>
</template>
