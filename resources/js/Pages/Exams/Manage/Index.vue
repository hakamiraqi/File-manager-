<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { PlusIcon, PencilSquareIcon, ChartBarIcon, DocumentDuplicateIcon, TrashIcon, ClipboardDocumentCheckIcon, EyeIcon, CheckIcon, ArrowUturnLeftIcon, ArrowUpTrayIcon, UserPlusIcon, MagnifyingGlassIcon, CalendarIcon, UsersIcon, QuestionMarkCircleIcon } from '@heroicons/vue/24/outline';
import { ref, computed } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({ exams: Array, to_review: { type: Array, default: () => [] }, wizard: { type: Object, default: () => ({ groups: [], halls: [], subjects: [], has_bank: false }) } });
const { t } = useTrans();
const fmt = (iso) => (iso ? new Date(iso).toLocaleDateString(document.documentElement.lang || 'ar', { dateStyle: 'medium' }) : '—');
const toDelete = ref(null);
// Find an exam: by words of its title or subject, and by its state.
const q = ref('');
const show = ref('all');
const shown = computed(() => {
    const words = q.value.trim().toLowerCase();
    return props.exams.filter((e) => (!words || [e.title, e.subject, e.stage, e.author].filter(Boolean).join(' ').toLowerCase().includes(words))
        && (show.value === 'all' || (show.value === 'published' ? e.is_published : show.value === 'draft' ? !e.is_published : e.pending > 0)));
});
const counts = computed(() => ({ all: props.exams.length, published: props.exams.filter((e) => e.is_published).length, draft: props.exams.filter((e) => !e.is_published).length, grading: props.exams.filter((e) => e.pending > 0).length }));
// An exam from another server (this system's file) or from another system (QTI 3.0 zip).
const importExam = (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (file) router.post(route('exams.manage.package.import'), { file }, { forceFormData: true });
};
const page = usePage();
const canImportStudents = computed(() => !!page.props.permissions?.admin?.users);

// Student accounts from Excel: the answer is a file with each new account and its password.
const studentsOpen = ref(false);
const studentsFile = ref(null);
const studentsBusy = ref(false);
const studentsError = ref('');
async function importStudents() {
    studentsBusy.value = true;
    studentsError.value = '';
    try {
        const body = new FormData();
        body.append('file', studentsFile.value);
        const res = await window.axios.post(route('exams.manage.students.import'), body, { responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'students-accounts.csv';
        a.click();
        URL.revokeObjectURL(url);
        studentsOpen.value = false;
    } catch (e) {
        try { studentsError.value = JSON.parse(await e.response.data.text()).message; } catch { studentsError.value = t('exam.import_failed', 'تعذّر الاستيراد.'); }
    } finally {
        studentsBusy.value = false;
    }
}

// Official photos for the invigilator: a ZIP of pictures named by student number or e-mail.
const photosMsg = ref('');
const photosBusy = ref(false);
async function uploadPhotos(event) {
    const file = event.target.files[0];
    event.target.value = '';
    if (!file) return;
    photosBusy.value = true;
    photosMsg.value = '';
    try {
        const body = new FormData();
        body.append('file', file);
        const r = (await window.axios.post(route('exams.manage.students.photos'), body)).data;
        photosMsg.value = t('exam.photos_saved', 'حُفظت صور :n طالباً.', { n: r.saved }) + (r.unknown_count ? ' ' + t('exam.photos_unknown', 'بلا طالب مطابق: :list', { list: r.unknown.join('، ') + (r.unknown_count > r.unknown.length ? '…' : '') }) : '');
    } catch (e) {
        photosMsg.value = e.response?.data?.message || t('exam.import_failed', 'تعذّر الاستيراد.');
    } finally {
        photosBusy.value = false;
    }
}

// Exams waiting for this teacher's review.
const reviewNotes = ref({});
const decide = (e, decision) => router.post(route('exams.manage.review', e.id), { decision, note: reviewNotes.value[e.id] ?? '' }, { preserveScroll: true });

// «امتحان جديد» in three steps (2026-11-09, by request): name and subject; how the questions come
// in; the group, time and hall. The rest waits in the settings, at its defaults.
const wiz = ref(null);
const step = ref(1);
const create = () => {
    step.value = 1;
    wiz.value = { title: '', subject: '', stage: '', start: 'write', group_ids: [], date: '', time: '', duration_minutes: 60, hall: '', processing: false, error: '' };
};
const toggleGroup = (id) => { const l = wiz.value.group_ids; const i = l.indexOf(id); i >= 0 ? l.splice(i, 1) : l.push(id); };
const wizStudents = computed(() => (wiz.value ? props.wizard.groups.filter((g) => wiz.value.group_ids.includes(g.id)).reduce((n, g) => n + g.members, 0) : 0));
function next() {
    if (step.value === 1 && !wiz.value.title.trim()) { wiz.value.error = t('wizard.title_needed', 'اكتب اسم الامتحان.'); return; }
    wiz.value.error = '';
    step.value++;
}
function finish(blank = false) {
    const w = wiz.value;
    w.processing = true;
    const body = blank ? {} : {
        title: w.title, subject: w.subject || null, stage: w.stage || null, start: w.start, group_ids: w.group_ids,
        duration_minutes: w.duration_minutes || 60, hall: w.hall || null, opens_at: w.date ? `${w.date} ${w.time || '09:00'}` : null,
    };
    router.post(route('exams.manage.store'), body, { onFinish: () => (w.processing = false) });
}
const STARTS = [
    { key: 'write', icon: PencilSquareIcon, title: () => t('wizard.write', 'أكتب الأسئلة بنفسي'), text: () => t('wizard.write_text', 'يفتح المحرر على إضافة سؤال: اختيار، صح وخطأ، مقالي…') },
    { key: 'bank', icon: QuestionMarkCircleIcon, title: () => t('wizard.bank', 'من بنك الأسئلة'), text: () => t('wizard.bank_text', 'تختار أسئلة جاهزة من فئات البنك.') },
    { key: 'import', icon: ArrowUpTrayIcon, title: () => t('wizard.import', 'من ملف'), text: () => t('wizard.import_text', 'Excel أو Word أو Aiken أو GIFT أو Moodle، مع الصور (ZIP).') },
];
const duplicate = (e) => router.post(route('exams.manage.duplicate', e.id));
const destroy = () => router.delete(route('exams.manage.destroy', toDelete.value.id), { onFinish: () => (toDelete.value = null) });
</script>

<template>
    <Head :title="t('nav.exams_manage', 'إدارة الامتحانات')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold">{{ t('nav.exams_manage', 'إدارة الامتحانات') }}</h2>
                <div class="ms-auto flex items-center gap-1.5">
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700" :title="t('exam.import_exam_hint', 'ملف امتحان من سيرفر آخر (.json) أو حزمة QTI 3.0 (.zip)')">
                        <input type="file" accept=".json,.zip" class="sr-only" @change="importExam" /><ArrowUpTrayIcon class="h-4 w-4" /><span class="hidden sm:inline">{{ t('exam.import_exam', 'استيراد امتحان') }}</span></label>
                    <button v-if="canImportStudents" @click="studentsOpen = true" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700" :title="t('exam.import_students', 'استيراد طلاب')"><UserPlusIcon class="h-4 w-4" /><span class="hidden sm:inline">{{ t('exam.import_students', 'استيراد طلاب') }}</span></button>
                    <button @click="create" class="inline-flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-[var(--accent-700)]"><PlusIcon class="h-4 w-4" /> {{ t('exam.new', 'امتحان جديد') }}</button>
                </div>
                <span v-if="$page.props.errors.file" class="w-full text-xs text-red-600">{{ $page.props.errors.file }}</span>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <section v-if="to_review.length" class="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
                <p class="flex items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-100"><ClipboardDocumentCheckIcon class="h-5 w-5" /> {{ t('exam.to_review', 'امتحانات تنتظر مراجعتك') }} ({{ to_review.length }})</p>
                <div v-for="e in to_review" :key="e.id" class="flex flex-wrap items-center gap-2 rounded-lg bg-white p-3 text-sm dark:bg-gray-800">
                    <div class="min-w-0 flex-1"><p class="font-medium">{{ e.title }}</p><p class="text-xs text-gray-500">{{ e.author }} · {{ e.questions }} {{ t('exam.questions_n', 'سؤال') }}<template v-if="e.note"> · {{ e.note }}</template></p></div>
                    <Link :href="route('exams.manage.print', { exam: e.id, key: 1 })" class="flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-600"><EyeIcon class="h-4 w-4" /> {{ t('exam.review_read', 'قراءة الأسئلة والإجابات') }}</Link>
                    <input v-model="reviewNotes[e.id]" :placeholder="t('exam.review_note_ph', 'ملاحظة (لازمة عند الإعادة)')" class="w-64 rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    <button @click="decide(e, 'return')" :disabled="!reviewNotes[e.id]?.trim()" class="flex items-center gap-1 rounded-lg border border-amber-400 px-3 py-1.5 text-amber-800 disabled:opacity-40 dark:text-amber-200"><ArrowUturnLeftIcon class="h-4 w-4" /> {{ t('exam.review_return', 'إعادة للتعديل') }}</button>
                    <button @click="decide(e, 'approve')" class="flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-white"><CheckIcon class="h-4 w-4" /> {{ t('exam.review_approve', 'موافقة') }}</button>
                </div>
            </section>
            <div v-if="!exams.length" class="rounded-xl border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500 dark:border-gray-600">
                {{ t('exam.manage_empty', 'لا توجد امتحانات بعد. اضغط «امتحان جديد» للبدء.') }}
            </div>
            <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-[12rem] flex-1 sm:max-w-sm">
                    <MagnifyingGlassIcon class="pointer-events-none absolute top-2.5 h-4 w-4 text-gray-400 ltr:left-3 rtl:right-3" />
                    <input v-model="q" type="search" :placeholder="t('exam.find', 'ابحث بالعنوان أو المادة…')" class="w-full rounded-xl border-gray-300 py-2 text-sm ltr:pl-9 rtl:pr-9 dark:border-gray-600 dark:bg-gray-800" />
                </div>
                <div class="flex gap-1 overflow-x-auto rounded-xl bg-gray-100 p-1 text-xs dark:bg-gray-800">
                    <button v-for="k in ['all', 'published', 'draft', 'grading']" :key="k" v-show="k !== 'grading' || counts.grading" @click="show = k"
                        class="whitespace-nowrap rounded-lg px-3 py-1.5 font-medium" :class="show === k ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-600 dark:text-gray-400'">
                        {{ { all: t('exam.f_all', 'الكل'), published: t('exam.published', 'منشور'), draft: t('exam.draft', 'مسودة'), grading: t('exam.pending_grading', 'بانتظار التصحيح') }[k] }} <span class="opacity-60">{{ counts[k] }}</span>
                    </button>
                </div>
            </div>
            <p v-if="!shown.length" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-600">{{ t('exam.none_found', 'لا توجد امتحانات بهذا البحث.') }}</p>

            <!-- Phones: one card per exam -->
            <div class="space-y-3 md:hidden">
                <div v-for="e in shown" :key="'c' + e.id" class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <Link :href="e.can_edit ? route('exams.manage.edit', e.id) : route('exams.manage.results', e.id)" class="block font-semibold leading-snug hover:text-[var(--accent-700)]" dir="auto">{{ e.title }}</Link>
                            <p class="mt-0.5 truncate text-xs text-gray-500">{{ [e.subject, e.stage, e.author].filter(Boolean).join(' · ') || '—' }}</p>
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="e.is_published ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                            {{ e.is_published ? t('exam.published', 'منشور') : t('exam.draft', 'مسودة') }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                        <span class="inline-flex items-center gap-1"><QuestionMarkCircleIcon class="h-4 w-4 text-gray-400" /> {{ e.questions }} {{ t('exam.questions_n', 'سؤال') }}</span>
                        <span class="inline-flex items-center gap-1"><UsersIcon class="h-4 w-4 text-gray-400" /> {{ e.attempts }} {{ t('exam.attempts', 'المحاولات') }}<span v-if="e.pending" class="rounded-full bg-amber-100 px-1.5 text-amber-700">{{ e.pending }} {{ t('exam.to_grade', 'للتصحيح') }}</span></span>
                        <span v-if="e.closes_at" class="inline-flex items-center gap-1"><CalendarIcon class="h-4 w-4 text-gray-400" /> {{ t('exam.closes', 'يغلق') }} {{ fmt(e.closes_at) }}</span>
                        <span v-if="e.review && e.review !== 'approved'" class="rounded-full px-2 py-0.5" :class="e.review === 'returned' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">{{ e.review === 'returned' ? t('exam.review_returned', 'أُعيد للتعديل') : t('exam.review_pending', 'بانتظار المراجعة') }}</span>
                    </div>
                    <div class="mt-3 grid grid-cols-4 gap-1 border-t border-gray-100 pt-2 text-[11px] text-gray-600 dark:border-gray-700 dark:text-gray-300">
                        <Link v-if="e.can_edit" :href="route('exams.manage.edit', e.id)" class="flex flex-col items-center gap-0.5 rounded-lg py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700"><PencilSquareIcon class="h-5 w-5" /> {{ t('action.edit', 'تعديل') }}</Link>
                        <Link :href="route('exams.manage.results', e.id)" class="flex flex-col items-center gap-0.5 rounded-lg py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700"><ChartBarIcon class="h-5 w-5" /> {{ t('exam.results', 'النتائج') }}</Link>
                        <button v-if="e.can_edit" @click="duplicate(e)" class="flex flex-col items-center gap-0.5 rounded-lg py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700"><DocumentDuplicateIcon class="h-5 w-5" /> {{ t('exam.duplicate', 'نسخ') }}</button>
                        <button v-if="e.can_edit" @click="toDelete = e" class="flex flex-col items-center gap-0.5 rounded-lg py-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30"><TrashIcon class="h-5 w-5" /> {{ t('action.delete', 'حذف') }}</button>
                    </div>
                </div>
            </div>

            <!-- Wider screens: the table -->
            <div v-if="shown.length" class="hidden overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm md:block dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ t('exam.title', 'العنوان') }}</th>
                            <th class="px-2 py-2 text-start">{{ t('exam.subject', 'المادة') }}</th>
                            <th class="px-2 py-2">{{ t('exam.questions', 'الأسئلة') }}</th>
                            <th class="px-2 py-2">{{ t('exam.attempts', 'المحاولات') }}</th>
                            <th class="px-2 py-2">{{ t('exam.closes', 'يغلق') }}</th>
                            <th class="px-2 py-2">{{ t('exam.status', 'الحالة') }}</th>
                            <th class="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in shown" :key="e.id" class="border-t border-gray-100 hover:bg-gray-50/60 dark:border-gray-700 dark:hover:bg-gray-700/30">
                            <td class="px-4 py-2.5">
                                <Link :href="e.can_edit ? route('exams.manage.edit', e.id) : route('exams.manage.results', e.id)" class="font-medium hover:text-[var(--accent-700)]" dir="auto">{{ e.title }}</Link>
                                <p v-if="e.author" class="text-xs text-gray-400">{{ e.author }}<span v-if="!e.can_edit"> · {{ t('exam.you_mark', 'أنت مصحّح') }}</span></p>
                            </td>
                            <td class="px-2 py-2.5 text-gray-600 dark:text-gray-300">{{ [e.subject, e.stage].filter(Boolean).join(' · ') || '—' }}</td>
                            <td class="px-2 py-2.5 text-center">{{ e.questions }}</td>
                            <td class="px-2 py-2.5 text-center">
                                {{ e.attempts }}
                                <span v-if="e.pending" class="ms-1 rounded-full bg-amber-100 px-1.5 text-xs text-amber-700" :title="t('exam.pending_grading', 'بانتظار التصحيح')">{{ e.pending }}</span>
                            </td>
                            <td class="px-2 py-2.5 text-center text-gray-500">{{ fmt(e.closes_at) }}</td>
                            <td class="px-2 py-2.5 text-center">
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="e.is_published ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                                    {{ e.is_published ? t('exam.published', 'منشور') : t('exam.draft', 'مسودة') }}
                                </span>
                                <span v-if="e.review && e.review !== 'approved'" class="ms-1 rounded-full px-2 py-0.5 text-xs" :class="e.review === 'returned' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">
                                    {{ e.review === 'returned' ? t('exam.review_returned', 'أُعيد للتعديل') : t('exam.review_pending', 'بانتظار المراجعة') }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-end">
                                <Link v-if="e.can_edit" :href="route('exams.manage.edit', e.id)" class="inline-block rounded p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('action.edit', 'تعديل')"><PencilSquareIcon class="h-4 w-4" /></Link>
                                <Link :href="route('exams.manage.results', e.id)" class="inline-block rounded p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('exam.results', 'النتائج')"><ChartBarIcon class="h-4 w-4" /></Link>
                                <button v-if="e.can_edit" @click="duplicate(e)" class="rounded p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('exam.duplicate', 'نسخ')"><DocumentDuplicateIcon class="h-4 w-4" /></button>
                                <button v-if="e.can_edit" @click="toDelete = e" class="rounded p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30" :title="t('action.delete', 'حذف')"><TrashIcon class="h-4 w-4" /></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </template>
        </div>

        <Modal :show="studentsOpen" @close="studentsOpen = false" max-width="md">
            <form @submit.prevent="importStudents" class="space-y-4 p-6">
                <h3 class="text-lg font-semibold">{{ t('exam.import_students', 'استيراد طلاب') }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('exam.students_hint', 'ملف Excel أو CSV بأعمدة: الاسم، البريد أو الرقم الجامعي (أو كلاهما)، كلمة المرور (اختياري؛ تُولَّد إن تُركت فارغة). يُنزَّل بعدها ملف فيه كل حساب وكلمة مروره — احفظه، فلا يظهر مرة أخرى.') }}</p>
                <p class="rounded-lg bg-[var(--accent-50)] px-3 py-2 text-xs text-[var(--accent-700)] dark:bg-gray-700 dark:text-gray-200">{{ t('exam.students_group_hint', 'أضف عمود «المجموعة» (واختيارياً «المرحلة» و«القسم») ليدخل كل طالب مجموعته مباشرة؛ تُنشأ المجموعة إن لم تكن موجودة، ومن له حساب يُنقل إليها.') }}</p>
                <input type="file" accept=".xlsx,.csv" @input="studentsFile = $event.target.files[0]" class="block w-full text-sm" />
                <p v-if="studentsError" class="text-sm text-red-600">{{ studentsError }}</p>
                <div class="space-y-1 border-t border-gray-200 pt-3 dark:border-gray-700">
                    <p class="text-sm font-medium">{{ t('exam.photos', 'صور الطلاب للمراقب') }}</p>
                    <p class="text-xs text-gray-500">{{ t('exam.photos_hint', 'ملف ZIP فيه صورة لكل طالب باسم رقمه الجامعي أو بريده (2024001.jpg). تظهر للمراقب وحده بجانب اسم الطالب.') }}</p>
                    <label class="inline-block cursor-pointer rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600" :class="photosBusy ? 'opacity-50' : ''">
                        <input type="file" accept=".zip" class="sr-only" :disabled="photosBusy" @change="uploadPhotos" />{{ photosBusy ? '…' : t('exam.photos_upload', 'رفع ملف الصور') }}</label>
                    <p v-if="photosMsg" class="text-xs text-gray-700 dark:text-gray-300">{{ photosMsg }}</p>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="studentsOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button :disabled="!studentsFile || studentsBusy" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('exam.import', 'استيراد') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="!!toDelete" @close="toDelete = null" max-width="md">
            <div class="space-y-4 p-6">
                <h3 class="text-lg font-semibold">{{ t('exam.delete_title', 'حذف الامتحان؟') }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('exam.delete_warn', 'تُحذف الأسئلة وكل محاولات الطلاب ودرجاتهم نهائياً.') }}</p>
                <div class="flex justify-end gap-2">
                    <button @click="toDelete = null" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button @click="destroy" class="rounded-lg bg-red-600 px-4 py-2 text-sm text-white">{{ t('action.delete', 'حذف') }}</button>
                </div>
            </div>
        </Modal>
        <Modal :show="!!wiz" @close="wiz = null" max-width="xl">
            <div v-if="wiz" class="flex max-h-[90dvh] flex-col">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                    <h3 class="text-lg font-semibold">{{ t('exam.new', 'امتحان جديد') }}</h3>
                    <ol class="mt-3 flex items-center gap-2 text-xs">
                        <li v-for="(label, i) in [t('wizard.s1', 'الاسم والمادة'), t('wizard.s2', 'الأسئلة'), t('wizard.s3', 'المجموعة والموعد')]" :key="i" class="flex flex-1 items-center gap-2">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full font-bold" :class="step > i + 1 ? 'bg-emerald-500 text-white' : step === i + 1 ? 'bg-[var(--accent-600)] text-white' : 'bg-gray-200 text-gray-500 dark:bg-gray-700'">
                                <CheckIcon v-if="step > i + 1" class="h-4 w-4" /><template v-else>{{ i + 1 }}</template>
                            </span>
                            <span class="hidden truncate sm:inline" :class="step === i + 1 ? 'font-semibold' : 'text-gray-500'">{{ label }}</span>
                            <span v-if="i < 2" class="h-0.5 flex-1 rounded" :class="step > i + 1 ? 'bg-emerald-400' : 'bg-gray-200 dark:bg-gray-700'" />
                        </li>
                    </ol>
                </div>

                <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4 text-sm">
                    <template v-if="step === 1">
                        <label class="block"><span class="mb-1 block font-medium">{{ t('wizard.title', 'اسم الامتحان') }} <span class="text-red-500">*</span></span>
                            <input v-model="wiz.title" @keydown.enter.prevent="next" :placeholder="t('wizard.title_ph', 'مثل: الامتحان الفصلي الأول — التشريح')" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700" autofocus /></label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block"><span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('wizard.subject', 'المادة') }}</span>
                                <input v-model="wiz.subject" list="wiz-subjects" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700" />
                                <datalist id="wiz-subjects"><option v-for="s in wizard.subjects" :key="s" :value="s" /></datalist></label>
                            <label class="block"><span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('wizard.stage', 'المرحلة') }}</span><input v-model="wiz.stage" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                        </div>
                    </template>

                    <template v-else-if="step === 2">
                        <p class="font-medium">{{ t('wizard.how', 'كيف تضيف الأسئلة؟') }}</p>
                        <button v-for="o in STARTS" :key="o.key" type="button" @click="wiz.start = o.key" :disabled="o.key === 'bank' && !wizard.has_bank"
                            class="flex w-full items-start gap-3 rounded-2xl border-2 p-3 text-start transition disabled:opacity-40" :class="wiz.start === o.key ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-200 hover:border-gray-300 dark:border-gray-600'">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-[var(--accent-600)] shadow-sm dark:bg-gray-800"><component :is="o.icon" class="h-5 w-5" /></span>
                            <span class="min-w-0 flex-1"><span class="block font-semibold">{{ o.title() }}</span><span class="block text-xs text-gray-500">{{ o.key === 'bank' && !wizard.has_bank ? t('wizard.no_bank', 'لا فئات في البنك بعد.') : o.text() }}</span></span>
                            <CheckIcon v-if="wiz.start === o.key" class="h-5 w-5 text-[var(--accent-600)]" />
                        </button>
                    </template>

                    <template v-else>
                        <div>
                            <p class="mb-1 font-medium">{{ t('wizard.groups', 'لمن الامتحان؟') }}</p>
                            <p v-if="!wizard.groups.length" class="rounded-xl bg-gray-50 p-3 text-xs text-gray-500 dark:bg-gray-900/40">{{ t('wizard.no_groups', 'لا مجموعات بعد: يراه كل الطلاب. تقدر تنشئ المجموعات من «الصفوف والمجموعات».') }}</p>
                            <div v-else class="flex flex-wrap gap-2">
                                <button v-for="g in wizard.groups" :key="g.id" type="button" @click="toggleGroup(g.id)" class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs"
                                    :class="wiz.group_ids.includes(g.id) ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white' : 'border-gray-300 dark:border-gray-600'">
                                    <CheckIcon v-if="wiz.group_ids.includes(g.id)" class="h-3.5 w-3.5" />{{ g.name }}<span class="opacity-75">· {{ g.members }}</span>
                                </button>
                            </div>
                            <p class="mt-1 text-xs" :class="wiz.group_ids.length ? 'text-gray-500' : 'text-amber-700 dark:text-amber-300'">{{ wiz.group_ids.length ? t('wizard.n_students', ':n طالب', { n: wizStudents }) : t('wizard.everyone', 'بلا مجموعة: يراه كل الطلاب.') }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <label class="col-span-2 block"><span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('wizard.date', 'اليوم') }}</span><input v-model="wiz.date" type="date" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                            <label class="block"><span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('wizard.time', 'الساعة') }}</span><input v-model="wiz.time" type="time" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                            <label class="block"><span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('wizard.minutes', 'المدة (دقيقة)') }}</span><input v-model.number="wiz.duration_minutes" type="number" min="1" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                        </div>
                        <label class="block"><span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('wizard.hall', 'القاعة (اختياري)') }}</span>
                            <input v-model="wiz.hall" list="wiz-halls" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700" />
                            <datalist id="wiz-halls"><option v-for="h in wizard.halls" :key="h" :value="h" /></datalist></label>
                        <p class="rounded-xl bg-gray-50 p-3 text-xs text-gray-500 dark:bg-gray-900/40">{{ t('wizard.later', 'يُنشأ الامتحان غير منشور. باقي الخيارات (الخلط، الحماية، عرض النتائج…) في «الإعدادات» بقيمها المعتادة، وتنشره متى ما جهز.') }}</p>
                    </template>
                    <p v-if="wiz.error" class="text-sm text-red-600">{{ wiz.error }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-5 py-3 dark:border-gray-700">
                    <button v-if="step === 1" type="button" @click="finish(true)" :disabled="wiz.processing" class="text-xs text-gray-500 underline">{{ t('wizard.blank', 'امتحان فارغ مباشرة') }}</button>
                    <button v-else type="button" @click="step--" class="rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('wizard.back', 'السابق') }}</button>
                    <span class="flex-1" />
                    <button v-if="step < 3" type="button" @click="next" class="rounded-xl bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white">{{ t('wizard.next', 'التالي') }}</button>
                    <button v-else type="button" @click="finish()" :disabled="wiz.processing" class="inline-flex items-center gap-1 rounded-xl bg-[var(--accent-600)] px-5 py-2 text-sm font-semibold text-white disabled:opacity-50"><CheckIcon class="h-4 w-4" /> {{ t('wizard.create', 'إنشاء وفتح المحرر') }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
