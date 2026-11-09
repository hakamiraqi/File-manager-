<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import ToggleSwitch from '@/Components/ToggleSwitch.vue';
import { useTrans } from '@/Composables/useTrans';
import { PlusIcon, TrashIcon, PencilSquareIcon, UsersIcon, ArchiveBoxIcon, ChartBarIcon, SparklesIcon, CheckIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ categories: Array, current: Number, questions: Array, ai: Boolean });
// The question's record in real exams.
const FLAGS = { exposed: 'مكشوف: رآه طلاب كثيرون', too_easy: 'سهل جداً', too_hard: 'صعب جداً', weak: 'تمييز ضعيف', check_key: 'راجع مفتاح الإجابة' };
const { t } = useTrans();

const TYPE_LABELS = {
    single: 'اختيار (إجابة واحدة)', multiple: 'اختيار (عدة إجابات)', true_false: 'صح / خطأ', short: 'إجابة قصيرة', numeric: 'رقمي',
    matching: 'مطابقة', ordering: 'ترتيب', cloze: 'املأ الفراغ', essay: 'مقالي', classify: 'تصنيف', upload: 'رفع ملف', hotspot: 'نقطة على صورة', calculated: 'محسوب', drag_text: 'سحب كلمات', drag_image: 'سحب إلى صورة', audio: 'إجابة صوتية', drawing: 'رسم', description: 'نص توضيحي',
};

// Category form (new or edit)
const editing = ref(null);
const catForm = useForm({ name: '', subject: '', is_shared: false });
function openCategory(c = null) {
    editing.value = c ?? {};
    catForm.name = c?.name ?? '';
    catForm.subject = c?.subject ?? '';
    catForm.is_shared = !!c?.is_shared;
    catForm.clearErrors();
}
function saveCategory() {
    const done = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value?.id ? catForm.put(route('exams.manage.bank.categories.update', editing.value.id), done) : catForm.post(route('exams.manage.bank.categories.store'), done);
}
function removeCategory(c) {
    if (!c) return;
    router.delete(route('exams.manage.bank.categories.destroy', c.id), { preserveScroll: true });
}
const removeQuestion = (q) => router.delete(route('exams.manage.bank.questions.destroy', q.id), { preserveScroll: true });

// Import into the open category
const importForm = useForm({ file: null, lang: 'ar' });

// ---- Questions drafted by AI from a lecture (hosted site); the teacher keeps the right ones ----
const gen = ref({ open: false, file: null, text: '', count: 10, types: ['single', 'true_false'], lang: 'ar', busy: false, error: '', drafts: [] });
async function generate() {
    const g = gen.value;
    g.busy = true; g.error = ''; g.drafts = [];
    try {
        const body = new FormData();
        if (g.file) body.append('file', g.file);
        body.append('text', g.text);
        body.append('count', g.count);
        body.append('lang', g.lang);
        g.types.forEach((x) => body.append('types[]', x));
        g.drafts = (await window.axios.post(route('exams.manage.bank.categories.generate', props.current), body)).data.questions.map((q) => ({ ...q, keep: true }));
    } catch (e) {
        g.error = e.response?.data?.message || Object.values(e.response?.data?.errors ?? {})[0]?.[0] || t('exam.ai_failed', 'تعذّر التوليد.');
    } finally {
        g.busy = false;
    }
}
function keepDrafts() {
    const kept = gen.value.drafts.filter((q) => q.keep).map(({ keep, ...q }) => q);
    router.post(route('exams.manage.bank.categories.generated', props.current), { lang: gen.value.lang, questions: kept }, { preserveScroll: true, onSuccess: () => { gen.value.drafts = []; gen.value.open = false; } });
}
const GEN_TYPES = { single: 'اختيار من متعدد', true_false: 'صح / خطأ', short: 'جواب قصير' };
const fileInput = ref(null);
function importFile() {
    importForm.post(route('exams.manage.bank.categories.import', props.current), {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { importForm.reset('file'); if (fileInput.value) fileInput.value.value = ''; },
    });
}
const currentCategory = () => props.categories.find((c) => c.id === props.current);
</script>

<template>
    <Head :title="t('exam.bank', 'بنك الأسئلة')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-lg font-semibold"><ArchiveBoxIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.bank', 'بنك الأسئلة') }}</h2>
                <button @click="openCategory()" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm text-white"><PlusIcon class="h-4 w-4" /> {{ t('exam.new_category', 'فئة جديدة') }}</button>
            </div>
        </template>

        <p v-if="$page.props.errors.category || $page.props.errors.question"
            class="mx-4 mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 sm:mx-6 dark:bg-red-900/30 dark:text-red-300">
            {{ $page.props.errors.category || $page.props.errors.question }}
        </p>
        <div class="grid gap-4 p-4 sm:p-6 lg:grid-cols-4">
            <aside class="space-y-1 lg:col-span-1">
                <p v-if="!categories.length" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-600">
                    {{ t('exam.bank_empty', 'لا توجد فئات بعد. أنشئ فئة (مثلاً «كيمياء — الفصل الأول») ثم أضف إليها أسئلة.') }}
                </p>
                <Link v-for="c in categories" :key="c.id" :href="route('exams.manage.bank', { category: c.id })" preserve-scroll
                    class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                    :class="c.id === current ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800'">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ c.name }}</span>
                        <span class="block truncate text-xs text-gray-500">{{ [c.subject, c.owner].filter(Boolean).join(' · ') }}</span>
                    </span>
                    <UsersIcon v-if="c.is_shared" class="h-4 w-4 text-gray-400" :title="t('exam.shared', 'مشتركة مع الأساتذة')" />
                    <span class="text-xs text-gray-400">{{ c.questions }}</span>
                </Link>
            </aside>

            <main v-if="current" class="space-y-3 lg:col-span-3">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="flex-1 font-semibold">{{ currentCategory()?.name }}</h3>
                    <template v-if="currentCategory()?.editable">
                        <button @click="openCategory(currentCategory())" class="rounded p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700"><PencilSquareIcon class="h-4 w-4" /></button>
                        <button @click="removeCategory(currentCategory())" class="rounded p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30" :title="t('exam.delete_category', 'حذف الفئة وأسئلتها')"><TrashIcon class="h-4 w-4" /></button>
                    </template>
                </div>

                <section v-if="ai && currentCategory()?.editable" class="space-y-2 rounded-xl border border-violet-200 bg-violet-50/50 p-3 text-sm dark:border-violet-800 dark:bg-violet-900/10">
                    <button type="button" @click="gen.open = !gen.open" class="flex items-center gap-2 font-medium text-violet-800 dark:text-violet-200"><SparklesIcon class="h-5 w-5" /> {{ t('exam.ai_generate', 'توليد أسئلة من محاضرة بالذكاء الاصطناعي') }}</button>
                    <div v-if="gen.open" class="space-y-2">
                        <p class="text-xs text-gray-500">{{ t('exam.ai_generate_hint', 'أرفق المحاضرة (PDF أو Word أو نص) أو الصقها. تظهر الأسئلة هنا لتراجعها، ولا يُضاف إلى البنك إلا ما تُبقيه.') }}</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="file" accept=".pdf,.docx,.txt" @input="gen.file = $event.target.files[0]" class="text-sm" />
                            <label class="flex items-center gap-1">{{ t('exam.ai_count', 'العدد') }} <input v-model.number="gen.count" type="number" min="1" max="40" class="w-16 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" /></label>
                            <label v-for="(l, k) in GEN_TYPES" :key="k" class="flex items-center gap-1"><input type="checkbox" :value="k" v-model="gen.types" class="rounded" /> {{ t('exam.type_' + k, l) }}</label>
                            <select v-model="gen.lang" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700"><option value="ar">العربية</option><option value="en">English</option></select>
                        </div>
                        <textarea v-model="gen.text" rows="3" :placeholder="t('exam.ai_paste', 'أو الصق نص المحاضرة هنا')" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <button type="button" @click="generate" :disabled="gen.busy || (!gen.file && !gen.text.trim()) || !gen.types.length" class="rounded-lg bg-violet-600 px-4 py-1.5 text-white disabled:opacity-40">{{ gen.busy ? t('exam.ai_working', 'يكتب الأسئلة…') : t('exam.ai_go', 'توليد') }}</button>
                        <p v-if="gen.error" class="text-xs text-red-600">{{ gen.error }}</p>
                        <div v-for="(q, i) in gen.drafts" :key="i" class="rounded-lg border bg-white p-2 dark:bg-gray-800" :class="q.keep ? 'border-violet-300' : 'border-gray-200 opacity-50 dark:border-gray-700'">
                            <label class="flex items-start gap-2"><input type="checkbox" v-model="q.keep" class="mt-1 rounded" />
                                <span class="min-w-0 flex-1"><textarea v-model="q.body" rows="2" dir="auto" class="w-full rounded border-gray-200 text-sm dark:border-gray-600 dark:bg-gray-700" />
                                    <span class="text-xs text-gray-500">{{ t('exam.type_' + q.type, GEN_TYPES[q.type]) }} · {{ ['', t('exam.easy', 'سهل'), t('exam.medium', 'متوسط'), t('exam.hard', 'صعب')][q.difficulty] }}<template v-if="q.tag"> · {{ q.tag }}</template></span></span></label>
                            <ul v-if="q.choices.length" class="ms-6 mt-1 space-y-0.5 text-xs">
                                <li v-for="(c, k) in q.choices" :key="k" class="flex items-center gap-1" :class="c.correct ? 'font-semibold text-emerald-700 dark:text-emerald-400' : ''"><CheckIcon v-if="c.correct" class="h-3.5 w-3.5" /><span v-else class="w-3.5" />{{ c.text }}</li>
                            </ul>
                            <p v-else-if="q.answer" class="ms-6 mt-1 text-xs text-emerald-700 dark:text-emerald-400">{{ t('exam.answer', 'الجواب') }}: {{ q.answer }}</p>
                        </div>
                        <button v-if="gen.drafts.length" type="button" @click="keepDrafts" :disabled="!gen.drafts.some((q) => q.keep)" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-white disabled:opacity-40">{{ t('exam.ai_keep', 'إضافة :n سؤالاً إلى البنك', { n: gen.drafts.filter((q) => q.keep).length }) }}</button>
                    </div>
                </section>

                <form v-if="currentCategory()?.editable" @submit.prevent="importFile" class="flex flex-wrap items-center gap-2 rounded-xl border border-dashed border-gray-300 p-3 text-sm dark:border-gray-600">
                    <span class="text-gray-600 dark:text-gray-300">{{ t('exam.bank_import', 'استيراد أسئلة (Excel/CSV/Aiken/GIFT/Moodle XML):') }}</span>
                    <input ref="fileInput" type="file" accept=".xlsx,.csv,.txt,.gift,.xml,.docx" @input="importForm.file = $event.target.files[0]" class="text-sm" />
                    <select v-model="importForm.lang" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                        <option value="ar">العربية</option><option value="en">English</option><option value="ku">کوردی</option>
                    </select>
                    <button :disabled="!importForm.file || importForm.processing" class="rounded-lg border border-gray-300 px-3 py-1 disabled:opacity-40 dark:border-gray-600">{{ t('exam.import', 'استيراد') }}</button>
                    <a :href="route('exams.manage.import.template')" class="text-xs text-[var(--accent-600)] underline">{{ t('exam.import_template', 'النموذج') }}</a>
                    <p v-if="importForm.errors.file" class="w-full text-xs text-red-600">{{ importForm.errors.file }}</p>
                </form>
                <p class="text-xs text-gray-500">{{ t('exam.bank_hint', 'لإضافة أسئلة من امتحان: زر «إلى البنك» تحت كل سؤال في محرّر الامتحان. ولاستخدامها: «من البنك» أو «سؤال عشوائي» في المحرّر.') }}</p>

                <p v-if="!questions.length" class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-600">{{ t('exam.bank_no_questions', 'لا أسئلة في هذه الفئة بعد.') }}</p>
                <div v-for="(q, i) in questions" :key="q.id" class="flex items-start gap-3 rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <span class="mt-0.5 rounded bg-gray-100 px-2 py-0.5 text-xs dark:bg-gray-700">{{ i + 1 }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="line-clamp-2 whitespace-pre-line" dir="auto">{{ q.body }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ t('exam.type_' + q.type, TYPE_LABELS[q.type]) }} · {{ q.points }} {{ t('exam.points', 'درجة') }} · {{ q.languages.join(' / ') }}</p>
                        <p v-if="q.quality?.uses" class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                            <ChartBarIcon class="h-3.5 w-3.5" />
                            {{ t('exam.bank_used', 'استُعمل :n مرة', { n: q.quality.uses }) }}
                            <template v-if="q.quality.facility !== null"> · {{ t('exam.bank_facility', 'السهولة') }} {{ Math.round(q.quality.facility * 100) }}%</template>
                            <template v-if="q.quality.discrimination !== null"> · {{ t('exam.bank_disc', 'التمييز') }} {{ q.quality.discrimination }}</template>
                            <span v-for="f in q.quality.flags" :key="f" class="rounded-full px-2 py-0.5" :class="f === 'check_key' || f === 'exposed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">{{ t('exam.bank_flag_' + f, FLAGS[f]) }}</span>
                        </p>
                    </div>
                    <button v-if="currentCategory()?.editable" @click="removeQuestion(q)" class="rounded p-1.5 text-gray-400 hover:text-red-500"><TrashIcon class="h-4 w-4" /></button>
                </div>
            </main>
        </div>

        <Modal :show="!!editing" @close="editing = null" max-width="md">
            <form @submit.prevent="saveCategory" class="space-y-4 p-6">
                <h3 class="text-lg font-semibold">{{ editing?.id ? t('exam.edit_category', 'تعديل الفئة') : t('exam.new_category', 'فئة جديدة') }}</h3>
                <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.category_name', 'الاسم') }}</span>
                    <input v-model="catForm.name" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" />
                    <span v-if="catForm.errors.name" class="text-xs text-red-600">{{ catForm.errors.name }}</span></label>
                <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.subject', 'المادة') }}</span>
                    <input v-model="catForm.subject" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                <label class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                    {{ t('exam.share_category', 'مشتركة: يستخدمها كل الأساتذة (التعديل لصاحبها فقط)') }} <ToggleSwitch v-model="catForm.is_shared" /></label>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="editing = null" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button :disabled="catForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white">{{ t('action.save', 'حفظ') }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
