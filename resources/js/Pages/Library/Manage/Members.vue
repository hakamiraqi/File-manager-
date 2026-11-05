<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import { useTrans } from '@/Composables/useTrans';
import { PlusIcon, PencilSquareIcon, TrashIcon, PrinterIcon, ArrowUpTrayIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ members: Object, filters: Object });
const q = ref(props.filters.q ?? '');
const typeLabel = (type) => ({ student: t('library.type.student', 'طالب'), teacher: t('library.type.teacher', 'تدريسي'), staff: t('library.type.staff', 'موظف') })[type] ?? type;

const open = ref(false);
const editing = ref(null);
const form = useForm({ name: '', card_number: '', type: 'student', stage: '', phone: '', email: '', active: true, notes: '' });
function openForm(m = null) {
    editing.value = m;
    form.clearErrors();
    Object.assign(form, { name: m?.name ?? '', card_number: m?.card_number ?? '', type: m?.type ?? 'student', stage: m?.stage ?? '', phone: m?.phone ?? '', email: m?.email ?? '', active: m?.active ?? true, notes: m?.notes ?? '' });
    open.value = true;
}
function submit() {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editing.value ? form.put(route('library.manage.members.update', editing.value.id), opts) : form.post(route('library.manage.members.store'), opts);
}
function destroy(m) {
    if (confirm(t('library.confirm_delete_member', 'حذف القارئ :name؟', { name: m.name }))) router.delete(route('library.manage.members.destroy', m.id), { preserveScroll: true });
}

const importOpen = ref(false);
const importForm = useForm({ file: null });
function runImport() {
    importForm.post(route('library.manage.members.import'), { forceFormData: true, onSuccess: () => { importOpen.value = false; importForm.reset(); } });
}
const cards = (ids) => window.open(route('library.manage.members.cards', ids ? { ids } : {}), '_blank');
</script>

<template>
    <Head :title="t('library.tab.members', 'القرّاء')" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold">{{ t('library.tab.members', 'القرّاء') }}</h2>
                <div class="ms-auto flex gap-2">
                    <button @click="cards()" class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><PrinterIcon class="h-5 w-5" /><span class="hidden sm:inline">{{ t('library.print_cards', 'طباعة البطاقات') }}</span></button>
                    <button @click="importOpen = true" class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600"><ArrowUpTrayIcon class="h-5 w-5" /><span class="hidden sm:inline">{{ t('library.import', 'استيراد Excel') }}</span></button>
                    <button @click="openForm()" class="flex items-center gap-1.5 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white"><PlusIcon class="h-5 w-5" />{{ t('library.new_member', 'قارئ جديد') }}</button>
                </div>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <LibraryTabs />
            <div class="relative max-w-md">
                <MagnifyingGlassIcon class="pointer-events-none absolute start-2.5 top-2.5 h-4 w-4 text-gray-400" />
                <input v-model="q" type="search" @keyup.enter="router.get(route('library.manage.members'), { q: q || undefined }, { preserveState: true })"
                    :placeholder="t('library.find_member', 'ابحث عن قارئ بالاسم أو البطاقة أو البريد')" class="w-full rounded-lg border-gray-300 py-1.5 ps-8 text-sm dark:border-gray-600 dark:bg-gray-700" />
            </div>
            <p class="text-xs text-gray-500">{{ t('library.members_hint', 'مستخدمو الموقع يصبحون قرّاء تلقائياً عند أول إعارة؛ أضف هنا من ليس له حساب.') }}</p>

            <div class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
                <div v-if="!members.data.length" class="p-6 text-center text-gray-400">{{ t('library.no_members', 'لا يوجد قرّاء.') }}</div>
                <div v-for="m in members.data" :key="m.id" class="flex items-center gap-3 px-3 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ m.name }} <span v-if="!m.active" class="text-xs text-red-600">({{ t('library.inactive', 'موقوف') }})</span></p>
                        <p class="truncate text-xs text-gray-500"><span dir="ltr">{{ m.card_number }}</span> · {{ typeLabel(m.type) }}<template v-if="m.stage"> · {{ m.stage }}</template><template v-if="m.user_id"> · {{ t('library.has_account', 'له حساب') }}</template></p>
                    </div>
                    <span class="shrink-0 text-xs text-gray-500">{{ t('library.n_loans', ':n مُعار', { n: m.active_loans_count }) }}</span>
                    <button @click="cards([m.id])" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><PrinterIcon class="h-5 w-5 text-gray-500" /></button>
                    <button @click="openForm(m)" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><PencilSquareIcon class="h-5 w-5 text-gray-500" /></button>
                    <button @click="destroy(m)" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><TrashIcon class="h-5 w-5 text-red-500" /></button>
                </div>
            </div>
            <Pagination :links="members.links" />
        </div>

        <Modal :show="open" @close="open = false">
            <form @submit.prevent="submit" class="grid grid-cols-2 gap-3 p-6">
                <h2 class="col-span-2 text-lg font-medium">{{ editing ? t('library.edit_member', 'تعديل القارئ') : t('library.new_member', 'قارئ جديد') }}</h2>
                <div class="col-span-2"><InputLabel :value="t('auth.name', 'الاسم') + ' *'" /><TextInput v-model="form.name" class="mt-1 block w-full" /><InputError :message="form.errors.name" /></div>
                <div><InputLabel :value="t('library.card_number', 'رقم البطاقة (تلقائي إذا تُرك فارغاً)')" /><TextInput v-model="form.card_number" dir="ltr" class="mt-1 block w-full" /><InputError :message="form.errors.card_number" /></div>
                <div><InputLabel :value="t('library.member_type', 'النوع')" />
                    <select v-model="form.type" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                        <option value="student">{{ typeLabel('student') }}</option><option value="teacher">{{ typeLabel('teacher') }}</option><option value="staff">{{ typeLabel('staff') }}</option>
                    </select></div>
                <div><InputLabel :value="t('library.stage', 'المرحلة')" /><TextInput v-model="form.stage" class="mt-1 block w-full" /></div>
                <div><InputLabel :value="t('library.phone', 'الهاتف')" /><TextInput v-model="form.phone" dir="ltr" class="mt-1 block w-full" /></div>
                <div class="col-span-2"><InputLabel :value="t('auth.email', 'البريد الإلكتروني')" /><TextInput v-model="form.email" type="email" dir="ltr" class="mt-1 block w-full" /><InputError :message="form.errors.email" /></div>
                <label class="col-span-2 flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.active" class="rounded" /> {{ t('library.member_active', 'مسموح له بالاستعارة') }}</label>
                <div class="col-span-2 flex justify-end gap-2">
                    <button type="button" @click="open = false" class="rounded-md border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button :disabled="form.processing" class="rounded-md bg-[var(--accent-600)] px-4 py-2 text-sm text-white">{{ t('action.save', 'حفظ') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :show="importOpen" @close="importOpen = false">
            <form @submit.prevent="runImport" class="space-y-3 p-6">
                <h2 class="text-lg font-medium">{{ t('library.import_members', 'استيراد القرّاء') }}</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ t('library.import_members_hint', 'الصف الأول: الاسم، رقم البطاقة، النوع (طالب/تدريسي/موظف)، المرحلة، الهاتف، البريد. رقم البطاقة اختياري.') }}</p>
                <input type="file" accept=".xlsx,.csv" @change="importForm.file = $event.target.files[0]" class="block w-full text-sm" />
                <InputError :message="importForm.errors.file" />
                <div class="flex justify-end"><button :disabled="!importForm.file || importForm.processing" class="rounded-md bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('library.import_run', 'استيراد') }}</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
