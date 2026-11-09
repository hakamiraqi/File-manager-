<script setup>
// «مسار المرشّح» in an OSCE (2026-11-09, by request): the stations in the order the candidate goes
// round them, joined by a path; at each one the time spent (against the time allowed) and the
// mark. Done stations are green, the one they are at now beats, the next ones are grey, and the
// path runs on towards where they are.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { CheckIcon } from '@heroicons/vue/24/solid';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
const props = defineProps({
    journey: { type: Array, default: () => [] },
    compact: { type: Boolean, default: false }, // dots only, for a table row
    here: { type: Number, default: null }, // the station this page is (highlighted)
});
const now = ref(Date.now());
let timer = null;
onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(timer));

const steps = computed(() => props.journey.map((s) => {
    const seconds = s.state === 'now' && s.started_at ? Math.max(0, Math.round((now.value - new Date(s.started_at).getTime()) / 1000)) : s.seconds;
    return { ...s, seconds, over: seconds !== null && seconds !== undefined && seconds > s.minutes * 60 };
}));
const mmss = (sec) => (sec === null || sec === undefined ? '—' : `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`);
const num = (v) => (v === null || v === undefined ? '' : Number(v) % 1 ? Number(v).toFixed(1) : String(Number(v)));
const total = computed(() => steps.value.reduce((n, s) => n + (s.total !== null && s.total !== undefined ? Number(s.total) : 0), 0));
const max = computed(() => steps.value.reduce((n, s) => n + Number(s.max || 0), 0));
const time = computed(() => steps.value.reduce((n, s) => n + (s.seconds ?? 0), 0));
const done = computed(() => steps.value.filter((s) => s.state === 'done').length);
const dot = (s) => (s.state === 'done' ? 'bg-emerald-500 text-white ring-emerald-200 dark:ring-emerald-900' : s.state === 'now' ? 'bg-blue-600 text-white ring-blue-200 dark:ring-blue-900' : 'bg-gray-200 text-gray-500 ring-gray-100 dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-800');
// The path into a station: walked (green), being walked (moving), still ahead (grey).
const way = (i) => (steps.value[i].state === 'done' ? 'bg-emerald-500' : steps.value[i].state === 'now' ? 'osce-moving' : 'bg-gray-200 dark:bg-gray-700');
</script>

<template>
    <div v-if="compact" class="flex items-center" dir="ltr" :title="t('exam.osce_route_title', ':d من :n محطات · :s / :m', { d: done, n: steps.length, s: num(total), m: num(max) })">
        <template v-for="(s, i) in steps" :key="s.station_id">
            <span v-if="i" class="h-0.5 w-3" :class="way(i)" />
            <span class="relative flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold ring-2" :class="dot(s)">
                <span v-if="s.state === 'now'" class="absolute inset-0 animate-ping rounded-full bg-blue-400/60" />
                <CheckIcon v-if="s.state === 'done'" class="h-2.5 w-2.5" /><span v-else class="relative">{{ i + 1 }}</span>
            </span>
        </template>
    </div>
    <div v-else class="space-y-2">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
            <span>{{ t('exam.osce_route', 'مسار المرشّح') }}</span>
            <span>{{ t('exam.osce_route_done', ':d من :n محطات', { d: done, n: steps.length }) }}</span>
            <span>{{ t('exam.osce_route_marks', 'الدرجة :s / :m', { s: num(total), m: num(max) }) }}</span>
            <span>{{ t('exam.osce_route_time', 'الوقت :t', { t: mmss(time) }) }}</span>
        </div>
        <ol class="flex items-start overflow-x-auto pb-1">
            <li v-for="(s, i) in steps" :key="s.station_id" class="flex min-w-[6.5rem] flex-1 items-start">
                <div class="flex w-full flex-col items-center">
                    <div class="flex w-full items-center">
                        <span class="h-1 flex-1 rounded-full" :class="i ? way(i) : 'bg-transparent'" />
                        <span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-bold ring-4" :class="[dot(s), here === s.station_id ? 'outline outline-2 outline-offset-2 outline-[var(--accent-500)]' : '']">
                            <span v-if="s.state === 'now'" class="absolute inset-0 animate-ping rounded-full bg-blue-400/50" />
                            <CheckIcon v-if="s.state === 'done'" class="relative h-5 w-5" /><span v-else class="relative">{{ i + 1 }}</span>
                        </span>
                        <span class="h-1 flex-1 rounded-full" :class="i < steps.length - 1 ? way(i + 1) : 'bg-transparent'" />
                    </div>
                    <p class="mt-1 line-clamp-2 px-1 text-center text-xs font-medium leading-4" dir="auto" :title="s.name">{{ s.name }}</p>
                    <p class="mt-0.5 text-center text-xs tabular-nums" :class="s.over ? 'font-semibold text-red-600' : 'text-gray-500'">
                        {{ mmss(s.seconds) }}<span class="text-gray-400"> / {{ s.minutes }}:00</span>
                    </p>
                    <p class="text-center text-xs font-semibold tabular-nums" :class="s.state === 'done' ? 'text-emerald-700 dark:text-emerald-400' : 'text-gray-400'">
                        {{ s.state === 'done' ? num(s.total) : '—' }} / {{ num(s.max) }}
                    </p>
                </div>
            </li>
        </ol>
    </div>
</template>

<style scoped>
.osce-moving {
    background-image: repeating-linear-gradient(90deg, #2563eb 0 6px, #bfdbfe 6px 12px);
    background-size: 24px 100%;
    animation: osce-move 0.8s linear infinite;
}
[dir='rtl'] .osce-moving { animation-direction: reverse; }
@keyframes osce-move {
    from { background-position: 0 0; }
    to { background-position: 24px 0; }
}
</style>
