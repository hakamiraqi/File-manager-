<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { PlusIcon, PencilSquareIcon, ChartBarIcon, DocumentDuplicateIcon, TrashIcon, ClipboardDocumentCheckIcon, EyeIcon, CheckIcon, ArrowUturnLeftIcon, ArrowUpTrayIcon, UserPlusIcon, MagnifyingGlassIcon, CalendarIcon, UsersIcon, QuestionMarkCircleIcon } from '@heroicons/vue/24/outline';
import { ref, computed } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({ exams: Array, to_review: { type: Array, default: () => [] } });
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

const create = () => router.post(route('exams.manage.store'));
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
    </AppLayout>
</template>
