<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import OsceJourney from '@/Components/Exams/OsceJourney.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, CheckIcon, MinusIcon, XMarkIcon, UserIcon, ClockIcon, PlayIcon, StopIcon } from '@heroicons/vue/24/outline';

// The assessor's device at one station: pick the candidate in front of you, tick the checklist
// (done, partly, not done), a global rating, and it is saved.
const props = defineProps({ station: Object, candidates: Array });
const { t } = useTrans();
const list = ref(props.candidates.map((c) => ({ ...c })));
const search = ref('');
const shown = computed(() => list.value.filter((c) => !search.value || (c.name + ' ' + (c.number ?? '')).toLowerCase().includes(search.value.toLowerCase())));
const current = ref(null);
const scores = ref([]);
const globalRating = ref(null);
const note = ref('');
const saving = ref(false);
const saved = ref(false);
function pick(c) {
    current.value = c;
    scores.value = props.station.checklist.map((item, i) => c.mark?.scores?.[i] ?? null);
    globalRating.value = c.mark?.global ?? null;
    note.value = c.mark?.note ?? '';
    saved.value = false;
}
const total = computed(() => scores.value.reduce((s, v) => s + Number(v || 0), 0));
async function save() {
    saving.value = true;
    try {
        const r = (await window.axios.post(route('exams.monitor.osce.mark', [props.station.id, current.value.id]), { scores: scores.value, global_rating: globalRating.value, note: note.value })).data;
        current.value.mark = { scores: [...scores.value], global: globalRating.value, note: note.value, total: r.total };
        // This station done on the candidate's route, with the time spent here.
        const step = current.value.journey?.find((x) => x.station_id === props.station.id);
        if (step) {
            if (step.state === 'now' && step.started_at) step.seconds = Math.round((Date.now() - new Date(step.started_at).getTime()) / 1000);
            Object.assign(step, { state: 'done', total: r.total });
        }
        saved.value = true;
    } finally {
        saving.value = false;
    }
}
// The station's countdown, started by the assessor when the candidate comes in.
const left = ref(null);
let timer = null;
function startClock() {
    clearInterval(timer);
    // The candidate in front of the assessor came in now: their time at this station starts.
    if (current.value) {
        const step = current.value.journey?.find((x) => x.station_id === props.station.id);
        if (step && step.state !== 'done') Object.assign(step, { state: 'now', started_at: new Date().toISOString(), seconds: 0 });
        window.axios.post(route('exams.monitor.osce.start', [props.station.id, current.value.id])).catch(() => {});
    }
    left.value = props.station.minutes * 60;
    timer = setInterval(() => { left.value--; if (left.value <= 0) { clearInterval(timer); try { navigator.vibrate?.(500); } catch {} } }, 1000);
}
const stopClock = () => { clearInterval(timer); left.value = null; };
onBeforeUnmount(() => clearInterval(timer));
const clock = computed(() => (left.value === null ? null : Math.floor(Math.max(0, left.value) / 60) + ':' + String(Math.max(0, left.value) % 60).padStart(2, '0')));
</script>

<template>
    <Head :title="station.name" />
    <AppLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-2">
                <Link :href="route('exams.monitor.osce')" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="min-w-0 flex-1 truncate text-lg font-semibold">{{ station.name }} <span class="text-sm font-normal text-gray-500">· {{ station.exam }}</span></h2>
                <span v-if="clock" class="flex items-center gap-1 rounded-lg px-3 py-1 font-mono text-lg font-bold" :class="left <= 60 ? 'bg-red-100 text-red-700' : 'bg-gray-100 dark:bg-gray-700'"><ClockIcon class="h-5 w-5" /> {{ clock }}</span>
                <button v-if="left === null" @click="startClock" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600"><PlayIcon class="h-4 w-4" /> {{ station.minutes }} {{ t('exam.minutes', 'دقيقة') }}</button>
                <button v-else @click="stopClock" class="rounded-lg border border-gray-300 p-1.5 dark:border-gray-600"><StopIcon class="h-4 w-4" /></button>
            </div>
        </template>

        <div class="grid gap-4 p-4 sm:p-6 lg:grid-cols-3">
            <aside class="space-y-2">
                <input v-model="search" :placeholder="t('exam.search_student', 'بحث بالاسم أو الرقم')" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                <button v-for="c in shown" :key="c.id" @click="pick(c)" class="flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-start text-sm"
                    :class="current?.id === c.id ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'">
                    <span class="min-w-0 flex-1 truncate">{{ c.name }}<span v-if="c.number" class="ms-1 font-mono text-xs text-gray-500">{{ c.number }}</span></span>
                    <span v-if="c.mark" class="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"><CheckIcon class="h-4 w-4" /> {{ c.mark.total }}</span>
                </button>
            </aside>

            <section v-if="current" class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 lg:col-span-2 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center gap-3">
                    <img v-if="current.photo" :src="current.photo" alt="" class="h-16 w-12 rounded object-cover" />
                    <div v-else class="flex h-16 w-12 items-center justify-center rounded bg-gray-100 text-gray-400 dark:bg-gray-700"><UserIcon class="h-6 w-6" /></div>
                    <div class="min-w-0 flex-1"><p class="text-lg font-semibold">{{ current.name }}</p><p v-if="current.number" class="font-mono text-sm text-gray-500">{{ current.number }}</p></div>
                    <p class="text-2xl font-bold">{{ total }}<span class="text-sm text-gray-400"> / {{ station.max }}</span></p>
                </div>
                <OsceJourney v-if="current.journey?.length" :journey="current.journey" :here="station.id" class="rounded-lg bg-gray-50 p-2 dark:bg-gray-900/40" />
                <p v-if="left === null && current.mark === null" class="text-xs text-blue-700 dark:text-blue-300">{{ t('exam.osce_start_hint', 'اضغط زر الوقت عند دخول المرشّح لتُحسب مدة بقائه في المحطة على مساره.') }}</p>
                <div v-for="(c, i) in station.checklist" :key="i" class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-2 text-sm dark:border-gray-700">
                    <span class="min-w-0 flex-1">{{ c.item }} <span class="text-xs text-gray-400">({{ c.points }})</span></span>
                    <button type="button" @click="scores[i] = c.points" class="flex items-center gap-1 rounded-lg px-3 py-2" :class="scores[i] === c.points ? 'bg-emerald-600 text-white' : 'border border-gray-300 dark:border-gray-600'"><CheckIcon class="h-4 w-4" /> {{ t('exam.osce_done', 'أدّاه') }}</button>
                    <button v-if="c.points > 0.5" type="button" @click="scores[i] = c.points / 2" class="flex items-center gap-1 rounded-lg px-3 py-2" :class="scores[i] === c.points / 2 ? 'bg-amber-500 text-white' : 'border border-gray-300 dark:border-gray-600'"><MinusIcon class="h-4 w-4" /> {{ t('exam.osce_partly', 'جزئياً') }}</button>
                    <button type="button" @click="scores[i] = 0" class="flex items-center gap-1 rounded-lg px-3 py-2" :class="scores[i] === 0 ? 'bg-red-600 text-white' : 'border border-gray-300 dark:border-gray-600'"><XMarkIcon class="h-4 w-4" /> {{ t('exam.osce_not', 'لم يؤدِّه') }}</button>
                </div>
                <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 text-sm dark:border-gray-700">
                    <span class="text-xs text-gray-500">{{ t('exam.global_rating', 'التقدير العام') }}</span>
                    <button v-for="g in 5" :key="g" type="button" @click="globalRating = g" class="h-9 w-9 rounded-lg" :class="globalRating === g ? 'bg-[var(--accent-600)] text-white' : 'border border-gray-300 dark:border-gray-600'">{{ g }}</button>
                </div>
                <textarea v-model="note" rows="2" :placeholder="t('exam.osce_note_ph', 'ملاحظة للمرشّح أو للّجنة (اختياري)')" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                <div class="flex items-center gap-3">
                    <button @click="save" :disabled="saving || scores.some((s) => s === null)" class="rounded-lg bg-[var(--accent-600)] px-5 py-2 text-sm text-white disabled:opacity-40">{{ saving ? '…' : t('action.save', 'حفظ') }}</button>
                    <span v-if="scores.some((s) => s === null)" class="text-xs text-gray-500">{{ t('exam.osce_all_items', 'قيّم كل البنود ثم احفظ.') }}</span>
                    <span v-if="saved" class="flex items-center gap-1 text-sm text-emerald-700 dark:text-emerald-400"><CheckIcon class="h-4 w-4" /> {{ t('exam.saved', 'محفوظ') }}</span>
                </div>
            </section>
            <p v-else class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 lg:col-span-2 dark:border-gray-600">{{ t('exam.osce_pick', 'اختر المرشّح الذي أمامك.') }}</p>
        </div>
    </AppLayout>
</template>
