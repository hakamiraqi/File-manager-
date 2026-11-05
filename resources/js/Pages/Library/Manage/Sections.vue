<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import { useTrans } from '@/Composables/useTrans';
import { PlusIcon, PencilSquareIcon, TrashIcon, FolderIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ sections: Array });

const open = ref(false);
const editing = ref(null);
const preview = ref(null);
const form = useForm({ name: '', parent_id: null, description: '', location: '', sort: 0, cover: null, remove_cover: false });

function openForm(section = null, parentId = null) {
    editing.value = section;
    form.clearErrors();
    form.name = section?.name ?? '';
    form.parent_id = section ? section.parent_id : parentId;
    form.description = section?.description ?? '';
    form.location = section?.location ?? '';
    form.sort = section?.sort ?? 0;
    form.cover = null;
    form.remove_cover = false;
    preview.value = section?.cover_url ?? null;
    open.value = true;
}
function pick(e) {
    const f = e.target.files?.[0];
    if (f) { form.cover = f; preview.value = URL.createObjectURL(f); }
}
function submit() {
    const opts = { forceFormData: true, preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.transform((d) => ({ ...d, _method: 'put' })).post(route('library.manage.sections.update', editing.value.id), opts);
    else form.transform((d) => d).post(route('library.manage.sections.store'), opts);
}
function destroy(s) {
    if (!confirm(t('library.confirm_delete_section', 'حذف القسم ":name"؟ كتبه وأقسامه الفرعية تنتقل إلى القسم الأعلى.', { name: s.name }))) return;
    router.delete(route('library.manage.sections.destroy', s.id), { preserveScroll: true });
}
const depth = (s) => (s.path.match(/ \/ /g) || []).length;
</script>

<template>
    <Head :title="t('library.tab.sections', 'الأقسام')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('library.tab.sections', 'الأقسام') }}</h2>
                <button @click="openForm()" class="ms-auto flex items-center gap-1.5 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)]">
                    <PlusIcon class="h-5 w-5" /> {{ t('library.new_section', 'قسم جديد') }}
                </button>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <LibraryTabs />
            <div v-if="!sections.length" class="py-16 text-center text-gray-400">{{ t('library.no_sections', 'لا توجد أقسام بعد.') }}</div>
            <div v-else class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
                <div v-for="s in sections" :key="s.id" class="flex items-center gap-3 py-2.5 pe-3" :style="{ paddingInlineStart: `${12 + depth(s) * 24}px` }">
                    <img v-if="s.cover_url" :src="s.cover_url" class="h-9 w-9 shrink-0 rounded-md object-cover" alt="" />
                    <FolderIcon v-else class="h-9 w-9 shrink-0 text-amber-400" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ s.name }}</p>
                        <p class="truncate text-xs text-gray-500">{{ t('library.n_books', ':n كتاب', { n: s.books_count }) }}<template v-if="s.location"> · {{ s.location }}</template></p>
                    </div>
                    <button @click="openForm(null, s.id)" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('library.add_subsection', 'قسم فرعي')"><PlusIcon class="h-5 w-5 text-gray-500" /></button>
                    <button @click="openForm(s)" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><PencilSquareIcon class="h-5 w-5 text-gray-500" /></button>
                    <button @click="destroy(s)" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><TrashIcon class="h-5 w-5 text-red-500" /></button>
                </div>
            </div>
        </div>

        <Modal :show="open" @close="open = false">
            <form @submit.prevent="submit" class="space-y-3 p-6">
                <h2 class="text-lg font-medium">{{ editing ? t('library.edit_section', 'تعديل القسم') : t('library.new_section', 'قسم جديد') }}</h2>
                <div>
                    <InputLabel :value="t('library.section_name', 'اسم القسم') + ' *'" />
                    <TextInput v-model="form.name" class="mt-1 block w-full" />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('library.parent_section', 'داخل القسم')" />
                    <select v-model="form.parent_id" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option :value="null">{{ t('library.top_level', '— قسم رئيسي —') }}</option>
                        <option v-for="s in sections.filter((x) => x.id !== editing?.id)" :key="s.id" :value="s.id">{{ s.path }}</option>
                    </select>
                    <InputError :message="form.errors.parent_id" class="mt-1" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel :value="t('library.location', 'المكان (رف، غرفة)')" />
                        <TextInput v-model="form.location" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('library.sort', 'الترتيب')" />
                        <TextInput v-model="form.sort" type="number" min="0" class="mt-1 block w-full" />
                    </div>
                </div>
                <div>
                    <InputLabel :value="t('library.field.description', 'الوصف')" />
                    <textarea v-model="form.description" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                </div>
                <div class="flex items-center gap-3">
                    <img v-if="preview" :src="preview" class="h-14 w-14 rounded-md object-cover" alt="" />
                    <label class="cursor-pointer rounded-md border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600">
                        {{ t('library.section_image', 'صورة القسم') }}
                        <input type="file" accept="image/*" class="hidden" @change="pick" />
                    </label>
                    <button v-if="preview" type="button" class="text-xs text-red-600" @click="preview = null; form.cover = null; form.remove_cover = true">{{ t('library.remove_cover', 'إزالة') }}</button>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="open = false" class="rounded-md border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button :disabled="form.processing" class="rounded-md bg-[var(--accent-600)] px-4 py-2 text-sm text-white">{{ t('action.save', 'حفظ') }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
