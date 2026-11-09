<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { PlusIcon, PencilSquareIcon, ChartBarIcon, DocumentDuplicateIcon, TrashIcon, ClipboardDocumentCheckIcon, EyeIcon, CheckIcon, ArrowUturnLeftIcon } from '@heroicons/vue/24/outline';
import { ref, computed } from 'vue';
import Modal from '@/Components/Modal.vue';

defineProps({ exams: Array, to_review: { type: Array, default: () => [] } });
const { t } = useTrans();
const fmt = (iso) => (iso ? new Date(iso).toLocaleDateString(document.documentElement.lang || 'ar', { dateStyle: 'medium' }) : '—');
const toDelete = ref(null);
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
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">{{ t('nav.exams_manage', 'إدارة الامتحانات') }}</h2>
                <label class="ms-auto cursor-pointer rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600" :title="t('exam.import_exam_hint', 'ملف امتحان من سيرفر آخر (.json) أو حزمة QTI 3.0 (.zip)')">
                    <input type="file" accept=".json,.zip" class="sr-only" @change="importExam" />{{ t('exam.import_exam', 'استيراد امتحان') }}</label>
                <span v-if="$page.props.errors.file" class="text-xs text-red-600">{{ $page.props.errors.file }}</span>
                <button v-if="canImportStudents" @click="studentsOpen = true" class=" rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">{{ t('exam.import_students', 'استيراد طلاب') }}</button>
                <button @click="create" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm text-white hover:bg-[var(--accent-700)]"><PlusIcon class="h-4 w-4" /> {{ t('exam.new', 'امتحان جديد') }}</button>
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
            <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
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
                        <tr v-for="e in exams" :key="e.id" class="border-t border-gray-100 dark:border-gray-700">
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
