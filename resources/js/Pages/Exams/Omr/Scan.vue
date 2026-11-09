<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { ArrowRightIcon, ArrowUpTrayIcon, CheckIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';
import { readSheet } from '@/Components/Exams/omrRead';
import { LETTERS } from '@/Components/Exams/omrLayout';

// Read the scanned sheets here (nothing leaves the computer but the answers), check the
// doubtful bubbles, then save: each sheet becomes the student's marked attempt.
const props = defineProps({ exam: Object, questions: Array, students: Object, skipped: Number });
const { t } = useTrans();
const sheets = ref([]);   // { file, url, status, code, answers, doubtful, name, score, error }
const busy = ref(false);
const ERR = { corners: ['لم تُعثر على المربعات السوداء الأربعة: امسح الورقة كاملة ومستقيمة.', 'exam.omr_err_corners'], qr: ['لم يُقرأ رمز QR للورقة.', 'exam.omr_err_qr'] };

const studentOf = (code) => { const m = /^EXOMR:(\d+):(\d+):/.exec(code || ''); return m && Number(m[1]) === props.exam.id ? props.students[m[2]] ?? null : null; };
function load(file) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => resolve({ img, url });
        img.onerror = () => resolve({ img: null, url });
        img.src = url;
    });
}
async function add(event) {
    const files = [...event.target.files];
    event.target.value = '';
    busy.value = true;
    for (const file of files) {
        const { img, url } = await load(file);
        const r = img ? readSheet(img, props.questions) : { error: 'image' };
        sheets.value.push({ file: file.name, url, status: r.error ? 'error' : 'read', error: r.error, code: r.code, answers: r.answers ?? {}, doubtful: r.doubtful ?? [], name: studentOf(r.code), score: null });
        await new Promise((res) => setTimeout(res, 0));
    }
    busy.value = false;
}
function toggle(s, q, i) {
    const cur = s.answers[q.id] ?? [];
    s.answers[q.id] = q.type === 'multiple' ? (cur.includes(i) ? cur.filter((x) => x !== i) : [...cur, i]) : (cur.includes(i) ? [] : [i]);
    s.doubtful = s.doubtful.filter((x) => x !== q.id);
    s.status = 'read';
}
async function save(s) {
    try {
        const r = (await window.axios.post(route('exams.manage.omr.store', props.exam.id), { code: s.code, answers: s.answers })).data;
        Object.assign(s, { status: 'saved', score: r.score + ' / ' + r.max, name: r.name });
    } catch (e) {
        Object.assign(s, { status: 'error', error: e.response?.data?.message || 'save' });
    }
}
async function saveAll() {
    for (const s of sheets.value) if (s.status === 'read' && !s.doubtful.length) await save(s);
}
const letter = (q, i) => (q.type === 'true_false' ? ['T', 'F'][i] : LETTERS[i]);
</script>

<template>
    <Head :title="t('exam.omr_scan', 'قراءة الأوراق الممسوحة')" />
    <AppLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-2">
                <Link :href="route('exams.manage.omr', exam.id)" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="flex-1 truncate text-lg font-semibold">{{ t('exam.omr_scan', 'قراءة الأوراق الممسوحة') }} — {{ exam.title }}</h2>
                <label class="flex cursor-pointer items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600"><input type="file" accept="image/*" multiple class="sr-only" @change="add" /><ArrowUpTrayIcon class="h-4 w-4" /> {{ busy ? '…' : t('exam.omr_add', 'صور الأوراق') }}</label>
                <button @click="saveAll" :disabled="!sheets.some((s) => s.status === 'read' && !s.doubtful.length)" class="rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm text-white disabled:opacity-40">{{ t('exam.omr_save_all', 'حفظ كل الأوراق الواضحة') }}</button>
            </div>
        </template>
        <div class="space-y-3 p-4 sm:p-6">
            <p class="text-xs text-gray-500">{{ t('exam.omr_scan_hint', 'امسح الأوراق بماسح ضوئي (أو صوّرها مستقيمة على سطح فاتح تظهر فيها الزوايا الأربع). تُقرأ هنا في المتصفح. الأسئلة المشكوك فيها تظهر بلون أصفر: اضغط الخيار الصحيح ثم احفظ.') }}</p>
            <div v-for="(s, k) in sheets" :key="k" class="rounded-xl border bg-white p-3 text-sm dark:bg-gray-800" :class="s.status === 'saved' ? 'border-emerald-300' : s.status === 'error' ? 'border-red-300' : s.doubtful.length ? 'border-amber-300' : 'border-gray-200 dark:border-gray-700'">
                <div class="flex flex-wrap items-center gap-3">
                    <img :src="s.url" alt="" class="h-16 w-12 rounded border object-cover" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ s.name || t('exam.omr_unknown', 'طالب غير معروف') }}</p>
                        <p class="text-xs text-gray-500">{{ s.file }}</p>
                    </div>
                    <span v-if="s.status === 'saved'" class="flex items-center gap-1 text-emerald-700"><CheckIcon class="h-4 w-4" /> {{ s.score }}</span>
                    <span v-else-if="s.status === 'error'" class="flex items-center gap-1 text-red-600"><ExclamationTriangleIcon class="h-4 w-4" /> {{ ERR[s.error] ? t(ERR[s.error][1], ERR[s.error][0]) : s.error }}</span>
                    <span v-else-if="s.doubtful.length" class="text-xs text-amber-700">{{ t('exam.omr_doubtful', ':n سؤالاً يحتاج نظرة', { n: s.doubtful.length }) }}</span>
                    <button v-if="s.status === 'read'" @click="save(s)" :disabled="!s.name" class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40 dark:border-gray-600">{{ t('action.save', 'حفظ') }}</button>
                </div>
                <div v-if="s.status !== 'error'" class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                    <span v-for="(q, i) in questions" :key="q.id" class="flex items-center gap-1 rounded px-1" :class="s.doubtful.includes(q.id) ? 'bg-amber-100 dark:bg-amber-900/30' : ''">
                        <span class="w-5 text-end text-xs text-gray-500">{{ i + 1 }}</span>
                        <button v-for="o in q.options" :key="o" type="button" @click="toggle(s, q, o - 1)" :disabled="s.status === 'saved'"
                            class="h-6 w-6 rounded-full border text-[10px]" :class="(s.answers[q.id] ?? []).includes(o - 1) ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-gray-300 dark:border-gray-600'">{{ letter(q, o - 1) }}</button>
                    </span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
