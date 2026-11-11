<script setup>
// «تجربة Qari» (2026-11-11, by request): one Arabic page read by Tesseract (what the library
// uses now) and by Qari 2B / 4B side by side, with the time each took on this server.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ArrowUpTrayIcon, BeakerIcon, ClockIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ models: Array, trials: Array });

const engines = computed(() => [
    { key: 'tesseract', label: 'Tesseract (الحالي)', up: true },
    ...props.models,
]);
const chosen = ref(['tesseract', ...props.models.filter((m) => m.up).map((m) => m.key)]);
const file = ref(null);
const page = ref(1);
const busy = ref(false);
const error = ref('');
const trials = ref([...props.trials]);
const now = ref(Date.now());

const isPdf = computed(() => file.value?.name?.toLowerCase().endsWith('.pdf'));
const label = (key) => engines.value.find((e) => e.key === key)?.label || key;

async function start() {
    if (!file.value || !chosen.value.length) return;
    busy.value = true;
    error.value = '';
    try {
        const fd = new FormData();
        fd.append('file', file.value);
        fd.append('page', String(page.value || 1));
        chosen.value.forEach((e) => fd.append('engines[]', e));
        const { data } = await axios.post(route('admin.qari.store'), fd);
        trials.value.unshift(data);
    } catch (e) {
        error.value = e.response?.data?.message || 'تعذّر بدء التجربة.';
    } finally {
        busy.value = false;
    }
}

const running = (t) => Object.values(t.results || {}).some((r) => r.state === 'waiting' || r.state === 'running');

let timer = null;
async function poll() {
    now.value = Date.now();
    for (const [i, t] of trials.value.entries()) {
        if (!running(t)) continue;
        try {
            const { data } = await axios.get(route('admin.qari.show', t.id));
            trials.value[i] = data;
        } catch {}
    }
}
onMounted(() => { timer = setInterval(poll, 3000); });
onBeforeUnmount(() => clearInterval(timer));

const cols = { 2: 'lg:grid-cols-2', 3: 'lg:grid-cols-3', 4: 'lg:grid-cols-4' };
const words = (s) => (s || '').trim().split(/\s+/).filter(Boolean).length;
</script>

<template>
    <AdminLayout>
        <Head title="تجربة Qari" />
        <div class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6">
            <div class="flex items-start gap-3">
                <BeakerIcon class="h-8 w-8 shrink-0 text-indigo-500" />
                <div>
                    <h1 class="text-xl font-bold">تجربة Qari لقراءة النص العربي</h1>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        ارفع صفحة عربية (صورة أو PDF مع رقم الصفحة)، فتُقرأ بـTesseract الحالي وبنموذجَي Qari على هذا الخادم، وتظهر النتائج
                        جنباً إلى جنب مع الوقت. هذه الصفحة منفصلة ولا تغيّر شيئاً في المكتبة.
                    </p>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-wrap items-end gap-4">
                    <label class="flex min-w-[16rem] flex-1 cursor-pointer items-center gap-3 rounded-xl border-2 border-dashed border-gray-300 p-3 hover:border-indigo-400 dark:border-gray-600">
                        <ArrowUpTrayIcon class="h-6 w-6 text-gray-400" />
                        <span class="truncate text-sm">{{ file ? file.name : 'اختر صورة أو ملف PDF' }}</span>
                        <input type="file" class="hidden" accept=".pdf,image/*" @change="file = $event.target.files[0] || null" />
                    </label>
                    <label v-if="isPdf" class="text-sm">
                        رقم الصفحة
                        <input v-model.number="page" type="number" min="1" class="mt-1 block w-24 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900" />
                    </label>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="e in engines" :key="e.key" class="flex items-center gap-2 text-sm" :class="!e.up && 'opacity-50'">
                            <input v-model="chosen" type="checkbox" :value="e.key" :disabled="!e.up" class="rounded" />
                            {{ e.label }}
                            <span v-if="!e.up" class="text-xs text-red-500">(متوقف)</span>
                        </label>
                    </div>
                    <button
                        class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="!file || !chosen.length || busy"
                        @click="start"
                    >
                        {{ busy ? 'جارٍ الرفع…' : 'ابدأ القراءة' }}
                    </button>
                </div>
                <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>
                <p class="mt-3 text-xs text-gray-500">تُقرأ الصفحات واحدة بعد أخرى في الخلفية؛ قد يأخذ Qari دقائق للصفحة لأن الخادم بلا بطاقة رسوميات.</p>
            </div>

            <div v-for="t in trials" :key="t.id" class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-semibold" dir="auto">{{ t.name }}</span>
                    <span v-if="t.name?.toLowerCase().endsWith('.pdf')" class="text-gray-500">— صفحة {{ t.page }}</span>
                    <span class="text-gray-400" dir="ltr">{{ new Date(t.at).toLocaleString('ar-IQ') }}</span>
                </div>
                <div class="grid gap-4" :class="cols[Math.min(4, (t.engines?.length || 1) + 1)]">
                    <a :href="route('admin.qari.image', t.id)" target="_blank" class="block">
                        <img :src="route('admin.qari.image', t.id)" class="max-h-[32rem] w-full rounded-lg border object-contain dark:border-gray-700" alt="" />
                    </a>
                    <div v-for="key in t.engines" :key="key" class="flex min-w-0 flex-col rounded-xl border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                            <span class="font-semibold">{{ label(key) }}</span>
                            <span v-if="t.results[key]?.state === 'done'" class="flex items-center gap-1 text-xs text-gray-500">
                                <ClockIcon class="h-4 w-4" /> {{ t.results[key].seconds }} ث · {{ words(t.results[key].text) }} كلمة
                            </span>
                            <span v-else-if="t.results[key]?.state === 'running'" class="text-xs text-indigo-600">يقرأ الآن…</span>
                            <span v-else-if="t.results[key]?.state === 'waiting'" class="text-xs text-gray-400">بالانتظار</span>
                        </div>
                        <div v-if="t.results[key]?.state === 'failed'" class="flex gap-2 p-3 text-sm text-red-600">
                            <ExclamationTriangleIcon class="h-5 w-5 shrink-0" /> {{ t.results[key].error }}
                        </div>
                        <pre
                            v-else-if="t.results[key]?.state === 'done'"
                            dir="auto"
                            class="max-h-[32rem] flex-1 overflow-auto whitespace-pre-wrap break-words p-3 font-sans text-sm leading-7"
                        >{{ t.results[key].text || '(لا يوجد نص)' }}</pre>
                        <div v-else class="flex flex-1 items-center justify-center p-8">
                            <div class="h-6 w-6 animate-spin rounded-full border-2 border-indigo-500 border-t-transparent" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
