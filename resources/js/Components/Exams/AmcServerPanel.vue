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
const btn = 'inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:hover:bg-gray-700';
const primary = 'inline-flex items-center justify-center gap-1.5 rounded-xl bg-[var(--accent-600)] px-4 py-2 text-sm font-semibold text-white disabled:opacity-40';
// Which step is done, which is next (2026-11-09, redesigned as numbered steps).
const built = computed(() => ['built', 'ready', 'failed', 'processing'].includes(project.value.state) && !!project.value.pages);
const done = computed(() => [built.value, (project.value.scans ?? 0) > 0, project.value.state === 'ready']);
const current = computed(() => done.value.findIndex((d) => !d));
</script>

<template>
    <section v-if="info?.configured" class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
            <ServerIcon class="h-5 w-5 text-[var(--accent-600)]" />
            <h2 class="min-w-0 flex-1 truncate font-semibold">{{ t('exam.amc_server', 'Auto Multiple Choice على السيرفر') }}</h2>
            <span v-if="info.health?.ok" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300"><span class="h-2 w-2 rounded-full bg-emerald-500" /> {{ t('exam.amc_up', 'يعمل') }}</span>
            <span v-else class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300" :title="info.health?.error"><span class="h-2 w-2 rounded-full bg-red-500" /> {{ t('exam.amc_down', 'لا يستجيب') }}</span>
        </div>
        <ol class="divide-y divide-gray-100 dark:divide-gray-700">
            <!-- 1. The sheets -->
            <li class="flex gap-3 p-4">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold" :class="done[0] ? 'bg-emerald-500 text-white' : current === 0 ? 'bg-[var(--accent-600)] text-white' : 'bg-gray-200 text-gray-500 dark:bg-gray-700'"><CheckIcon v-if="done[0]" class="h-4 w-4" /><template v-else>1</template></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <p class="font-medium">{{ t('exam.amc_s1', 'تجهيز الأوراق وطباعتها') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="build" :disabled="!!busy || !info.health?.ok" :class="built ? btn : primary"><ArrowPathIcon class="h-4 w-4" :class="busy === 'build' ? 'animate-spin' : ''" /> {{ busy === 'build' ? t('exam.amc_building', 'يجهّز الأوراق… (قد يأخذ دقيقة)') : built ? t('exam.amc_rebuild', 'إعادة التجهيز') : t('exam.amc_build', 'تجهيز الأوراق من الامتحان') }}</button>
                        <template v-if="built">
                            <a :href="route('exams.manage.amc.server.pdf', exam.id)" target="_blank" :class="primary"><DocumentArrowDownIcon class="h-4 w-4" /> {{ t('exam.amc_sheets', 'الأوراق للطباعة') }} ({{ project.pages }})</a>
                            <a :href="route('exams.manage.amc.server.pdf', { exam: exam.id, which: 'correction' })" target="_blank" :class="btn">{{ t('exam.amc_key', 'ورقة الحل') }}</a>
                        </template>
                    </div>
                    <p class="text-xs text-gray-500">{{ t('exam.amc_build_hint', 'أعد التجهيز إن غيّرت الأسئلة أو الطلاب، ثم اطبع من جديد.') }}</p>
                </div>
            </li>
            <!-- 2. The scans -->
            <li class="flex gap-3 p-4" :class="!built ? 'opacity-60' : ''">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold" :class="done[1] ? 'bg-emerald-500 text-white' : current === 1 ? 'bg-[var(--accent-600)] text-white' : 'bg-gray-200 text-gray-500 dark:bg-gray-700'"><CheckIcon v-if="done[1]" class="h-4 w-4" /><template v-else>2</template></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <p class="font-medium">{{ t('exam.amc_s2', 'رفع الأوراق الممسوحة بعد الامتحان') }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <label :class="[current === 1 ? primary : btn, 'cursor-pointer', !['built', 'ready', 'failed'].includes(project.state) || busy ? 'pointer-events-none opacity-40' : '']"><input type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff" class="sr-only" @change="upload" /><ArrowUpTrayIcon class="h-4 w-4" /> {{ busy === 'upload' ? t('exam.amc_uploading', 'يرفع… :n', { n: uploaded }) : t('exam.amc_upload', 'رفع المسح (PDF أو صور)') }}</label>
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs dark:bg-gray-700">{{ t('exam.amc_scans_n', 'ملفات مرفوعة: :n', { n: project.scans ?? 0 }) }}</span>
                        <button v-if="project.scans" type="button" @click="clearScans" :disabled="!!busy" class="rounded-lg p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30" :title="t('exam.amc_clear', 'حذف المسح المرفوع')"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                    <p class="text-xs text-gray-500">{{ t('exam.amc_scan_hint', 'امسح بدقة 200–300 نقطة، كل ملف حتى 32 ميغابايت.') }}</p>
                </div>
            </li>
            <!-- 3. Read and mark -->
            <li class="flex gap-3 p-4" :class="!project.scans ? 'opacity-60' : ''">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold" :class="done[2] ? 'bg-emerald-500 text-white' : current === 2 ? 'bg-[var(--accent-600)] text-white' : 'bg-gray-200 text-gray-500 dark:bg-gray-700'"><CheckIcon v-if="done[2]" class="h-4 w-4" /><template v-else>3</template></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <p class="font-medium">{{ t('exam.amc_s3', 'قراءة الأوراق وتصحيحها') }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="process" :disabled="!!busy || !project.scans || project.state === 'processing'" :class="current === 2 ? primary : btn"><PlayIcon class="h-4 w-4" /> {{ t('exam.amc_process', 'قراءة الأوراق وتصحيحها') }}</button>
                        <span v-if="project.state === 'processing'" class="inline-flex items-center gap-1 text-xs text-[var(--accent-700)]"><ArrowPathIcon class="h-4 w-4 animate-spin" /> {{ t('exam.amc_step', 'جارٍ: :s', { s: t('exam.amc_step_' + project.step, STEPS[project.step] ?? project.step ?? '') }) }}</span>
                        <button v-if="project.state === 'ready'" type="button" @click="importResults" :disabled="!!busy" :class="btn">{{ t('exam.amc_reimport', 'إدخال النتائج مرة أخرى') }}</button>
                    </div>
                    <p class="text-xs text-gray-500">{{ t('exam.amc_s3_hint', 'تدخل الدرجات صفحة النتائج تلقائياً عند الانتهاء.') }}</p>
                </div>
            </li>
        </ol>
        <p v-if="message" class="flex items-start gap-2 border-t px-4 py-3 text-sm" :class="message.ok ? 'border-emerald-100 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-200' : 'border-red-100 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-900/20 dark:text-red-300'"><CheckIcon v-if="message.ok" class="h-5 w-5 shrink-0" /><ExclamationTriangleIcon v-else class="h-5 w-5 shrink-0" /><span class="whitespace-pre-line">{{ message.text }}</span></p>
    </section>
</template>
