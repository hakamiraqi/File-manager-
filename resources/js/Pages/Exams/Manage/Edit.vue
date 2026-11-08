<script setup>
import RichText from '@/Components/Exams/RichText.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import LangInput from '@/Components/Exams/LangInput.vue';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import { useTrans } from '@/Composables/useTrans';
import { PlusIcon, TrashIcon, ArrowUpIcon, ArrowDownIcon, ChartBarIcon, ArrowRightIcon, EyeIcon, PrinterIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ exam: Object, attempts: Number, languages: Array, types: Array, categories: { type: Array, default: () => [] }, groups: { type: Array, default: () => [] } });
const { t } = useTrans();
const page = usePage();

const TYPE_LABELS = {
    single: 'اختيار من متعدد (إجابة واحدة)', multiple: 'اختيار من متعدد (عدة إجابات)', true_false: 'صح / خطأ',
    short: 'إجابة قصيرة', numeric: 'رقمي', matching: 'مطابقة', ordering: 'ترتيب', cloze: 'املأ الفراغ', essay: 'مقالي',
    classify: 'تصنيف (عناصر في فئات)', upload: 'رفع ملف', hotspot: 'نقطة على صورة', calculated: 'محسوب (أرقام مختلفة لكل طالب)',
    drag_text: 'سحب كلمات إلى الفراغات', drag_image: 'سحب علامات إلى صورة', audio: 'إجابة صوتية (تسجيل)', drawing: 'رسم أو كتابة بخط اليد', description: 'نص توضيحي (بلا درجة)', random: 'أسئلة عشوائية من البنك',
};
const LANG_LABELS = { ar: 'العربية', en: 'English', ku: 'کوردی' };
const typeLabel = (type) => t('exam.type_' + type, TYPE_LABELS[type]);

const clone = (v) => JSON.parse(JSON.stringify(v));
function fromProps(exam) {
    const e = clone(exam);
    e.questions = (e.questions || []).map((q) => ({
        ...q,
        settings: Array.isArray(q.settings) ? {} : q.settings || {},
        answersText: (q.settings?.answers || []).join('\n'),
        distractorsText: (q.settings?.distractors || []).join('\n'),
        tagsText: (q.tags || []).join('، '),
    }));
    return e;
}
const form = useForm(fromProps(props.exam));

const blank = () => Object.fromEntries(form.languages.map((l) => [l, '']));
function choice(body = null, correct = false) {
    return { id: null, body: body ?? blank(), match: blank(), is_correct: correct };
}
function addQuestion(type) {
    const q = { id: null, type, body: blank(), feedback: {}, points: type === 'description' ? 0 : 1, settings: {}, choices: [], answersText: '' };
    if (type === 'single' || type === 'multiple') q.choices = [choice(), choice(), choice(), choice()];
    if (type === 'true_false') q.choices = [choice({ ar: 'صح', en: 'True', ku: 'ڕاست' }, true), choice({ ar: 'خطأ', en: 'False', ku: 'هەڵە' })];
    if (type === 'matching' || type === 'ordering') q.choices = [choice(), choice(), choice()];
    if (type === 'classify') q.choices = [choice(), choice(), choice(), choice()];
    if (type === 'upload') q.settings = { max_mb: 10, types: 'pdf,jpg,png,docx' };
    if (type === 'hotspot') q.settings = { image: '', image_url: null, zones: [] };
    if (type === 'drag_image') { q.settings = { image: '', image_url: null, zones: [] }; q.choices = [choice(), choice(), choice()]; }
    if (type === 'drag_text') { q.settings = { distractors: [] }; q.distractorsText = ''; }
    if (type === 'audio') q.settings = { max_seconds: 120 };
    if (type === 'calculated') {
        q.body = Object.fromEntries(form.languages.map((l) => [l, l === 'ar' ? 'سيارة سرعتها {v} م/ث تسير {t} ثانية. كم متراً تقطع؟' : 'A car at {v} m/s drives for {t} s. How many metres does it go?']));
        q.settings = { vars: [{ name: 'v', min: 10, max: 30, decimals: 0 }, { name: 't', min: 5, max: 20, decimals: 0 }], formula: 'v * t', tolerance: '0', decimals: '' };
    }
    if (type === 'numeric') q.settings = { answer: '', tolerance: '0' };
    if (type === 'random') q.settings = { category_id: props.categories[0]?.id ?? '', count: 5 };
    form.questions.push(q);
}
function moveQuestion(i, step) {
    const j = i + step;
    if (j < 0 || j >= form.questions.length) return;
    const list = form.questions;
    [list[i], list[j]] = [list[j], list[i]];
}
function moveChoice(q, i, step) {
    const j = i + step;
    if (j < 0 || j >= q.choices.length) return;
    [q.choices[i], q.choices[j]] = [q.choices[j], q.choices[i]];
}
function setOnlyCorrect(q, c) {
    q.choices.forEach((x) => (x.is_correct = x === c));
}
// Plain text ↔ formatted: plain lines become paragraphs; formatting is dropped going back.
function toggleFormat(q) {
    const toHtml = q.format !== 'html';
    const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const strip = (h) => { const d = document.createElement('div'); d.innerHTML = h.replace(/<\/(p|div|li|tr)>/gi, '\n').replace(/<br\s*\/?>/gi, '\n'); return d.textContent.replace(/\n{3,}/g, '\n\n').trim(); };
    for (const key of ['body', 'feedback']) {
        q[key] = Object.fromEntries(Object.entries(q[key] || {}).map(([l, v]) => [l, toHtml ? (v ? v.split('\n').map((line) => `<p>${esc(line) || '<br>'}</p>`).join('') : '') : strip(v || '')]));
    }
    q.format = toHtml ? 'html' : 'text';
}
function toggleGroup(id) {
    const list = form.group_ids ?? (form.group_ids = []);
    const i = list.indexOf(id);
    i >= 0 ? list.splice(i, 1) : list.push(id);
}
// A code the invigilator announces in the hall: easy to read aloud, no 0/O or 1/I.
function newAccessCode() {
    const chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    form.access_code = Array.from(crypto.getRandomValues(new Uint8Array(6)), (b) => chars[b % chars.length]).join('');
}
function toggleLanguage(lang) {
    const i = form.languages.indexOf(lang);
    if (i >= 0 && form.languages.length > 1) form.languages.splice(i, 1);
    else if (i < 0) form.languages.push(lang);
}

const totalPoints = computed(() => form.questions.filter((q) => q.type !== 'description')
    .reduce((s, q) => s + Number(q.points || 0) * (q.type === 'random' ? Number(q.settings?.count || 0) : 1), 0));

// ---- Question bank ----
const toBank = (q, categoryId) => categoryId && router.post(route('exams.manage.bank.from-exam', q.id), { category_id: categoryId }, { preserveScroll: true });
const bankOpen = ref(false);
const bankCategory = ref(null);
const bankQuestions = ref([]);
const bankPicked = ref([]);
async function loadBank(id) {
    bankCategory.value = id;
    bankPicked.value = [];
    // The bank page's props carry the category's questions.
    const res = await window.axios.get(route('exams.manage.bank', { category: id }), { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': page.version ?? '' } });
    bankQuestions.value = res.data?.props?.questions ?? [];
}
function addFromBank() {
    router.post(route('exams.manage.bank.to-exam', props.exam.id), { ids: bankPicked.value }, {
        preserveScroll: true,
        onSuccess: () => {
            bankOpen.value = false;
            const fresh = fromProps(page.props.exam);
            Object.keys(fresh).forEach((k) => (form[k] = fresh[k]));
            form.defaults(fresh);
        },
    });
}
// ---- A picture question: upload the picture, then drag on it to mark the right areas ----
const imageUploading = ref(null);
async function uploadImage(q, i, event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    imageUploading.value = i;
    const data = new FormData();
    data.append('image', file);
    try {
        const res = await window.axios.post(route('exams.manage.images.store'), data);
        q.settings = { ...q.settings, image: res.data.image, image_url: res.data.url, zones: q.settings.zones ?? [] };
    } catch (e) {
        form.setError(`questions.${i}.settings`, e.response?.data?.errors?.image?.[0] ?? t('exam.upload_failed', 'تعذّر رفع الملف.'));
    } finally {
        imageUploading.value = null;
    }
}
const drawing = ref(null);
const pct = (event, box) => ({
    x: Math.max(0, Math.min(100, ((event.clientX - box.left) / box.width) * 100)),
    y: Math.max(0, Math.min(100, ((event.clientY - box.top) / box.height) * 100)),
});
function startZone(q, event, slot = undefined) {
    const box = event.currentTarget.getBoundingClientRect();
    const p = pct(event, box);
    event.currentTarget.setPointerCapture?.(event.pointerId);
    drawing.value = { q, box, from: p, zone: { x: p.x, y: p.y, w: 0, h: 0 }, slot };
}
const labelSlot = ref({});   // drag_image: which label's place is being drawn, per question
function moveZone(event) {
    const d = drawing.value;
    if (!d) return;
    const p = pct(event, d.box);
    d.zone = { x: Math.min(d.from.x, p.x), y: Math.min(d.from.y, p.y), w: Math.abs(p.x - d.from.x), h: Math.abs(p.y - d.from.y) };
}
function endZone() {
    const d = drawing.value;
    drawing.value = null;
    if (!d) return;
    // A click without dragging marks a small square around the point.
    const z = d.zone.w < 1 || d.zone.h < 1 ? { x: Math.max(0, d.from.x - 4), y: Math.max(0, d.from.y - 4), w: 8, h: 8 } : d.zone;
    const round = (v) => Math.round(v * 10) / 10;
    const zone = { x: round(z.x), y: round(z.y), w: round(z.w), h: round(z.h) };
    if (d.slot !== undefined) {
        const zones = [...(d.q.settings.zones ?? [])];
        zones[d.slot] = zone;
        d.q.settings.zones = zones;
        return;
    }
    d.q.settings.zones = [...(d.q.settings.zones ?? []), zone];
}
const errorsFor = (i) => Object.entries(form.errors).filter(([k]) => k.startsWith(`questions.${i}.`) || k === `questions.${i}`).map(([, v]) => v);

// ---- Import questions (Excel / CSV / Aiken) ----
const importForm = useForm({ file: null });
const fileInput = ref(null);
function importQuestions() {
    importForm.post(route('exams.manage.import', props.exam.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset();
            if (fileInput.value) fileInput.value.value = '';
            const fresh = fromProps(page.props.exam);
            Object.keys(fresh).forEach((k) => (form[k] = fresh[k]));
            form.defaults(fresh);
        },
    });
}

function save() {
    form.transform((data) => ({
        ...data,
        questions: data.questions.map((q) => ({
            ...q,
            tags: (q.tagsText || '').split(/[,،]/).map((s) => s.trim()).filter(Boolean),
            settings: q.type === 'short' ? { ...q.settings, answers: (q.answersText || '').split('\n').map((s) => s.trim()).filter(Boolean) }
                : q.type === 'drag_text' ? { distractors: (q.distractorsText || '').split('\n').map((s) => s.trim()).filter(Boolean) } : q.settings,
        })),
    })).put(route('exams.manage.update', props.exam.id), {
        preserveScroll: true,
        onSuccess: () => {
            const fresh = fromProps(page.props.exam);
            Object.keys(fresh).forEach((k) => (form[k] = fresh[k]));
            form.defaults(fresh);
        },
    });
}
</script>

<template>
    <Head :title="t('exam.edit', 'تعديل الامتحان')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('exams.manage.index')" class="text-gray-400 hover:text-gray-600"><ArrowRightIcon class="h-5 w-5 ltr:rotate-180" /></Link>
                <h2 class="min-w-0 flex-1 truncate text-lg font-semibold">{{ form.title?.[form.languages[0]] || t('exam.edit', 'تعديل الامتحان') }}</h2>
                <Link :href="route('exams.show', exam.id)" class="hidden items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm sm:flex dark:border-gray-600"><EyeIcon class="h-4 w-4" /> {{ t('exam.preview', 'معاينة') }}</Link>
                <Link :href="route('exams.manage.print', exam.id)" class="hidden items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm sm:flex dark:border-gray-600"><PrinterIcon class="h-4 w-4" /> {{ t('exam.print', 'طباعة') }}</Link>
                <Link :href="route('exams.manage.results', exam.id)" class="hidden items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm sm:flex dark:border-gray-600"><ChartBarIcon class="h-4 w-4" /> {{ t('exam.results', 'النتائج') }}</Link>
                <button @click="save" :disabled="form.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50">{{ t('action.save', 'حفظ') }}</button>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-5 p-4 sm:p-6">
            <p v-if="attempts" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                {{ t('exam.has_attempts', 'لهذا الامتحان :n محاولة. تعديل الأسئلة لا يغيّر الدرجات حتى تضغط «إعادة التصحيح» في صفحة النتائج، وحذف سؤال يحذف إجاباته.', { n: attempts }) }}
            </p>
            <p v-if="Object.keys(form.errors).length" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ t('exam.fix_errors', 'لم يُحفظ: صحّح الأخطاء المشار إليها.') }}</p>

            <!-- Settings -->
            <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <div>
                    <p class="mb-1 text-xs font-medium text-gray-500">{{ t('exam.languages', 'لغات الامتحان (الأولى هي الافتراضية)') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="lang in languages" :key="lang" type="button" @click="toggleLanguage(lang)"
                            class="rounded-full border px-3 py-1 text-sm" :class="form.languages.includes(lang) ? 'border-[var(--accent-600)] bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700 dark:text-white' : 'border-gray-300 dark:border-gray-600'">
                            {{ LANG_LABELS[lang] ?? lang }}<span v-if="form.languages[0] === lang"> ★</span>
                        </button>
                    </div>
                </div>
                <div>
                    <p class="mb-1 text-xs font-medium text-gray-500">{{ t('exam.title', 'العنوان') }}</p>
                    <LangInput v-model="form.title" :languages="form.languages" />
                    <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
                </div>
                <div>
                    <p class="mb-1 text-xs font-medium text-gray-500">{{ t('exam.instructions', 'تعليمات للطالب') }}</p>
                    <LangInput v-model="form.description" :languages="form.languages" multiline :rows="2" />
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.subject', 'المادة') }}</span><input v-model="form.subject" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.stage', 'المرحلة / الصف') }}</span><input v-model="form.stage" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.department', 'القسم') }}</span><input v-model="form.department" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.opens', 'يفتح') }}</span><input v-model="form.opens_at" type="datetime-local" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.closes', 'يغلق') }}</span><input v-model="form.closes_at" type="datetime-local" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <span v-if="form.errors.closes_at" class="text-xs text-red-600">{{ form.errors.closes_at }}</span></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.duration_min', 'المدة (دقيقة، فارغ = بلا حد)') }}</span><input v-model="form.duration_minutes" type="number" min="1" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.max_attempts', 'عدد المحاولات') }}</span><input v-model="form.max_attempts" type="number" min="1" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.grading', 'الدرجة المعتمدة') }}</span>
                        <select v-model="form.grading_method" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option value="highest">{{ t('exam.grading_highest', 'أعلى درجة') }}</option>
                            <option value="average">{{ t('exam.grading_average', 'المعدّل') }}</option>
                            <option value="first">{{ t('exam.grading_first', 'المحاولة الأولى') }}</option>
                            <option value="last">{{ t('exam.grading_last', 'المحاولة الأخيرة') }}</option>
                        </select></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.pass_mark', 'درجة النجاح %') }}</span><input v-model="form.pass_mark" type="number" min="0" max="100" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.password', 'كلمة مرور الامتحان (اختياري)') }}</span><input v-model="form.password" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.direction', 'اتجاه النص') }}</span>
                        <select v-model="form.direction" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option value="auto">{{ t('exam.dir_auto', 'حسب اللغة الأولى') }}</option>
                            <option value="rtl">{{ t('exam.dir_rtl', 'من اليمين لليسار') }}</option>
                            <option value="ltr">{{ t('exam.dir_ltr', 'من اليسار لليمين') }}</option>
                        </select></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.navigation', 'التنقل بين الأسئلة') }}</span>
                        <select v-model="form.navigation" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option value="free">{{ t('exam.nav_free', 'حر (يرجع ويتقدّم)') }}</option>
                            <option value="sequential">{{ t('exam.nav_sequential', 'بالترتيب بلا رجوع') }}</option>
                        </select></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.calculator', 'آلة حاسبة') }}</span>
                        <select v-model="form.calculator" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option value="none">{{ t('exam.calc_none', 'لا') }}</option>
                            <option value="basic">{{ t('exam.calc_basic', 'عادية') }}</option>
                            <option value="scientific">{{ t('exam.calc_scientific', 'علمية') }}</option>
                        </select></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.show_results', 'إظهار النتيجة للطالب') }}</span>
                        <select v-model="form.show_results" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option value="after_submit">{{ t('exam.after_submit', 'بعد التسليم مباشرة') }}</option>
                            <option value="after_close">{{ t('exam.after_close', 'بعد إغلاق الامتحان') }}</option>
                            <option value="never">{{ t('exam.never', 'لا تُظهر') }}</option>
                        </select></label>
                </div>
                <div>
                    <p class="mb-1 text-xs font-medium text-gray-500">{{ t('exam.given_to', 'يُعطى إلى') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="g in groups" :key="g.id" type="button" @click="toggleGroup(g.id)"
                            class="rounded-full border px-3 py-1 text-sm" :class="form.group_ids?.includes(g.id) ? 'border-[var(--accent-600)] bg-[var(--accent-50)] text-[var(--accent-700)] dark:bg-gray-700 dark:text-white' : 'border-gray-300 dark:border-gray-600'">
                            {{ g.name }} <span class="text-xs text-gray-400">({{ g.members }})</span>
                        </button>
                        <Link :href="route('exams.manage.groups')" class="rounded-full border border-dashed border-gray-300 px-3 py-1 text-sm text-gray-500 dark:border-gray-600">+ {{ t('exam.groups', 'الصفوف والمجموعات') }}</Link>
                    </div>
                    <p class="mt-1 text-xs" :class="form.group_ids?.length ? 'text-gray-500' : 'text-amber-600'">{{ form.group_ids?.length ? t('exam.given_to_groups', 'يراه طلاب المجموعات المختارة فقط.') : t('exam.given_to_all', 'لم تُختر مجموعة: يراه كل الطلاب.') }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.access_code', 'رمز دخول القاعة (يعلنه المراقب عند البدء)') }}</span>
                        <span class="flex gap-2"><input v-model="form.access_code" dir="ltr" class="w-full rounded-lg border-gray-300 font-mono text-sm uppercase tracking-widest dark:border-gray-600 dark:bg-gray-700" />
                            <button type="button" @click="newAccessCode" class="shrink-0 rounded-lg border border-gray-300 px-3 text-xs dark:border-gray-600">{{ t('exam.generate', 'توليد') }}</button></span></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.allowed_ips', 'أجهزة القاعة فقط (عناوين أو نطاقات، مثل 192.168.1.0/24)') }}</span>
                        <input v-model="form.allowed_ips" dir="ltr" class="w-full rounded-lg border-gray-300 font-mono text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <span v-if="form.errors.allowed_ips" class="text-xs text-red-600">{{ form.errors.allowed_ips }}</span></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                        <span>{{ t('exam.resume_code', 'رمز المتابعة عند الخروج') }}<span class="block text-xs text-gray-500">{{ t('exam.resume_code_hint', 'من يغادر الصفحة أو يفتحها من جديد يُقفل حتى تعطيه رمزه') }}</span></span>
                        <ToggleSwitch v-model="form.resume_code_required" /></label>
                    <label class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                        <span>{{ t('exam.bind_computers', 'تثبيت كل طالب على حاسبته') }}<span class="block text-xs text-gray-500">{{ t('exam.bind_computers_hint', 'لا يفتح امتحانه إلا من عنوان حاسبته') }}</span></span>
                        <ToggleSwitch v-model="form.bind_computers" /></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">{{ t('exam.shuffle_questions', 'خلط ترتيب الأسئلة') }} <ToggleSwitch v-model="form.shuffle_questions" /></label>
                    <label class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">{{ t('exam.shuffle_choices', 'خلط ترتيب الخيارات') }} <ToggleSwitch v-model="form.shuffle_choices" /></label>
                    <label class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">{{ t('exam.show_answers', 'إظهار الإجابات الصحيحة في المراجعة') }} <ToggleSwitch v-model="form.show_answers" /></label>
                    <label class="flex items-center justify-between gap-2 rounded-lg border p-3 text-sm" :class="form.is_published ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700'">
                        {{ t('exam.publish', 'منشور (يراه الطلاب)') }} <ToggleSwitch v-model="form.is_published" /></label>
                    <label class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                        <span>{{ t('exam.practice', 'امتحان تدريبي') }}<span class="block text-xs text-gray-500">{{ t('exam.practice_hint', 'زر «تحقّق» لكل سؤال يُظهر الإجابة فوراً، وتُثبَّت بعده.') }}</span></span>
                        <ToggleSwitch v-model="form.is_practice" /></label>
                    <label class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                        <span>{{ t('exam.seb_required', 'يُؤدّى في Safe Exam Browser فقط') }}<span class="block text-xs text-gray-500">{{ t('exam.seb_hint', 'متصفح مقفل للامتحانات الحضورية: لا تبويبات ولا تطبيقات أخرى.') }}</span></span>
                        <ToggleSwitch v-model="form.seb_required" /></label>
                    <label class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                        <span>{{ t('exam.double_marking', 'تصحيح مزدوج للمقالي والملفات') }}<span class="block text-xs text-gray-500">{{ t('exam.double_marking_hint', 'مصحّحان مستقلان؛ تُعتمد المتوسط إذا تقاربت درجتاهما. أضف المصحّحين من صفحة النتائج.') }}</span></span>
                        <ToggleSwitch v-model="form.double_marking" /></label>
                    <label v-if="form.double_marking" class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.marking_tolerance', 'أقصى فرق مقبول بين المصحّحَين (% من درجة السؤال)') }}</span>
                        <input v-model="form.marking_tolerance" type="number" min="0" max="100" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label v-if="form.seb_required" class="text-sm sm:col-span-2"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.seb_keys', 'مفاتيح SEB (Config Key أو Browser Exam Key، سطر لكل مفتاح؛ فارغ = يكفي أن يكون المتصفح SEB)') }}</span>
                        <textarea v-model="form.seb_keys" rows="2" dir="ltr" class="w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-700" /></label>
                </div>
            </section>

            <!-- Questions -->
            <div class="flex flex-wrap items-center justify-between gap-y-1">
                <h3 class="font-semibold">{{ t('exam.questions', 'الأسئلة') }} ({{ form.questions.length }})</h3>
                <span class="text-sm text-gray-500">{{ t('exam.total_points', 'مجموع الدرجات') }}: {{ totalPoints }}</span>
                <p class="w-full text-xs text-gray-400">{{ t('exam.math_hint', 'المعادلات في أي نص: اكتبها بين $…$ مثل $x^2 + \\frac{1}{2}$، أو استعمل «نص منسّق» لزر المعادلة والجداول والصور والصوت والفيديو.') }}</p>
            </div>

            <section v-for="(q, i) in form.questions" :key="q.id ?? 'new' + i" class="space-y-3 rounded-xl border bg-white p-5 dark:bg-gray-800"
                :class="errorsFor(i).length ? 'border-red-300 dark:border-red-700' : 'border-gray-200 dark:border-gray-700'">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-semibold dark:bg-gray-700">{{ i + 1 }}</span>
                    <select v-model="q.type" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                        <option v-for="type in types" :key="type" :value="type">{{ typeLabel(type) }}</option>
                    </select>
                    <label v-if="q.type !== 'description'" class="flex items-center gap-1 text-sm">{{ t('exam.points', 'درجة') }}
                        <input v-model="q.points" type="number" min="0" step="0.25" class="w-20 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <button v-if="!['cloze', 'random'].includes(q.type)" type="button" @click="toggleFormat(q)"
                        class="rounded-lg border px-2 py-0.5 text-xs" :class="q.format === 'html' ? 'border-[var(--accent-600)] text-[var(--accent-700)] dark:text-white' : 'border-gray-300 text-gray-500 dark:border-gray-600'"
                        :title="t('exam.rich_hint', 'تنسيق، جداول، صور، صوت، فيديو، معادلات')">{{ q.format === 'html' ? t('exam.rich_on', 'نص منسّق ✓') : t('exam.rich_off', 'نص منسّق') }}</button>
                    <span class="flex-1" />
                    <button @click="moveQuestion(i, -1)" :disabled="i === 0" class="rounded p-1.5 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700"><ArrowUpIcon class="h-4 w-4" /></button>
                    <button @click="moveQuestion(i, 1)" :disabled="i === form.questions.length - 1" class="rounded p-1.5 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700"><ArrowDownIcon class="h-4 w-4" /></button>
                    <button @click="form.questions.splice(i, 1)" class="rounded p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30"><TrashIcon class="h-4 w-4" /></button>
                </div>

                <div v-if="q.type === 'random'" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.bank_category', 'فئة البنك') }}</span>
                        <select v-model="q.settings.category_id" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} ({{ c.questions_count }})</option>
                        </select></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.random_count', 'عدد الأسئلة لكل طالب') }}</span>
                        <input v-model="q.settings.count" type="number" min="1" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.difficulty', 'الصعوبة') }}</span>
                        <select v-model="q.settings.difficulty" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option :value="undefined">{{ t('exam.any', 'أي مستوى') }}</option><option :value="1">{{ t('exam.easy', 'سهل') }}</option><option :value="2">{{ t('exam.medium', 'متوسط') }}</option><option :value="3">{{ t('exam.hard', 'صعب') }}</option>
                        </select></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.tag_filter', 'وسم (اختياري)') }}</span>
                        <input v-model="q.settings.tag" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <p class="text-xs text-gray-500 sm:col-span-2">{{ t('exam.random_hint', 'كل طالب يأخذ أسئلة مختلفة من الفئة، والدرجة أعلاه لكل سؤال منها.') }}
                        <Link v-if="!categories.length" :href="route('exams.manage.bank')" class="text-[var(--accent-600)] underline">{{ t('exam.bank_create_first', 'أنشئ فئة في بنك الأسئلة أولاً') }}</Link></p>
                </div>
                <LangInput v-else v-model="q.body" :languages="form.languages" multiline :rows="2" :rich="q.format === 'html'" :placeholder="q.type === 'description' ? t('exam.description_ph', 'نص أو قطعة تعود إليها الأسئلة التالية') : t('exam.question_ph', 'نص السؤال')" />

                <!-- Choices: one / several / true-false -->
                <div v-if="['single', 'multiple', 'true_false'].includes(q.type)" class="space-y-2">
                    <div v-for="(c, j) in q.choices" :key="c.id ?? 'c' + j" class="flex items-start gap-2">
                        <input v-if="q.type === 'multiple'" type="checkbox" v-model="c.is_correct" class="mt-2.5 rounded text-emerald-600" :title="t('exam.correct', 'صحيحة')" />
                        <input v-else type="radio" :name="'correct' + i" :checked="c.is_correct" @change="setOnlyCorrect(q, c)" class="mt-2.5 text-emerald-600" :title="t('exam.correct', 'صحيحة')" />
                        <div class="flex-1"><LangInput v-model="c.body" :languages="form.languages" :placeholder="t('exam.choice', 'خيار') + ' ' + (j + 1)" /></div>
                        <button v-if="q.type !== 'true_false'" @click="q.choices.splice(j, 1)" class="mt-1.5 rounded p-1 text-gray-400 hover:text-red-500"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                    <button v-if="q.type !== 'true_false'" @click="q.choices.push(choice())" class="flex items-center gap-1 text-sm text-[var(--accent-600)]"><PlusIcon class="h-4 w-4" /> {{ t('exam.add_choice', 'خيار') }}</button>
                    <p class="text-xs text-gray-500">{{ q.type === 'multiple' ? t('exam.multiple_hint', 'علّم كل الصحيحة. الدرجة جزئية: كل خيار خاطئ يُلغي صحيحاً.') : t('exam.single_hint', 'علّم الإجابة الصحيحة.') }}</p>
                </div>

                <div v-else-if="q.type === 'short'" class="space-y-2">
                    <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.accepted_answers', 'الإجابات المقبولة (سطر لكل إجابة)') }}</span>
                        <textarea v-model="q.answersText" rows="3" dir="auto" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="q.settings.case_sensitive" class="rounded" /> {{ t('exam.case_sensitive', 'مطابقة حرفية (بدون تجاهل الهمزات والتشكيل وحالة الأحرف)') }}</label>
                </div>

                <div v-else-if="q.type === 'numeric'" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.numeric_answer', 'الإجابة') }}</span><input v-model="q.settings.answer" dir="ltr" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.tolerance', 'هامش الخطأ المسموح ±') }}</span><input v-model="q.settings.tolerance" dir="ltr" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                </div>

                <div v-else-if="q.type === 'matching' || q.type === 'classify'" class="space-y-2">
                    <p v-if="q.type === 'classify'" class="text-xs text-gray-500">{{ t('exam.classify_edit_hint', 'لكل عنصر اكتب فئته؛ اكتب اسم الفئة نفسه للعناصر التي تنتمي إليها. فئتان على الأقل، والدرجة جزئية.') }}</p>
                    <div v-for="(c, j) in q.choices" :key="c.id ?? 'm' + j" class="grid items-start gap-2 sm:grid-cols-[1fr_1fr_auto]">
                        <LangInput v-model="c.body" :languages="form.languages" :placeholder="t('exam.item', 'العنصر')" />
                        <LangInput v-model="c.match" :languages="form.languages" :placeholder="q.type === 'classify' ? t('exam.category', 'الفئة') : t('exam.match', 'ما يقابله')" />
                        <button @click="q.choices.splice(j, 1)" class="mt-1.5 rounded p-1 text-gray-400 hover:text-red-500"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                    <button @click="q.choices.push(choice())" class="flex items-center gap-1 text-sm text-[var(--accent-600)]"><PlusIcon class="h-4 w-4" /> {{ q.type === 'classify' ? t('exam.add_item', 'عنصر') : t('exam.add_pair', 'زوج') }}</button>
                </div>

                <div v-else-if="q.type === 'calculated'" class="space-y-2">
                    <p class="text-xs text-gray-500">{{ t('exam.calc_hint', 'اكتب المتغيرات في نص السؤال بين { } مثل {v}. يأخذ كل طالب قيماً مختلفة من المدى، والإجابة ناتج المعادلة عليها.') }}</p>
                    <div v-for="(v, j) in q.settings.vars" :key="j" class="flex flex-wrap items-end gap-2" dir="ltr">
                        <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.calc_var', 'المتغير') }}</span><input v-model="v.name" class="w-20 rounded-lg border-gray-300 font-mono text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                        <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.calc_min', 'من') }}</span><input v-model="v.min" class="w-24 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                        <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.calc_max', 'إلى') }}</span><input v-model="v.max" class="w-24 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                        <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.calc_decimals', 'منازل عشرية') }}</span><input v-model="v.decimals" type="number" min="0" max="6" class="w-20 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                        <button @click="q.settings.vars.splice(j, 1)" class="mb-1.5 rounded p-1 text-gray-400 hover:text-red-500"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                    <button @click="q.settings.vars.push({ name: '', min: 1, max: 10, decimals: 0 })" class="flex items-center gap-1 text-sm text-[var(--accent-600)]"><PlusIcon class="h-4 w-4" /> {{ t('exam.calc_add_var', 'متغير') }}</button>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-sm sm:col-span-3"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.calc_formula', 'المعادلة (مثل v * t أو sqrt(a^2 + b^2))') }}</span><input v-model="q.settings.formula" dir="ltr" class="w-full rounded-lg border-gray-300 font-mono text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                        <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.tolerance_calc', 'هامش الخطأ (0.1 أو 2%)') }}</span><input v-model="q.settings.tolerance" dir="ltr" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                        <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.calc_round', 'تقريب الإجابة (منازل، اختياري)') }}</span><input v-model="q.settings.decimals" type="number" min="0" max="6" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    </div>
                </div>

                <div v-else-if="q.type === 'upload'" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.max_mb', 'أقصى حجم (ميغابايت)') }}</span>
                        <input v-model="q.settings.max_mb" type="number" min="1" max="50" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.file_types', 'الأنواع المقبولة (فارغ = أي نوع)') }}</span>
                        <input v-model="q.settings.types" dir="ltr" placeholder="pdf,jpg,png,docx" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <p class="text-xs text-gray-500 sm:col-span-2">{{ t('exam.upload_hint', 'يرفع الطالب ملفاً واحداً، ويُصحَّح يدوياً من صفحة النتائج.') }}</p>
                </div>

                <div v-else-if="q.type === 'drag_text'" class="space-y-2">
                    <p class="rounded-lg bg-gray-50 p-2 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ t('exam.drag_text_hint', 'اكتب الكلمات الصحيحة في النص بين [[ و ]]، مثل: عاصمة العراق [[بغداد]]. يسحبها الطالب من قائمة فيها أيضاً الكلمات الخاطئة أدناه.') }}</p>
                    <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.distractors', 'كلمات خاطئة إضافية (سطر لكل كلمة)') }}</span>
                        <textarea v-model="q.distractorsText" rows="3" dir="auto" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                </div>

                <div v-else-if="q.type === 'drag_image'" class="space-y-2">
                    <label class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">
                        <input type="file" accept="image/*" class="sr-only" @change="uploadImage(q, i, $event)" />
                        {{ imageUploading === i ? t('exam.uploading', 'يرفع…') : q.settings.image ? t('exam.replace_image', 'تغيير الصورة') : t('exam.upload_image', 'رفع الصورة') }}
                    </label>
                    <div v-for="(c, j) in q.choices" :key="c.id ?? 'l' + j" class="flex items-start gap-2">
                        <span class="mt-2 w-6 text-center text-xs text-gray-400">{{ j + 1 }}</span>
                        <div class="flex-1"><LangInput v-model="c.body" :languages="form.languages" :placeholder="t('exam.label', 'العلامة')" /></div>
                        <button type="button" @click="labelSlot[i] = j" class="mt-1 rounded-lg border px-2 py-1 text-xs" :class="labelSlot[i] === j ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : q.settings.zones?.[j] ? 'border-emerald-400 text-emerald-700' : 'border-amber-400 text-amber-700'">
                            {{ q.settings.zones?.[j] ? t('exam.place_set', 'مكانها ✓') : t('exam.place_set_now', 'حدّد مكانها') }}</button>
                        <button @click="q.choices.splice(j, 1); q.settings.zones?.splice(j, 1)" class="mt-1.5 rounded p-1 text-gray-400 hover:text-red-500"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                    <button @click="q.choices.push(choice())" class="flex items-center gap-1 text-sm text-[var(--accent-600)]"><PlusIcon class="h-4 w-4" /> {{ t('exam.add_label', 'علامة') }}</button>
                    <template v-if="q.settings.image_url">
                        <p class="text-xs text-gray-500">{{ t('exam.drag_image_hint', 'اختر «حدّد مكانها» لعلامة ثم اسحب على الصورة مكانها الصحيح.') }}</p>
                        <div class="relative inline-block max-w-full cursor-crosshair touch-none select-none" @pointerdown.prevent="labelSlot[i] !== undefined && startZone(q, $event, labelSlot[i])" @pointermove="moveZone" @pointerup="endZone">
                            <img :src="q.settings.image_url" alt="" class="block max-h-[60vh] max-w-full rounded-lg" draggable="false" />
                            <span v-for="(z, k) in q.settings.zones" :key="k" v-show="z" class="absolute border-2 bg-emerald-400/20 text-[10px]" :class="labelSlot[i] === k ? 'border-[var(--accent-600)]' : 'border-emerald-500'"
                                :style="z ? { left: z.x + '%', top: z.y + '%', width: z.w + '%', height: z.h + '%' } : {}">{{ k + 1 }}</span>
                            <span v-if="drawing && drawing.q === q" class="pointer-events-none absolute border-2 border-dashed border-[var(--accent-600)]"
                                :style="{ left: drawing.zone.x + '%', top: drawing.zone.y + '%', width: drawing.zone.w + '%', height: drawing.zone.h + '%' }" />
                        </div>
                    </template>
                </div>

                <div v-else-if="q.type === 'audio'" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.max_seconds', 'أطول تسجيل (ثانية)') }}</span>
                        <input v-model="q.settings.max_seconds" type="number" min="10" max="600" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <p class="text-xs text-gray-500 sm:col-span-2">{{ t('exam.audio_hint', 'يسجّل الطالب إجابته بالمايكروفون، وتستمع إليها وتصحّحها من صفحة النتائج.') }}</p>
                </div>
                <p v-else-if="q.type === 'drawing'" class="text-xs text-gray-500">{{ t('exam.drawing_hint', 'يرسم الطالب أو يكتب بخط يده على لوحة (فأرة أو قلم أو إصبع)، وتصحّحها يدوياً من صفحة النتائج.') }}</p>

                <div v-else-if="q.type === 'hotspot'" class="space-y-2">
                    <label class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">
                        <input type="file" accept="image/*" class="sr-only" @change="uploadImage(q, i, $event)" />
                        {{ imageUploading === i ? t('exam.uploading', 'يرفع…') : q.settings.image ? t('exam.replace_image', 'تغيير الصورة') : t('exam.upload_image', 'رفع الصورة') }}
                    </label>
                    <template v-if="q.settings.image_url">
                        <p class="text-xs text-gray-500">{{ t('exam.hotspot_edit_hint', 'اسحب على الصورة لتحديد منطقة صحيحة (أو اضغط لمربّع صغير). أي نقرة للطالب داخل منطقة تُحسب صحيحة.') }}</p>
                        <div class="relative inline-block max-w-full cursor-crosshair touch-none select-none" @pointerdown.prevent="startZone(q, $event)" @pointermove="moveZone" @pointerup="endZone">
                            <img :src="q.settings.image_url" alt="" class="block max-h-[60vh] max-w-full rounded-lg" draggable="false" />
                            <span v-for="(z, k) in q.settings.zones" :key="k" class="absolute border-2 border-emerald-500 bg-emerald-400/25"
                                :style="{ left: z.x + '%', top: z.y + '%', width: z.w + '%', height: z.h + '%' }">
                                <button type="button" @pointerdown.stop @click.stop="q.settings.zones.splice(k, 1)" class="absolute -top-2.5 rounded-full bg-white p-0.5 text-red-500 shadow ltr:-right-2.5 rtl:-left-2.5"><TrashIcon class="h-3 w-3" /></button>
                            </span>
                            <span v-if="drawing && drawing.q === q" class="pointer-events-none absolute border-2 border-dashed border-[var(--accent-600)]"
                                :style="{ left: drawing.zone.x + '%', top: drawing.zone.y + '%', width: drawing.zone.w + '%', height: drawing.zone.h + '%' }" />
                        </div>
                    </template>
                </div>

                <div v-else-if="q.type === 'ordering'" class="space-y-2">
                    <p class="text-xs text-gray-500">{{ t('exam.ordering_hint', 'اكتب العناصر بترتيبها الصحيح؛ تظهر للطالب مخلوطة.') }}</p>
                    <div v-for="(c, j) in q.choices" :key="c.id ?? 'o' + j" class="flex items-start gap-2">
                        <span class="mt-2 w-6 text-center text-xs text-gray-400">{{ j + 1 }}</span>
                        <div class="flex-1"><LangInput v-model="c.body" :languages="form.languages" /></div>
                        <button @click="moveChoice(q, j, -1)" :disabled="j === 0" class="mt-1 rounded p-1 disabled:opacity-30"><ArrowUpIcon class="h-4 w-4" /></button>
                        <button @click="moveChoice(q, j, 1)" :disabled="j === q.choices.length - 1" class="mt-1 rounded p-1 disabled:opacity-30"><ArrowDownIcon class="h-4 w-4" /></button>
                        <button @click="q.choices.splice(j, 1)" class="mt-1 rounded p-1 text-gray-400 hover:text-red-500"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                    <button @click="q.choices.push(choice())" class="flex items-center gap-1 text-sm text-[var(--accent-600)]"><PlusIcon class="h-4 w-4" /> {{ t('exam.add_item', 'عنصر') }}</button>
                </div>

                <div v-else-if="q.type === 'cloze'" class="rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                    <p>{{ t('exam.cloze_hint_typed', 'فراغ يكتبه الطالب: [[بغداد|بغدادُ]] — تُقبل أي إجابة من المكتوبة.') }}</p>
                    <p>{{ t('exam.cloze_hint_select', 'قائمة يختار منها: [[*البصرة|الموصل|أربيل]] — النجمة على الصحيحة، وتظهر الخيارات مخلوطة.') }}</p>
                    <p>{{ t('exam.cloze_hint_langs', 'في كل لغة نفس عدد الفراغات وبنفس الترتيب. الدرجة جزئية حسب عدد الفراغات الصحيحة.') }}</p>
                </div>

                <div v-else-if="q.type === 'essay'" class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.min_words', 'أقل عدد كلمات') }}</span><input v-model="q.settings.min_words" type="number" min="0" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.max_words', 'أكبر عدد كلمات') }}</span><input v-model="q.settings.max_words" type="number" min="0" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                    <p class="text-xs text-gray-500 sm:col-span-2">{{ t('exam.essay_hint', 'يُصحَّح يدوياً من صفحة النتائج.') }}</p>
                </div>

                <select v-if="q.id && q.type !== 'random' && categories.length" @change="toBank(q, $event.target.value); $event.target.value = ''" class="rounded-lg border-gray-300 py-1 text-xs text-gray-500 dark:border-gray-600 dark:bg-gray-700">
                    <option value="">{{ t('exam.to_bank', 'نسخ إلى البنك…') }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>

                <div v-if="q.type !== 'description' && q.type !== 'random'" class="flex flex-wrap items-center gap-2 text-xs">
                    <select v-model="q.difficulty" class="rounded-lg border-gray-300 py-1 text-xs dark:border-gray-600 dark:bg-gray-700">
                        <option :value="null">{{ t('exam.difficulty', 'الصعوبة') }}: —</option><option :value="1">{{ t('exam.easy', 'سهل') }}</option><option :value="2">{{ t('exam.medium', 'متوسط') }}</option><option :value="3">{{ t('exam.hard', 'صعب') }}</option>
                    </select>
                    <input v-model="q.tagsText" :placeholder="t('exam.tags_ph', 'وسوم: الفصل 2، الأحماض')" class="min-w-[12rem] flex-1 rounded-lg border-gray-300 py-1 text-xs dark:border-gray-600 dark:bg-gray-700" />
                </div>
                <details v-if="q.type !== 'description' && q.type !== 'random'" class="text-sm">
                    <summary class="cursor-pointer text-xs text-gray-500">{{ t('exam.feedback', 'تغذية راجعة تظهر في المراجعة') }}</summary>
                    <div class="mt-2"><LangInput v-model="q.feedback" :languages="form.languages" multiline :rows="2" :rich="q.format === 'html'" /></div>
                </details>

                <ul v-if="errorsFor(i).length" class="space-y-0.5 text-xs text-red-600">
                    <li v-for="err in errorsFor(i)" :key="err">{{ err }}</li>
                </ul>
            </section>

            <!-- Import -->
            <section class="space-y-2 rounded-xl border border-dashed border-gray-300 p-4 dark:border-gray-600">
                <p class="text-sm font-medium">{{ t('exam.import_title', 'استيراد أسئلة') }}</p>
                <p class="text-xs text-gray-500">
                    {{ t('exam.import_hint', 'ملف Excel أو CSV بأعمدة: النوع، السؤال، الخيار 1…، الإجابة، الدرجة — أو ملف Word (.docx) أو نص بصيغة Aiken أو GIFT، أو Moodle XML (.xml). تُضاف الأسئلة في آخر الامتحان. احفظ تعديلاتك أولاً.') }}
                    <a :href="route('exams.manage.import.template')" class="text-[var(--accent-600)] underline">{{ t('exam.import_template', 'تنزيل النموذج') }}</a>
                </p>
                <form @submit.prevent="importQuestions" class="flex flex-wrap items-center gap-2">
                    <input ref="fileInput" type="file" accept=".xlsx,.csv,.txt,.gift,.xml,.docx" @input="importForm.file = $event.target.files[0]" class="text-sm" />
                    <button :disabled="!importForm.file || importForm.processing || form.isDirty" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm disabled:opacity-40 dark:border-gray-600">{{ t('exam.import', 'استيراد') }}</button>
                    <span v-if="form.isDirty" class="text-xs text-amber-600">{{ t('exam.save_first', 'احفظ التعديلات قبل الاستيراد.') }}</span>
                </form>
                <p v-if="importForm.errors.file" class="text-xs text-red-600">{{ importForm.errors.file }}</p>
            </section>

            <!-- From the bank -->
            <section v-if="categories.length" class="flex flex-wrap items-center gap-2 rounded-xl border border-dashed border-gray-300 p-4 text-sm dark:border-gray-600">
                <span class="font-medium">{{ t('exam.from_bank', 'من بنك الأسئلة') }}</span>
                <button @click="bankOpen = true; loadBank(categories[0].id)" :disabled="form.isDirty" class="rounded-lg border border-gray-300 px-3 py-1.5 disabled:opacity-40 dark:border-gray-600">{{ t('exam.pick_questions', 'اختيار أسئلة') }}</button>
                <span v-if="form.isDirty" class="text-xs text-amber-600">{{ t('exam.save_first', 'احفظ التعديلات أولاً.') }}</span>
            </section>

            <!-- Add a question -->
            <section class="rounded-xl border border-dashed border-gray-300 p-4 dark:border-gray-600">
                <p class="mb-2 text-sm font-medium">{{ t('exam.add_question', 'إضافة سؤال') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="type in types" :key="type" @click="addQuestion(type)" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:border-[var(--accent-500)] dark:border-gray-600">
                        <PlusIcon class="h-4 w-4" /> {{ typeLabel(type) }}
                    </button>
                </div>
            </section>

            <div class="flex justify-end">
                <button @click="save" :disabled="form.processing" class="rounded-lg bg-[var(--accent-600)] px-6 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50">{{ t('action.save', 'حفظ') }}</button>
            </div>
        </div>

        <Modal :show="bankOpen" @close="bankOpen = false" max-width="2xl">
            <div class="space-y-3 p-6">
                <h3 class="text-lg font-semibold">{{ t('exam.from_bank', 'من بنك الأسئلة') }}</h3>
                <select :value="bankCategory" @change="loadBank(Number($event.target.value))" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} ({{ c.questions_count }})</option>
                </select>
                <div class="max-h-96 space-y-1 overflow-y-auto">
                    <label v-for="bq in bankQuestions" :key="bq.id" class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 p-2 text-sm dark:border-gray-700">
                        <input type="checkbox" :value="bq.id" v-model="bankPicked" class="mt-1 rounded" />
                        <span class="min-w-0 flex-1"><span class="line-clamp-2" dir="auto">{{ bq.body }}</span>
                            <span class="block text-xs text-gray-500">{{ typeLabel(bq.type) }} · {{ bq.points }}</span></span>
                    </label>
                    <p v-if="!bankQuestions.length" class="p-4 text-center text-sm text-gray-400">{{ t('exam.bank_no_questions', 'لا أسئلة في هذه الفئة بعد.') }}</p>
                </div>
                <div class="flex justify-end gap-2">
                    <button @click="bankOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button @click="addFromBank" :disabled="!bankPicked.length" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('exam.add_n', 'إضافة :n', { n: bankPicked.length }) }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
