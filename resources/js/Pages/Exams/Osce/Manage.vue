<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import OsceJourney from '@/Components/Exams/OsceJourney.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, PlusIcon, TrashIcon, ArrowUpIcon, ArrowDownIcon, ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline';

// A practical exam: its stations and checklists, who assesses each, the rotation, and the marks.
const props = defineProps({ exam: Object, stations: Array, candidates: Array, max: Number });
const { t } = useTrans();
const form = useForm({ stations: props.stations.map((s) => ({ ...s, checklist: s.checklist.map((c) => ({ ...c })) })) });
const station = () => ({ id: null, name: '', minutes: 8, assessor_email: '', checklist: [{ item: '', points: 1 }] });
const add = () => form.stations.push(station());
const move = (i, d) => { const j = i + d; if (j < 0 || j >= form.stations.length) return; const l = form.stations; [l[i], l[j]] = [l[j], l[i]]; };
const save = () => form.put(route('exams.manage.osce.stations', props.exam.id), { preserveScroll: true });
const stationMax = (s) => s.checklist.reduce((n, c) => n + Number(c.points || 0), 0);

// The rotation: in round r, candidate i is at station (i + r) mod n.
const rounds = computed(() => props.stations.length);
const at = (i, r) => props.stations[(i + r) % props.stations.length]?.name;
const total = (c) => Object.values(c.marks).reduce((n, m) => n + (m ? Number(m.total) : 0), 0);
const done = (c) => Object.values(c.marks).filter(Boolean).length;
const input = 'rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700';
// «مسار المرشّح»: tap a candidate to see the route; refreshed while someone is at a station.
const openRoute = ref(null);
let refresh = null;
onMounted(() => (refresh = setInterval(() => {
    if (props.candidates.some((c) => c.journey?.some((s) => s.state === 'now'))) router.reload({ only: ['candidates'], preserveScroll: true, preserveState: true });
}, 15000)));
onBeforeUnmount(() => clearInterval(refresh));
</script>

<template>
    <Head :title="t('exam.osce', 'امتحان عملي (OSCE)') + ' — ' + exam.title" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('exams.manage.edit', exam.id)" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="flex-1 truncate text-lg font-semibold">{{ t('exam.osce', 'امتحان عملي (OSCE)') }} — {{ exam.title }}</h2>
                <Link :href="route('exams.monitor.osce')" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600"><ClipboardDocumentCheckIcon class="h-4 w-4" /> {{ t('exam.osce_assess', 'التقييم في المحطات') }}</Link>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <form @submit.prevent="save" class="space-y-3">
                <section v-for="(s, i) in form.stations" :key="i" class="space-y-2 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-wrap items-end gap-2">
                        <span class="rounded bg-gray-100 px-2 py-1 text-xs dark:bg-gray-700">{{ i + 1 }}</span>
                        <label class="min-w-0 flex-1"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.station_name', 'المحطة (المهمة)') }}</span><input v-model="s.name" :class="input" class="w-full" /></label>
                        <label><span class="mb-1 block text-xs text-gray-500">{{ t('exam.minutes_label', 'دقائق') }}</span><input v-model.number="s.minutes" type="number" min="1" max="120" :class="input" class="w-20" /></label>
                        <label><span class="mb-1 block text-xs text-gray-500">{{ t('exam.assessor', 'المقيِّم (بريده)') }}</span><input v-model="s.assessor_email" type="email" dir="ltr" :class="input" class="w-56" /></label>
                        <button type="button" @click="move(i, -1)" class="p-1.5 text-gray-400"><ArrowUpIcon class="h-4 w-4" /></button>
                        <button type="button" @click="move(i, 1)" class="p-1.5 text-gray-400"><ArrowDownIcon class="h-4 w-4" /></button>
                        <button type="button" @click="form.stations.splice(i, 1)" class="p-1.5 text-gray-400 hover:text-red-500"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                    <p v-if="form.errors[`stations.${i}.assessor_email`]" class="text-xs text-red-600">{{ form.errors[`stations.${i}.assessor_email`] }}</p>
                    <p class="text-xs text-gray-500">{{ t('exam.checklist', 'قائمة التقييم') }} ({{ stationMax(s) }} {{ t('exam.points', 'درجة') }})</p>
                    <div v-for="(c, k) in s.checklist" :key="k" class="flex items-center gap-2">
                        <input v-model="c.item" :placeholder="t('exam.checklist_item', 'بند (مثل: غسل اليدين قبل الفحص)')" :class="input" class="min-w-0 flex-1" />
                        <input v-model.number="c.points" type="number" min="0" step="0.5" :class="input" class="w-20" />
                        <button type="button" @click="s.checklist.splice(k, 1)" class="p-1 text-gray-400 hover:text-red-500"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                    <button type="button" @click="s.checklist.push({ item: '', points: 1 })" class="flex items-center gap-1 text-xs text-[var(--accent-600)]"><PlusIcon class="h-3.5 w-3.5" /> {{ t('exam.add_item', 'بند') }}</button>
                </section>
                <div class="flex items-center gap-2">
                    <button type="button" @click="add" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><PlusIcon class="h-4 w-4" /> {{ t('exam.add_station', 'محطة') }}</button>
                    <button :disabled="form.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('action.save', 'حفظ') }}</button>
                    <span class="text-xs text-gray-500">{{ t('exam.osce_hint', 'يدخل كل مقيِّم من «المراقبة ← التقييم في المحطات» على جهازه ويملأ قائمة كل مرشّح.') }}</span>
                </div>
            </form>

            <section v-if="stations.length && candidates.length" class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-700/50">
                        <tr><th class="px-3 py-2 text-start">{{ t('exam.candidate', 'المرشّح') }}</th>
                            <th v-for="r in rounds" :key="'r' + r" class="px-2 py-2">{{ t('exam.round', 'الدور :n', { n: r }) }}</th>
                            <th v-for="s in stations" :key="s.id" class="px-2 py-2">{{ s.name }}<span class="block font-normal">/ {{ s.max }}</span></th>
                            <th class="px-3 py-2">{{ t('exam.total', 'المجموع') }} / {{ max }}</th>
                            <th class="px-3 py-2">{{ t('exam.osce_route', 'مسار المرشّح') }}</th></tr>
                    </thead>
                    <tbody>
                        <template v-for="(c, i) in candidates" :key="c.id">
                        <tr class="cursor-pointer border-t border-gray-100 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/40" @click="openRoute = openRoute === c.id ? null : c.id">
                            <td class="px-3 py-1.5"><p class="font-medium">{{ c.name }}</p><p v-if="c.number" class="font-mono text-xs text-gray-500">{{ c.number }}</p></td>
                            <td v-for="r in rounds" :key="'r' + r" class="px-2 py-1.5 text-center text-xs text-gray-500">{{ at(i, r - 1) }}</td>
                            <td v-for="s in stations" :key="s.id" class="px-2 py-1.5 text-center">{{ c.marks[s.id]?.total ?? '—' }}</td>
                            <td class="px-3 py-1.5 text-center font-semibold">{{ total(c) }}<span v-if="done(c) < stations.length" class="block text-[10px] font-normal text-amber-600">{{ t('exam.stations_left', 'بقيت :n محطة', { n: stations.length - done(c) }) }}</span></td>
                            <td class="px-3 py-1.5"><OsceJourney :journey="c.journey ?? []" compact /></td>
                        </tr>
                        <tr v-if="openRoute === c.id" class="bg-gray-50/60 dark:bg-gray-900/30">
                            <td :colspan="3 + rounds + stations.length" class="px-3 py-3"><OsceJourney :journey="c.journey ?? []" /></td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
