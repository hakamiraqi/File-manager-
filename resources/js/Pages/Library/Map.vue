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
import Maneuver from '@/Components/Library/Map/Maneuver.vue';
import { findRoute, directions, pointAt, standingPoints, hallAt, isHall, storeyOf, routeOnStorey, outline } from '@/Components/Library/Map/route.js';
import { createStepTracker, requestSensors, sensorsSupported, stepLength as stepLengthStore, compassOffset, planBearing, angleDiff, circularMean } from '@/Components/Library/Map/stepTracker.js';
import QrScanner from '@/Components/Library/Map/QrScanner.vue';
import { sectionColor } from '@/Components/Library/Map/colors.js';
import { useTrans } from '@/Composables/useTrans';
import {
    MagnifyingGlassIcon, XMarkIcon, PlayIcon, PauseIcon, ArrowPathIcon, MapIcon, PencilSquareIcon,
    ViewfinderCircleIcon, ChevronLeftIcon, ChevronRightIcon, BookOpenIcon, CheckCircleIcon, QrCodeIcon, MapPinIcon, ExclamationTriangleIcon, MinusIcon, PlusIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ floor: Object, shelves: Array, target: Object, canEdit: Boolean, startAt: { type: Number, default: null }, embedded: Boolean });
const page = usePage();
const signedIn = computed(() => !!page.props.auth?.user);
// Inside the FileNest apps (2026-11-06, by request) the map fills the app's screen on its own.
const EmbedShell = { setup: (_, { slots }) => () => h('div', { class: 'min-h-dvh bg-gray-100 dark:bg-gray-900' }, slots.default?.()) };
const Layout = computed(() => (props.embedded ? EmbedShell : signedIn.value ? AppLayout : GuestShell));

const map = ref(null);
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
const walkRoute = computed(() => (targetShelf.value?.kind === 'shelf' ? findRoute(props.floor, props.shelves, origin.value, targetShelf.value) : null));
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
        case 'stairs_up': return t('library.map.stairs_up', 'اصعد عبر :code إلى :storey', { code: step.stairs.code, storey: storeyName(step.storey) });
        case 'stairs_down': return t('library.map.stairs_down', 'انزل عبر :code إلى :storey', { code: step.stairs.code, storey: storeyName(step.storey) });
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
    if (walkRoute.value && (walking.value || arrived.value)) return pointAt(walkRoute.value, walked.value);
    // Standing still at a scanned sticker: show «أنت هنا», facing the way to go.
    if (here.value) return { x: here.value.x, y: here.value.y, heading: walkRoute.value ? pointAt(walkRoute.value, 0.01).heading : -Math.PI / 2 };
    return null;
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
onBeforeUnmount(() => { cancelAnimationFrame(raf); stopLive(); });

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
    tracker = createStepTracker({ onStep });
}
function stopLive() {
    tracker?.stop();
    tracker = null;
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
            if (Math.abs(angleDiff(facing, segBearing)) > 110) {
                wrongSteps++;
                if (wrongSteps >= 3) wrongWay.value = true;
                return;
            }
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
function onScan(text) {
    scanning.value = false;
    let id = null;
    try { id = Number(new URL(text, window.location.origin).searchParams.get('at')) || null; } catch {}
    const shelf = id ? shelfById.value[id] : null;
    if (!shelf) { scanNote.value = t('library.map.scan_unknown', 'هذا الرمز ليس من ملصقات خريطة المكتبة.'); return; }
    navigator.vibrate?.(60);
    const wasLive = live.value && walking.value;
    if (wasLive && stepCount.value >= 8 && liveStartedFrom) {
        // We now know how far they really walked: tune the step length to it.
        const real = findRoute(props.floor, props.shelves, liveStartedFrom, shelf)?.length;
        const len = real ? real / stepCount.value : null;
        if (len && len >= 0.4 && len <= 0.95) { stepLen.value = Math.round(len * 100) / 100; stepLengthStore.set(stepLen.value); }
    }
    placeAt(shelf);
    scanNote.value = t('library.map.you_are_at', 'أنت الآن عند الرف :code', { code: shelf.code });
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
const fromText = computed(() => (here.value ? t('library.map.from_here', ':d م من موقعك', { d: metres(walkRoute.value?.length ?? 0) }) : t('library.map.distance', ':d م مشياً من المدخل', { d: metres(walkRoute.value?.length ?? 0) })));
const spotLabel = (s) => [t('library.shelf', 'الرف') + ' ' + s.code, hallName(s.shelf_id), levelText(s.level)].filter(Boolean).join(' · ');
const highlight = computed(() => (targetShelf.value ? { id: targetShelf.value.id, level: spot.value?.level ?? null } : null));

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

            <div v-else class="relative overflow-hidden bg-gray-100 dark:bg-gray-900" :class="embedded ? 'h-dvh' : 'h-[calc(100dvh-10rem)] min-h-[460px] sm:h-[calc(100dvh-13rem)] rounded-2xl border border-gray-200 shadow-sm dark:border-gray-700'">
                <LibraryMap3D ref="map" :floor="floor" :shelves="viewShelves" :highlight="viewHighlight" :route="viewRoute" :avatar="viewAvatar" :follow="walking && !!viewAvatar" @pick="onPick" @ready="onReady" />

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
                            <MapPinIcon class="h-4 w-4" /> {{ t('library.map.you_are_at', 'أنت الآن عند الرف :code', { code: here.code }) }}
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
                <QrScanner v-if="scanning" @result="onScan" @close="scanning = false" />

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
                    <button @click="map?.overview()" class="rounded-xl bg-white/95 p-2.5 shadow ring-1 ring-black/5 hover:bg-white dark:bg-gray-800/95 dark:ring-white/10" :title="t('library.map.overview', 'عرض المكتبة كاملة')"><MapIcon class="h-5 w-5" /></button>
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

                <!-- Legend of sections when nothing is chosen. -->
                <div v-else class="pointer-events-none absolute inset-x-0 bottom-0 p-3">
                    <p class="mx-auto w-fit rounded-full bg-white/90 px-3 py-1.5 text-xs text-gray-600 shadow dark:bg-gray-800/90 dark:text-gray-300">{{ t('library.map.hint', 'ابحث عن كتاب أو اضغط على أي رف · اسحب لتدوير المكتبة') }}</p>
                </div>
            </div>
        </div>
    </component>
</template>
