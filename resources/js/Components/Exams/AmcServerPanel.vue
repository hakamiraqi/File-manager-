<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useTrans } from '@/Composables/useTrans';
import { ServerIcon, DocumentArrowDownIcon, ArrowUpTrayIcon, PlayIcon, CheckIcon, ExclamationTriangleIcon, ArrowPathIcon, TrashIcon } from '@heroicons/vue/24/outline';

// Auto Multiple Choice run by the server: build → print → upload the scans → read and mark → results.
const props = defineProps({ exam: Object });
const { t } = useTrans();
const info = ref(null);
const busy = ref('');
const message = ref(null);   // { ok, text }
const uploaded = ref(0);
let poll = null;

const project = computed(() => info.value?.project ?? { state: 'none' });
const STEPS = { prepare: 'تجهيز الأوراق', layout: 'حساب أماكن الخانات', scoring: 'قواعد التصحيح', images: 'تحويل المسح إلى صور', analyse: 'قراءة الخانات', note: 'التصحيح', association: 'ربط الأوراق بالطلاب', export: 'تصدير النتائج' };

async function load() {
    try { info.value = (await window.axios.get(route('exams.manage.amc.server', props.exam.id))).data; } catch { info.value = { configured: false }; }
    if (project.value.state === 'processing' && !poll) poll = setInterval(watch, 4000);
}
async function watch() {
    await load();
    if (project.value.state !== 'processing') {
        clearInterval(poll); poll = null;
        if (project.value.state === 'ready') await importResults();
        else if (project.value.state === 'failed') message.value = { ok: false, text: t('exam.amc_failed', 'فشلت المعالجة') + ': ' + (project.value.error ?? '') };
    }
}
onMounted(load);
onBeforeUnmount(() => clearInterval(poll));

async function run(name, fn) {
    busy.value = name; message.value = null;
    try { await fn(); } catch (e) { message.value = { ok: false, text: e.response?.data?.message || e.message }; } finally { busy.value = ''; }
}
const build = () => run('build', async () => {
    await window.axios.post(route('exams.manage.amc.server.build', props.exam.id), {}, { timeout: 900000 });
    message.value = { ok: true, text: t('exam.amc_built', 'الأوراق جاهزة للطباعة: ورقة باسم كل طالب.') };
    await load();
});
async function upload(event) {
    const files = [...event.target.files];
    event.target.value = '';
    await run('upload', async () => {
        for (const f of files) {
            const body = new FormData();
            body.append('file', f);
            await window.axios.post(route('exams.manage.amc.server.scans', props.exam.id), body, { timeout: 600000 });
            uploaded.value++;
        }
        await load();
    });
}
const clearScans = () => run('clear', async () => { await window.axios.delete(route('exams.manage.amc.server.scans.clear', props.exam.id)); uploaded.value = 0; await load(); });
const process = () => run('process', async () => {
    await window.axios.post(route('exams.manage.amc.server.process', props.exam.id));
    await load();
    poll ??= setInterval(watch, 4000);
});
async function importResults() {
    await run('import', async () => {
        const r = (await window.axios.post(route('exams.manage.amc.server.import', props.exam.id))).data;
        message.value = { ok: true, text: t('exam.amc_imported', 'أُدخلت نتائج :n طالباً؛ تظهر في صفحة النتائج.', { n: r.attempts }) + (r.unknown?.length ? ' ' + t('exam.amc_unknown', 'أوراق لم تُربط بطالب: :k', { k: r.unknown.join('، ') }) : '') };
    });
}
const btn = 'flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-xs disabled:opacity-40';
</script>

<template>
    <section v-if="info?.configured" class="w-full space-y-2 rounded-lg border border-indigo-200 bg-indigo-50/50 p-3 text-sm">
        <p class="flex items-center gap-2 font-medium text-indigo-900"><ServerIcon class="h-5 w-5" /> {{ t('exam.amc_server', 'Auto Multiple Choice على السيرفر') }}
            <span v-if="info.health?.ok" class="text-xs font-normal text-emerald-700">{{ t('exam.amc_up', 'يعمل') }}</span>
            <span v-else class="text-xs font-normal text-red-600">{{ t('exam.amc_down', 'لا يستجيب') }} ({{ info.health?.error }})</span></p>
        <ol class="space-y-2">
            <li class="flex flex-wrap items-center gap-2"><span class="w-5 text-xs text-gray-500">1</span>
                <button type="button" @click="build" :disabled="!!busy || !info.health?.ok" :class="btn"><ArrowPathIcon class="h-4 w-4" :class="busy === 'build' ? 'animate-spin' : ''" /> {{ busy === 'build' ? t('exam.amc_building', 'يجهّز الأوراق… (قد يأخذ دقيقة)') : t('exam.amc_build', 'تجهيز الأوراق من الامتحان') }}</button>
                <template v-if="['built', 'ready', 'failed', 'processing'].includes(project.state) && project.pages">
                    <a :href="route('exams.manage.amc.server.pdf', exam.id)" target="_blank" :class="btn"><DocumentArrowDownIcon class="h-4 w-4" /> {{ t('exam.amc_sheets', 'الأوراق للطباعة') }} ({{ project.pages }})</a>
                    <a :href="route('exams.manage.amc.server.pdf', { exam: exam.id, which: 'correction' })" target="_blank" class="text-xs text-indigo-700 underline">{{ t('exam.amc_key', 'ورقة الحل') }}</a>
                </template>
                <span class="text-xs text-gray-500">{{ t('exam.amc_build_hint', 'أعد التجهيز إن غيّرت الأسئلة أو الطلاب، ثم اطبع من جديد.') }}</span></li>
            <li class="flex flex-wrap items-center gap-2"><span class="w-5 text-xs text-gray-500">2</span>
                <label :class="[btn, 'cursor-pointer', !['built', 'ready', 'failed'].includes(project.state) || busy ? 'pointer-events-none opacity-40' : '']"><input type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff" class="sr-only" @change="upload" /><ArrowUpTrayIcon class="h-4 w-4" /> {{ busy === 'upload' ? t('exam.amc_uploading', 'يرفع… :n', { n: uploaded }) : t('exam.amc_upload', 'رفع المسح (PDF أو صور)') }}</label>
                <span class="text-xs text-gray-500">{{ t('exam.amc_scans_n', 'ملفات مرفوعة: :n', { n: project.scans ?? 0 }) }} · {{ t('exam.amc_scan_hint', 'امسح بدقة 200–300 نقطة، كل ملف حتى 32 ميغابايت.') }}</span>
                <button v-if="project.scans" type="button" @click="clearScans" :disabled="!!busy" class="text-gray-400 hover:text-red-500" :title="t('exam.amc_clear', 'حذف المسح المرفوع')"><TrashIcon class="h-4 w-4" /></button></li>
            <li class="flex flex-wrap items-center gap-2"><span class="w-5 text-xs text-gray-500">3</span>
                <button type="button" @click="process" :disabled="!!busy || !project.scans || project.state === 'processing'" :class="btn"><PlayIcon class="h-4 w-4" /> {{ t('exam.amc_process', 'قراءة الأوراق وتصحيحها') }}</button>
                <span v-if="project.state === 'processing'" class="flex items-center gap-1 text-xs text-indigo-800"><ArrowPathIcon class="h-4 w-4 animate-spin" /> {{ t('exam.amc_step', 'جارٍ: :s', { s: t('exam.amc_step_' + project.step, STEPS[project.step] ?? project.step ?? '') }) }}</span>
                <button v-if="project.state === 'ready'" type="button" @click="importResults" :disabled="!!busy" class="text-xs text-indigo-700 underline">{{ t('exam.amc_reimport', 'إدخال النتائج مرة أخرى') }}</button></li>
        </ol>
        <p v-if="message" class="flex items-start gap-1 text-xs" :class="message.ok ? 'text-emerald-700' : 'text-red-600'"><CheckIcon v-if="message.ok" class="h-4 w-4 shrink-0" /><ExclamationTriangleIcon v-else class="h-4 w-4 shrink-0" /><span class="whitespace-pre-line">{{ message.text }}</span></p>
    </section>
</template>
