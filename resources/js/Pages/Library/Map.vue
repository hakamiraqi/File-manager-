<script setup>
// «خريطة المكتبة» (2026-11-06, by request): search a book, see its bookcase in a 3D model of the
// reading room and follow the walk from the entrance, Waze style. Since 2026-11-06 (by request)
// the reader can also say where they are by scanning the QR sticker on the nearest bookcase,
// and «ابدأ المشي» moves the arrow with their real steps (motion sensor + compass).
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, h, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuestShell from '@/Components/GuestShell.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import BookCover from '@/Components/Library/BookCover.vue';
import LibraryMap3D from '@/Components/Library/Map/LibraryMap3D.vue';
import LibraryMap2D from '@/Components/Library/Map/LibraryMap2D.vue';
import Maneuver from '@/Components/Library/Map/Maneuver.vue';
import { findRoute, isLift, directions, pointAt, standingPoints, hallAt, isHall, storeyOf, routeOnStorey, outline } from '@/Components/Library/Map/route.js';
import { createStepTracker, requestSensors, sensorsSupported, stepLength as stepLengthStore, compassOffset, planBearing, angleDiff, circularMean, toPlanHeading } from '@/Components/Library/Map/stepTracker.js';
import QrScanner from '@/Components/Library/Map/QrScanner.vue';
import { isSticker, nearestStickers } from '@/Components/Library/Map/stickers.js';
import { sectionColor } from '@/Components/Library/Map/colors.js';
import { useTrans } from '@/Composables/useTrans';
import {
    MagnifyingGlassIcon, XMarkIcon, PlayIcon, PauseIcon, ArrowPathIcon, MapIcon, PencilSquareIcon,
    ViewfinderCircleIcon, ChevronLeftIcon, ChevronRightIcon, BookOpenIcon, CheckCircleIcon, QrCodeIcon, MapPinIcon, ExclamationTriangleIcon, MinusIcon, PlusIcon,
    Squares2X2Icon, CubeIcon, ArrowsPointingOutIcon, ArrowsPointingInIcon, ClipboardDocumentListIcon, FireIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ floor: Object, shelves: Array, stock: { type: Object, default: () => ({}) }, target: Object, canEdit: Boolean, startAt: { type: Number, default: null }, embedded: Boolean, status: { type: Object, default: null } });
const page = usePage();
const signedIn = computed(() => !!page.props.auth?.user);
// Inside the FileNest apps (2026-11-06, by request) the map fills the app's screen on its own.
const EmbedShell = { setup: (_, { slots }) => () => h('div', { class: 'min-h-dvh bg-gray-100 dark:bg-gray-900' }, slots.default?.()) };
const Layout = computed(() => (props.embedded ? EmbedShell : signedIn.value ? AppLayout : GuestShell));

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
const saved2d = store.get('library.map.flat');
const flat = ref(saved2d === null ? weakDevice() : saved2d === '1');
function setFlat(v) {
    flat.value = v;
    store.set('library.map.flat', v ? '1' : '0');
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
const liftOnly = ref(store.get('library.map.lift_only') === '1');
watch(liftOnly, (v) => store.set('library.map.lift_only', v ? '1' : '0'));
const shelfById = computed(() => Object.fromEntries(props.shelves.map((s) => [s.id, s])));
const bookcases = computed(() => props.shelves.filter((s) => s.kind === 'shelf'));
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

// Search -------------------------------------------------------------------------------------
const q = ref('');
const results = ref([]);
const searching = ref(false);
const showResults = ref(false);
let timer = null;
let lastQuery = '';
watch(q, (v) => {
    clearTimeout(timer);
    if (v.trim().length < 2) { results.value = []; return; }
    timer = setTimeout(async () => {
        lastQuery = v;
        searching.value = true;
        try {
            const { data } = await axios.get(route('library.map.search'), { params: { q: v } });
            if (lastQuery === v) { results.value = data.books; showResults.value = true; }
        } finally { searching.value = false; }
    }, 250);
});

// Selection: a book (with its spots) or a bookcase clicked on the map. -------------------------
const book = ref(props.target);
const spotIndex = ref(0);
const pickedShelf = ref(null);
const spot = computed(() => book.value?.spots?.[spotIndex.value] ?? null);
const targetShelf = computed(() => (spot.value ? shelfById.value[spot.value.shelf_id] : pickedShelf.value));

function chooseBook(b) {
    book.value = b;
    spotIndex.value = 0;
    pickedShelf.value = null;
    showResults.value = false;
    q.value = '';
    stop();
    // Keep the address shareable: /library/map?book=12
    try { window.history.replaceState(window.history.state, '', route('library.map', { book: b.id })); } catch {}
    if (b.spots?.length) setTimeout(() => map.value?.focusShelf(b.spots[0].shelf_id), 50);
}
// Opened from a book page (/library/map?book=12): fly straight to its bookcase.
function onReady(api) {
    if (spot.value) setTimeout(() => api.focusShelf(spot.value.shelf_id), 400);
    else if (here.value) setTimeout(() => api.focusShelf(here.value.shelfId), 400);
}
function clearSelection() {
    book.value = null;
    pickedShelf.value = null;
    stop();
    map.value?.overview();
    try { window.history.replaceState(window.history.state, '', route('library.map')); } catch {}
}
function onPick(id) {
    const s = shelfById.value[id];
    if (!s || walking.value) return;
    if (book.value && spot.value?.shelf_id === id) return;
    book.value = null;
    pickedShelf.value = s;
    stop();
}

// «كتب هذا الرف» (2026-11-07, by request): the covers of the books on the bookcase you tap; tapping
// a cover lights up its level and stands the cover on the shelf in 3D.
const shelfBooks = ref([]);
const shelfBooksLoading = ref(false);
const pickedBook = ref(null);
watch(pickedShelf, async (s) => {
    shelfBooks.value = [];
    pickedBook.value = null;
    if (!s || s.kind !== 'shelf' || !props.stock?.[s.id]) return;
    shelfBooksLoading.value = true;
    try {
        const { data } = await axios.get(route('library.map.shelf-books', s.id));
        if (pickedShelf.value?.id === s.id) shelfBooks.value = data.books ?? [];
    } catch {} finally { shelfBooksLoading.value = false; }
});
const stockOf = (id) => props.stock?.[id]?.n ?? 0;

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
const walkRoute = computed(() => (targetShelf.value?.kind === 'shelf' ? findRoute(props.floor, props.shelves, origin.value, targetShelf.value, routeOpts.value) : null));
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
        case 'arrive_right': return t('library.map.arrive_right', 'الرف :code على يمينك', { code });
        case 'arrive_left': return t('library.map.arrive_left', 'الرف :code على يسارك', { code });
        default: return t('library.map.arrive_ahead', 'الرف :code أمامك', { code });
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
const SPIN_KEY = 'library.map.spinMap';
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
    : t('library.map.you_are_at', 'أنت الآن عند الرف :code', { code: s.code }));

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
// «حالة الرفوف» (2026-11-10, by request, from the exam hall map's ideas): for staff, a pulsing
// mark on each bookcase that needs a walk (copies marked lost to look for, damaged copies to take
// for repair), the busiest bookcases glowing orange, and the way there drawn from the loan desk.
const STATUS_KEY = 'library.map.status';
const statusOn = ref(!!props.status && store.get(STATUS_KEY) === '1');
watch(statusOn, (v) => store.set(STATUS_KEY, v ? '1' : '0'));
const statusAlerts = computed(() => props.status?.alerts ?? []);
const busyTop = computed(() => Object.entries(props.status?.busy ?? {}).map(([id, n]) => ({ id: +id, n })).filter((b) => shelfById.value[b.id]).sort((a, b) => b.n - a.n));
const mapAlerts = computed(() => {
    if (!statusOn.value) return null;
    const max = busyTop.value[0]?.n || 1;
    return [
        ...busyTop.value.slice(0, 12).map((b) => ({ id: b.id, kind: 'busy', n: b.n, k: b.n / max })),
        ...statusAlerts.value.map((a) => ({ id: a.shelf_id, kind: a.kind, n: a.n })),
    ];
});
const statusCount = computed(() => statusAlerts.value.reduce((t, a) => t + a.n, 0));
/** The loan desk: a desk named for loans, or the first desk on the plan. */
const desk = computed(() => {
    const desks = props.shelves.filter((s) => s.kind === 'desk');
    return desks.find((s) => /إعار|استعار|loan|counter|circulation/i.test(`${s.name ?? ''} ${s.code ?? ''}`)) ?? desks[0] ?? null;
});
function goFromDesk(shelfId) {
    const s = shelfById.value[shelfId];
    if (!s) return;
    stop();
    if (desk.value) { placeAt(desk.value); here.value.desk = true; }
    book.value = null;
    pickedShelf.value = s;
    setTimeout(() => map.value?.focusShelf(s.id), 60);
}
watch(statusOn, (v) => { if (!v && here.value?.desk) resetHere(); });
const statusTitles = (shelfId) => statusAlerts.value.filter((a) => a.shelf_id === shelfId);

const beacons = computed(() => (qrNearby.value.length ? { ids: qrNearby.value.map((n) => n.s.id), nearest: qrNearby.value[0].s.id } : null));
function showStickers() {
    scanning.value = false;
    findQr.value = true;
    const n = qrNearby.value[0];
    if (n && !walking.value) setTimeout(() => map.value?.focusShelf(n.s.id), 60);
}
const stickerName = (s) => (s.kind === 'qr' ? [s.code, s.name].filter(Boolean).join(' · ') : t('library.map.shelf_sticker', 'ملصق الرف :code', { code: s.code }));

function onScan(text) {
    scanning.value = false;
    findQr.value = false;
    let id = null;
    try { id = Number(new URL(text, window.location.origin).searchParams.get('at')) || null; } catch {}
    const shelf = id ? shelfById.value[id] : null;
    if (!shelf) { scanNote.value = t('library.map.scan_unknown', 'هذا الرمز ليس من ملصقات خريطة المكتبة.'); return; }
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

function availability(b) {
    if (b.reference_only) return { text: t('library.reference_only', 'للقراءة داخل المكتبة'), cls: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' };
    if (!b.copies_count) return { text: t('library.not_in_library', 'غير موجود حالياً'), cls: 'bg-gray-100 text-gray-500 dark:bg-gray-700' };
    return b.available_count > 0
        ? { text: t('library.available_n', 'متاح :n من :m', { n: b.available_count, m: b.copies_count }), cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' }
        : { text: t('library.all_loaned', 'كل النسخ مُعارة'), cls: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' };
}
const fromText = computed(() => (here.value?.desk ? t('library.map.from_desk', ':d م من مكتب الإعارة', { d: metres(walkRoute.value?.length ?? 0) }) : here.value ? t('library.map.from_here', ':d م من موقعك', { d: metres(walkRoute.value?.length ?? 0) }) : t('library.map.distance', ':d م مشياً من المدخل', { d: metres(walkRoute.value?.length ?? 0) })));
const spotLabel = (s) => [t('library.shelf', 'الرف') + ' ' + s.code, hallName(s.shelf_id), levelText(s.level)].filter(Boolean).join(' · ');
const highlight = computed(() => {
    if (!targetShelf.value) return null;
    const shown = book.value ?? pickedBook.value;
    return { id: targetShelf.value.id, level: spot.value?.level ?? pickedBook.value?.level ?? null, cover: shown?.cover_url ?? null };
});

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
    <Head :title="t('library.map.title', 'خريطة المكتبة')" />

    <component :is="Layout">
        <template #header>
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('library.map.title', 'خريطة المكتبة') }}</h2>
                <Link v-if="canEdit" :href="route('library.manage.map')" class="ms-auto inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                    <PencilSquareIcon class="h-4 w-4" /> {{ t('library.map.edit', 'ترتيب الرفوف') }}
                </Link>
            </div>
        </template>

        <div :class="embedded ? '' : 'space-y-4 p-4 sm:p-6'">
            <LibraryTabs v-if="signedIn && !embedded" />

            <div v-if="!bookcases.length" class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-600 dark:bg-gray-800">
                <MapIcon class="mx-auto h-12 w-12 text-gray-300" />
                <p class="mt-3 font-semibold">{{ t('library.map.empty', 'لم تُرسم خريطة المكتبة بعد') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ t('library.map.empty_text', 'بعد ترتيب الرفوف تظهر هنا المكتبة بشكل ثلاثي الأبعاد مع طريق الوصول لكل كتاب.') }}</p>
                <Link v-if="canEdit" :href="route('library.manage.map')" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm font-medium text-white">
                    <PencilSquareIcon class="h-5 w-5" /> {{ t('library.map.start_editing', 'ابدأ ترتيب الرفوف') }}
                </Link>
            </div>

            <Teleport v-else to="body" :disabled="!full">
            <div class="overflow-hidden bg-gray-100 dark:bg-gray-900" :class="full ? 'fixed inset-0 z-40 h-dvh' : embedded ? 'relative h-dvh' : 'relative h-[calc(100dvh-10rem)] min-h-[460px] sm:h-[calc(100dvh-13rem)] rounded-2xl border border-gray-200 shadow-sm dark:border-gray-700'">
                <LibraryMap2D v-if="flat" ref="map" :floor="floor" :shelves="viewShelves" :highlight="viewHighlight" :route="viewRoute" :avatar="viewAvatar" :beacons="beacons" :alerts="mapAlerts" :follow="walking && !!viewAvatar" @pick="onPick" @ready="onReady" />
                <LibraryMap3D v-else ref="map" :floor="floor" :shelves="viewShelves" :stock="stock" :highlight="viewHighlight" :route="viewRoute" :avatar="viewAvatar" :beacons="beacons" :alerts="mapAlerts" :follow="walking && !!viewAvatar" @pick="onPick" @ready="onReady" @failed="setFlat(true)" />

                <!-- Search, or the Waze-style instruction banner while walking. -->
                <div class="pointer-events-none absolute inset-x-0 top-0 p-3">
                    <div v-if="walking || arrived" class="pointer-events-auto mx-auto flex max-w-xl items-center gap-3 rounded-2xl px-4 py-3 text-white shadow-lg" :class="arrived ? 'bg-emerald-600' : 'bg-[#1f2a44]'">
                        <Maneuver :type="arrived ? 'arrive' : currentStep?.type" class="h-12 w-12 shrink-0" />
                        <div class="min-w-0 flex-1">
                            <template v-if="arrived">
                                <p class="text-lg font-bold leading-tight">{{ t('library.map.arrived', 'وصلت') }}</p>
                                <p class="text-sm opacity-90">{{ stepText(steps[steps.length - 1]) }}<template v-if="spot?.level"> · {{ levelText(spot.level) }}</template></p>
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
                            <input v-model="q" @focus="showResults = true" type="search" :placeholder="t('library.map.search', 'ابحث عن كتاب لتعرف مكانه…')"
                                class="min-w-0 flex-1 border-0 bg-transparent p-1 text-sm focus:ring-0 dark:text-gray-100" />
                            <ArrowPathIcon v-if="searching" class="h-4 w-4 animate-spin text-gray-400" />
                            <button @click="scanning = true" class="-me-1 inline-flex items-center gap-1 rounded-xl bg-gray-100 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200" :title="t('library.map.scan', 'امسح ملصق الرف لتحديد موقعك')">
                                <QrCodeIcon class="h-4 w-4" /> <span class="hidden sm:inline">{{ t('library.map.where_am_i', 'أين أنا؟') }}</span>
                            </button>
                        </div>
                        <div v-if="here && !(showResults && q.trim().length >= 2)" class="mt-2 flex w-fit items-center gap-2 rounded-full bg-blue-600 px-3 py-1.5 text-xs font-medium text-white shadow">
                            <MapPinIcon class="h-4 w-4" /> {{ atText(shelfById[here.shelfId] ?? { code: here.code }) }}
                            <button @click="resetHere" class="-me-1 rounded-full p-0.5 hover:bg-white/20" :aria-label="t('library.map.from_entrance', 'من المدخل')"><XMarkIcon class="h-4 w-4" /></button>
                        </div>
                        <div v-if="showResults && q.trim().length >= 2" class="absolute inset-x-0 top-full mt-2 max-h-[50vh] overflow-y-auto rounded-2xl bg-white shadow-xl ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                            <p v-if="!searching && !results.length" class="p-4 text-center text-sm text-gray-500">{{ t('library.no_results', 'لا توجد نتائج') }}</p>
                            <button v-for="b in results" :key="b.id" @click="chooseBook(b)" class="flex w-full items-center gap-3 border-b border-gray-100 px-3 py-2.5 text-start last:border-0 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/60">
                                <BookCover :book="b" size="sm" class="w-9 shrink-0" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold" dir="auto">{{ b.title }}</span>
                                    <span class="block truncate text-xs text-gray-500" dir="auto">{{ b.authors }}</span>
                                    <span class="mt-1 flex flex-wrap gap-1 text-[11px]">
                                        <span class="rounded-full px-2 py-0.5" :class="availability(b).cls">{{ availability(b).text }}</span>
                                        <span v-if="b.spots.length" class="rounded-full bg-blue-50 px-2 py-0.5 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">{{ spotLabel(b.spots[0]) }}</span>
                                        <span v-else class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-500 dark:bg-gray-700">{{ t('library.map.no_spot', 'مكانه غير محدد على الخريطة') }}</span>
                                    </span>
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
                    <button @click="map?.overview()" class="rounded-xl bg-white/95 p-2.5 shadow ring-1 ring-black/5 hover:bg-white dark:bg-gray-800/95 dark:ring-white/10" :title="t('library.map.overview', 'عرض المكتبة كاملة')"><MapIcon class="h-5 w-5" /></button>
                    <button v-if="status" @click="statusOn = !statusOn" class="relative rounded-xl p-2.5 shadow ring-1" :class="statusOn ? 'bg-red-600 text-white ring-red-700' : 'bg-white/95 ring-black/5 hover:bg-white dark:bg-gray-800/95 dark:ring-white/10'" :title="t('library.map.status', 'حالة الرفوف (للموظفين)')" :aria-pressed="statusOn">
                        <ClipboardDocumentListIcon class="h-5 w-5" />
                        <span v-if="statusCount && !statusOn" class="absolute -end-1.5 -top-1.5 min-w-5 rounded-full bg-red-600 px-1 text-[10px] font-bold leading-5 text-white">{{ statusCount > 99 ? '99+' : statusCount }}</span>
                    </button>
                    <button v-if="targetShelf" @click="map?.focusShelf(targetShelf.id)" class="rounded-xl bg-white/95 p-2.5 shadow ring-1 ring-black/5 hover:bg-white dark:bg-gray-800/95 dark:ring-white/10" :title="t('library.map.show_shelf', 'اعرض الرف')"><ViewfinderCircleIcon class="h-5 w-5" /></button>
                </div>

                <!-- Bottom sheet: the chosen book or bookcase, and the route summary. -->
                <div v-if="book || pickedShelf" class="absolute inset-x-0 bottom-0 p-3">
                    <div class="mx-auto max-w-xl rounded-2xl bg-white p-4 shadow-2xl ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div v-if="book && !walking" class="flex gap-3">
                            <BookCover :book="book" size="sm" class="hidden w-12 shrink-0 sm:block" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start gap-2">
                                    <component :is="embedded ? 'span' : Link" :href="embedded ? undefined : route('library.books.show', book.id)" class="min-w-0 flex-1 truncate font-bold hover:underline" dir="auto">{{ book.title }}</component>
                                    <button @click="clearSelection" class="-m-1 rounded-full p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><XMarkIcon class="h-5 w-5" /></button>
                                </div>
                                <p class="truncate text-xs text-gray-500" dir="auto">{{ book.authors }}</p>
                                <div class="mt-1.5 flex flex-wrap gap-1.5 text-xs">
                                    <span class="rounded-full px-2 py-0.5" :class="availability(book).cls">{{ availability(book).text }}</span>
                                    <button v-for="(s, i) in book.spots" :key="i" @click="spotIndex = i; stop(); map?.focusShelf(s.shelf_id)"
                                        class="rounded-full px-2 py-0.5 ring-1" :class="i === spotIndex ? 'bg-blue-600 text-white ring-blue-600' : 'bg-white text-blue-700 ring-blue-200 dark:bg-gray-700 dark:text-blue-300 dark:ring-gray-600'">
                                        {{ spotLabel(s) }}<template v-if="s.total"> ({{ s.available }}/{{ s.total }})</template>
                                    </button>
                                </div>
                                <p v-if="!book.spots.length" class="mt-2 text-sm text-amber-700 dark:text-amber-400">{{ t('library.map.no_spot_text', 'لم يُحدَّد رف هذا الكتاب بعد. اسأل موظف المكتبة.') }}</p>
                                <p v-else-if="spot?.by_section" class="mt-1.5 text-xs text-gray-500">{{ t('library.map.by_section', 'المكان حسب قسم الكتاب') }}</p>
                            </div>
                        </div>
                        <div v-else-if="!walking" class="flex items-start gap-3">
                            <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm font-bold text-white" :style="{ background: pickedShelf.color || sectionColor(pickedShelf.section_id) }">{{ pickedShelf.code.slice(0, 4) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-bold">{{ pickedShelf.kind === 'shelf' ? t('library.shelf', 'الرف') + ' ' + pickedShelf.code : pickedShelf.name || pickedShelf.code }}</p>
                                <p class="truncate text-sm text-gray-500">{{ [pickedShelf.section || pickedShelf.name, hallName(pickedShelf.id)].filter(Boolean).join(' · ') }}</p>
                                <Link v-if="pickedShelf.section_id && !embedded" :href="route('library.index', { section: pickedShelf.section_id })" class="mt-1 inline-flex items-center gap-1 text-sm text-[var(--accent-600)] hover:underline"><BookOpenIcon class="h-4 w-4" /> {{ t('library.map.section_books', 'كتب هذا القسم') }}</Link>
                                <div v-if="statusOn && statusTitles(pickedShelf.id).length" class="mt-2 space-y-1">
                                    <p v-for="a in statusTitles(pickedShelf.id)" :key="a.kind" class="rounded-lg px-2 py-1 text-xs" :class="a.kind === 'lost' ? 'bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-200' : 'bg-violet-50 text-violet-800 dark:bg-violet-900/30 dark:text-violet-200'">
                                        <b>{{ a.kind === 'lost' ? t('library.map.status_lost', 'نسخ مفقودة: ابحث عنها هنا') : t('library.map.status_damaged', 'نسخ تالفة: خذها للصيانة') }} ({{ a.n }})</b><br />
                                        <span v-for="b in a.titles" :key="b.barcode" class="block truncate">{{ b.title }} · <span dir="ltr">{{ b.barcode }}</span><template v-if="b.level"> · {{ t('library.map.level_short', 'ط :n', { n: b.level }) }}</template></span>
                                    </p>
                                </div>
                                <template v-if="pickedShelf.kind === 'shelf'">
                                    <p class="mt-2 text-xs font-semibold text-gray-500">{{ stockOf(pickedShelf.id) ? t('library.map.shelf_stock', 'على هذا الرف :n نسخة', { n: stockOf(pickedShelf.id) }) : t('library.map.shelf_empty', 'لا توجد كتب مسجّلة على هذا الرف') }}</p>
                                    <div v-if="shelfBooks.length || shelfBooksLoading" class="-mx-1 mt-1.5 flex gap-2 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]">
                                        <template v-if="shelfBooksLoading && !shelfBooks.length"><span v-for="i in 4" :key="'l' + i" class="h-[4.5rem] w-12 shrink-0 animate-pulse rounded-md bg-gray-200 dark:bg-gray-700" /></template>
                                        <button v-for="b in shelfBooks" :key="b.id + '-' + b.level" @click="pickedBook = pickedBook === b ? null : b" class="relative w-12 shrink-0 rounded-md text-start outline-none ring-offset-2 dark:ring-offset-gray-800" :class="pickedBook === b ? 'ring-2 ring-[var(--accent-600)]' : ''" :title="b.title">
                                            <BookCover :book="b" size="sm" class="w-12" />
                                            <span v-if="b.copies > 1" class="absolute -end-1 -top-1 rounded-full bg-gray-900 px-1 text-[10px] font-bold leading-4 text-white">×{{ b.copies }}</span>
                                            <span v-if="b.level" class="absolute inset-x-0 bottom-0 rounded-b-md bg-black/60 text-center text-[10px] leading-4 text-white">{{ t('library.map.level_short', 'ط :n', { n: b.level }) }}</span>
                                        </button>
                                    </div>
                                    <p v-if="pickedBook" class="mt-1 truncate text-sm font-medium" dir="auto">{{ pickedBook.title }}<span v-if="pickedBook.level" class="text-gray-500"> · {{ levelText(pickedBook.level) }}</span></p>
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
                                <button @click="scanning = true" class="rounded-full border border-gray-300 p-2 dark:border-gray-600" :title="t('library.map.scan', 'امسح ملصق الرف لتحديد موقعك')"><QrCodeIcon class="h-5 w-5" /></button>
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
                        <p v-if="targetShelf?.kind === 'shelf' && !walkRoute && !walking" class="mt-2 text-sm text-amber-700 dark:text-amber-400">{{ routeOpts.liftOnly ? t('library.map.no_lift_route', 'لا يوجد طريق بالمصعد إلى هذا الرف. اسأل موظف المكتبة.') : t('library.map.no_route', 'تعذّر إيجاد طريق إلى هذا الرف.') }}</p>
                        <p v-if="sensorError" class="mt-2 text-xs text-red-600">{{ sensorError }}</p>
                        <p v-if="walkRoute && !walking && !arrived && canLive" class="mt-2 text-[11px] leading-5 text-gray-500">{{ t('library.map.live_hint', 'امسك الهاتف أمامك وامشِ: السهم يتقدم مع خطواتك. للدقة امسح ملصق QR على أي رف تمر به.') }}</p>

                        <!-- All the steps, Waze's list view. -->
                        <button v-if="walkRoute && !walking" @click="showSteps = !showSteps" class="mt-2 text-xs font-medium text-blue-600 sm:hidden dark:text-blue-400">{{ showSteps ? t('library.map.hide_steps', 'إخفاء الخطوات') : t('library.map.show_steps', 'عرض الخطوات (:n)', { n: steps.length }) }}</button>
                        <ol v-if="walkRoute && !walking" class="mt-2 max-h-36 space-y-1.5 overflow-y-auto text-sm sm:mt-3 sm:block" :class="showSteps ? 'block' : 'hidden'">
                            <li v-for="(s, i) in steps" :key="i" class="flex items-center gap-2.5">
                                <Maneuver :type="s.type" class="h-5 w-5 shrink-0" :class="s.type.startsWith('arrive') ? 'text-red-500' : 'text-blue-600 dark:text-blue-400'" />
                                <span class="flex-1">{{ stepText(s) }}<template v-if="s.type.startsWith('arrive') && spot?.level"> · {{ levelText(spot.level) }}</template></span>
                                <CheckCircleIcon v-if="arrived" class="h-4 w-4 text-emerald-500" />
                            </li>
                        </ol>
                    </div>
                </div>

                <!-- «حالة الرفوف»: what needs a walk from the loan desk. -->
                <div v-else-if="statusOn" class="absolute inset-x-0 bottom-0 p-3">
                    <div class="mx-auto max-h-[45vh] max-w-xl overflow-y-auto rounded-2xl bg-white p-4 shadow-2xl ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="flex items-center gap-2">
                            <ClipboardDocumentListIcon class="h-5 w-5 text-red-600" />
                            <h3 class="flex-1 font-semibold">{{ t('library.map.status', 'حالة الرفوف (للموظفين)') }}</h3>
                            <button @click="statusOn = false" class="rounded-full p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><XMarkIcon class="h-5 w-5" /></button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">{{ desk ? t('library.map.status_hint', 'اضغط على أي رف فيرسم الطريق إليه من :desk.', { desk: desk.name || desk.code }) : t('library.map.status_no_desk', 'ارسم «مكتب الإعارة» على الخريطة ليُرسم الطريق منه.') }}</p>
                        <ul v-if="statusAlerts.length" class="mt-3 space-y-1.5">
                            <li v-for="a in statusAlerts" :key="a.kind + a.shelf_id">
                                <button @click="goFromDesk(a.shelf_id)" class="flex w-full items-start gap-3 rounded-xl border px-3 py-2 text-start text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50" :class="a.kind === 'lost' ? 'border-red-200 dark:border-red-900' : 'border-violet-200 dark:border-violet-900'">
                                    <span class="mt-0.5 flex h-7 min-w-7 items-center justify-center rounded-full px-1 text-xs font-bold text-white" :class="a.kind === 'lost' ? 'bg-red-600' : 'bg-violet-600'">{{ a.n }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-semibold">{{ t('library.shelf', 'الرف') }} {{ a.code }} · {{ a.kind === 'lost' ? t('library.map.status_lost', 'نسخ مفقودة: ابحث عنها هنا') : t('library.map.status_damaged', 'نسخ تالفة: خذها للصيانة') }}</span>
                                        <span class="block truncate text-xs text-gray-500">{{ a.titles.map((b) => b.title + (b.level ? ` (${t('library.map.level_short', 'ط :n', { n: b.level })})` : '')).join(' · ') }}</span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                        <p v-else class="mt-3 flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200"><CheckCircleIcon class="h-5 w-5" /> {{ t('library.map.status_clear', 'لا توجد نسخ مفقودة أو تالفة.') }}</p>
                        <div v-if="busyTop.length" class="mt-3">
                            <p class="flex items-center gap-1.5 text-xs font-semibold text-orange-700 dark:text-orange-300"><FireIcon class="h-4 w-4" /> {{ t('library.map.status_busy', 'الأكثر استعارة في آخر 30 يوماً (متوهجة بالبرتقالي)') }}</p>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <button v-for="b in busyTop.slice(0, 8)" :key="b.id" @click="goFromDesk(b.id)" class="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-medium text-orange-800 ring-1 ring-orange-200 hover:bg-orange-100 dark:bg-orange-900/30 dark:text-orange-200 dark:ring-orange-800">{{ shelfById[b.id]?.code }} · {{ t('library.map.loans_n', ':n إعارة', { n: b.n }) }}</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Legend of sections when nothing is chosen. -->
                <div v-else class="pointer-events-none absolute inset-x-0 bottom-0 p-3">
                    <p class="mx-auto w-fit rounded-full bg-white/90 px-3 py-1.5 text-xs text-gray-600 shadow dark:bg-gray-800/90 dark:text-gray-300">{{ flat ? t('library.map.hint_2d', 'ابحث عن كتاب أو اضغط على أي رف · اسحب للتحريك وبإصبعين للتكبير') : t('library.map.hint', 'ابحث عن كتاب أو اضغط على أي رف · اسحب لتدوير المكتبة') }}</p>
                </div>
            </div>
            </Teleport>
        </div>
    </component>
</template>
