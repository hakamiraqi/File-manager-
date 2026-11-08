<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { useTrans } from '@/Composables/useTrans';
import { PlusIcon, TrashIcon, PencilSquareIcon, UserGroupIcon, ArrowUpTrayIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ groups: Array, current: Number, members: Array, canImport: Boolean });
const { t } = useTrans();
const currentGroup = () => props.groups.find((g) => g.id === props.current);

// New / edit group
const editing = ref(null);
const groupForm = useForm({ name: '', stage: '', department: '' });
function openGroup(g = null) {
    editing.value = g ?? {};
    groupForm.name = g?.name ?? '';
    groupForm.stage = g?.stage ?? '';
    groupForm.department = g?.department ?? '';
    groupForm.clearErrors();
}
function saveGroup() {
    const done = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    editing.value?.id ? groupForm.put(route('exams.manage.groups.update', editing.value.id), done) : groupForm.post(route('exams.manage.groups.store'), done);
}
const toDelete = ref(null);
const removeGroup = () => router.delete(route('exams.manage.groups.destroy', toDelete.value.id), { onFinish: () => (toDelete.value = null) });

// Members
const memberForm = useForm({ emails: '' });
const addMembers = () => memberForm.post(route('exams.manage.groups.members.store', props.current), { preserveScroll: true, onSuccess: () => memberForm.reset() });
const removeMember = (m) => router.delete(route('exams.manage.groups.members.destroy', [props.current, m.id]), { preserveScroll: true });

// New accounts from a sheet, straight into this group; the file of passwords comes back.
const importFile = ref(null);
const importError = ref('');
const importing = ref(false);
async function importStudents() {
    if (!importFile.value) return;
    importing.value = true;
    importError.value = '';
    const data = new FormData();
    data.append('file', importFile.value);
    data.append('group_id', props.current);
    try {
        const res = await window.axios.post(route('exams.manage.students.import'), data, { responseType: 'blob' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(res.data);
        a.download = 'students-passwords.csv';
        a.click();
        router.reload({ preserveScroll: true });
    } catch (e) {
        const text = e.response?.data instanceof Blob ? await e.response.data.text() : '';
        try { importError.value = JSON.parse(text).errors?.file?.[0] ?? JSON.parse(text).message; } catch { importError.value = t('exam.upload_failed', 'تعذّر رفع الملف.'); }
    } finally {
        importing.value = false;
    }
}
</script>

<template>
    <Head :title="t('exam.groups', 'الصفوف والمجموعات')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-lg font-semibold"><UserGroupIcon class="h-5 w-5 text-gray-500" /> {{ t('exam.groups', 'الصفوف والمجموعات') }}</h2>
                <button @click="openGroup()" class="flex items-center gap-1 rounded-lg bg-[var(--accent-600)] px-3 py-1.5 text-sm text-white"><PlusIcon class="h-4 w-4" /> {{ t('exam.new_group', 'مجموعة جديدة') }}</button>
            </div>
        </template>

        <p v-if="$page.props.errors.group" class="mx-4 mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 sm:mx-6 dark:bg-red-900/30 dark:text-red-300">{{ $page.props.errors.group }}</p>
        <div class="grid gap-4 p-4 sm:p-6 lg:grid-cols-4">
            <nav class="space-y-1 lg:col-span-1">
                <p v-if="!groups.length" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-600">
                    {{ t('exam.groups_empty', 'لا توجد مجموعات بعد. أنشئ مجموعة لكل صف أو شعبة، مثل «المرحلة الثانية — شعبة أ».') }}
                </p>
                <Link v-for="g in groups" :key="g.id" :href="route('exams.manage.groups', { group: g.id })" preserve-scroll
                    class="block rounded-lg border px-3 py-2 text-sm" :class="g.id === current ? 'border-[var(--accent-600)] bg-[var(--accent-50)] dark:bg-gray-700' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800'">
                    <span class="font-medium">{{ g.name }}</span>
                    <span class="block text-xs text-gray-500">{{ [g.stage, g.department].filter(Boolean).join(' · ') }} · {{ g.members }} {{ t('exam.students', 'طالب') }} · {{ g.exams }} {{ t('exam.exams_n', 'امتحان') }}</span>
                </Link>
            </nav>

            <section v-if="currentGroup()" class="space-y-4 lg:col-span-3">
                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="min-w-0 flex-1">
                        <p class="text-lg font-semibold">{{ currentGroup().name }}</p>
                        <p class="text-xs text-gray-500">{{ [currentGroup().stage, currentGroup().department].filter(Boolean).join(' · ') }}<span v-if="currentGroup().owner"> · {{ currentGroup().owner }}</span></p>
                    </div>
                    <template v-if="currentGroup().editable">
                        <button @click="openGroup(currentGroup())" class="rounded p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('action.edit', 'تعديل')"><PencilSquareIcon class="h-4 w-4" /></button>
                        <button @click="toDelete = currentGroup()" class="rounded p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30" :title="t('action.delete', 'حذف')"><TrashIcon class="h-4 w-4" /></button>
                    </template>
                </div>

                <div v-if="currentGroup().editable" class="grid gap-4 md:grid-cols-2">
                    <form @submit.prevent="addMembers" class="space-y-2 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <p class="text-sm font-medium">{{ t('exam.add_students', 'إضافة طلاب لهم حسابات') }}</p>
                        <textarea v-model="memberForm.emails" rows="3" dir="ltr" :placeholder="t('exam.emails_ph', 'بريد في كل سطر')" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                        <p v-if="memberForm.errors.emails" class="text-xs text-red-600">{{ memberForm.errors.emails }}</p>
                        <button :disabled="memberForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-1.5 text-sm text-white disabled:opacity-50">{{ t('exam.add', 'إضافة') }}</button>
                    </form>
                    <div v-if="canImport" class="space-y-2 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <p class="text-sm font-medium">{{ t('exam.import_into_group', 'حسابات جديدة من Excel إلى هذه المجموعة') }}</p>
                        <p class="text-xs text-gray-500">{{ t('exam.import_into_group_hint', 'أعمدة: الاسم، البريد، كلمة المرور (اختياري). من له حساب يُضاف فقط. يُنزَّل ملف بكلمات المرور الجديدة.') }}</p>
                        <input type="file" accept=".xlsx,.csv" @input="importFile = $event.target.files[0]" class="block w-full text-sm" />
                        <p v-if="importError" class="text-xs text-red-600">{{ importError }}</p>
                        <button @click="importStudents" :disabled="!importFile || importing" class="flex items-center gap-1 rounded-lg border border-gray-300 px-4 py-1.5 text-sm disabled:opacity-40 dark:border-gray-600"><ArrowUpTrayIcon class="h-4 w-4" /> {{ t('exam.import', 'استيراد') }}</button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <p v-if="!members.length" class="p-6 text-center text-sm text-gray-500">{{ t('exam.group_no_members', 'لا طلاب في هذه المجموعة بعد.') }}</p>
                    <div v-for="(m, i) in members" :key="m.id" class="flex items-center gap-3 border-b border-gray-100 px-4 py-2 text-sm last:border-0 dark:border-gray-700">
                        <span class="w-8 text-xs text-gray-400">{{ i + 1 }}</span>
                        <span class="min-w-0 flex-1"><span class="font-medium">{{ m.name }}</span> <span class="text-xs text-gray-500" dir="ltr">{{ m.email }}</span></span>
                        <button v-if="currentGroup().editable" @click="removeMember(m)" class="rounded p-1 text-gray-400 hover:text-red-500"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                </div>
                <p class="text-xs text-gray-500">{{ t('exam.groups_hint', 'لإعطاء امتحان لمجموعة: اخترها في إعدادات الامتحان. امتحان بلا مجموعات يراه كل الطلاب.') }}</p>
            </section>
        </div>

        <Modal :show="!!editing" @close="editing = null" max-width="md">
            <form @submit.prevent="saveGroup" class="space-y-3 p-6">
                <h3 class="text-lg font-semibold">{{ editing?.id ? t('exam.edit_group', 'تعديل المجموعة') : t('exam.new_group', 'مجموعة جديدة') }}</h3>
                <label class="block text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.group_name', 'الاسم') }}</span><input v-model="groupForm.name" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" />
                    <span v-if="groupForm.errors.name" class="text-xs text-red-600">{{ groupForm.errors.name }}</span></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.stage', 'المرحلة / الصف') }}</span><input v-model="groupForm.stage" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                    <label class="text-sm"><span class="mb-1 block text-xs text-gray-500">{{ t('exam.department', 'القسم') }}</span><input v-model="groupForm.department" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700" /></label>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="editing = null" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button :disabled="groupForm.processing" class="rounded-lg bg-[var(--accent-600)] px-4 py-2 text-sm text-white">{{ t('action.save', 'حفظ') }}</button>
                </div>
            </form>
        </Modal>
        <Modal :show="!!toDelete" @close="toDelete = null" max-width="sm">
            <div class="space-y-4 p-6">
                <p class="text-sm">{{ t('exam.delete_group_q', 'حذف المجموعة «:n»؟ يبقى الطلاب وحساباتهم.', { n: toDelete?.name }) }}</p>
                <div class="flex justify-end gap-2">
                    <button @click="toDelete = null" class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button @click="removeGroup" class="rounded-lg bg-red-600 px-4 py-2 text-sm text-white">{{ t('action.delete', 'حذف') }}</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
