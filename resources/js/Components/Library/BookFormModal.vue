<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import BookCover from '@/Components/Library/BookCover.vue';
import { useTrans } from '@/Composables/useTrans';
import { CameraIcon, PhotoIcon, ArrowPathIcon } from '@heroicons/vue/24/outline';

const { t } = useTrans();
const props = defineProps({ show: Boolean, book: { type: Object, default: null }, sections: Array, defaultSection: { type: Number, default: null } });
const emit = defineEmits(['close']);

const blank = {
    title: '', subtitle: '', authors: '', isbn: '', publisher: '', year: '', language: '', edition: '', pages: '',
    description: '', section_id: null, reference_only: false, cover: null, cover_url: '', remove_cover: false, copies: 1, shelf: '',
};
const form = useForm({ ...blank });
const preview = ref(null);
const lookup = ref({ busy: false, error: '' });

watch(() => props.show, (show) => {
    if (!show) return;
    form.clearErrors();
    form.defaults({ ...blank });
    form.reset();
    lookup.value = { busy: false, error: '' };
    if (props.book) {
        Object.keys(blank).forEach((k) => { if (k in props.book && props.book[k] !== null) form[k] = props.book[k]; });
        form.copies = null;
        preview.value = props.book.cover_url;
    } else {
        form.section_id = props.defaultSection;
        preview.value = null;
    }
});

const previewBook = computed(() => ({ title: form.title, cover_url: preview.value }));

function pickCover(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    form.cover = file;
    form.cover_url = '';
    form.remove_cover = false;
    preview.value = URL.createObjectURL(file);
}

function removeCover() {
    form.cover = null;
    form.cover_url = '';
    form.remove_cover = true;
    preview.value = null;
}

async function fetchIsbn() {
    if (!form.isbn) return;
    lookup.value = { busy: true, error: '' };
    try {
        const res = await window.axios.get(route('library.manage.isbn', form.isbn.replace(/[^0-9Xx]/g, '')));
        const d = res.data;
        ['title', 'subtitle', 'authors', 'publisher', 'year', 'pages'].forEach((k) => { if (d[k] && !form[k]) form[k] = d[k]; });
        if (d.cover_url && !form.cover) { form.cover_url = d.cover_url; preview.value = d.cover_url; }
    } catch (e) {
        lookup.value.error = e.response?.data?.message || t('library.isbn_failed', 'تعذّر جلب البيانات.');
    } finally {
        lookup.value.busy = false;
    }
}

function submit() {
    const opts = { preserveScroll: true, forceFormData: true, onSuccess: () => emit('close') };
    if (props.book) {
        form.transform((d) => ({ ...d, _method: 'put' })).post(route('library.manage.books.update', props.book.id), opts);
    } else {
        form.transform((d) => d).post(route('library.manage.books.store'), opts);
    }
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="$emit('close')">
        <form @submit.prevent="submit" class="p-5 sm:p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ book ? t('library.edit_book', 'تعديل الكتاب') : t('library.new_book', 'كتاب جديد') }}</h2>

            <div class="mt-4 flex flex-col gap-5 sm:flex-row">
                <!-- Cover -->
                <div class="flex shrink-0 flex-col items-center gap-2 sm:w-36">
                    <BookCover :book="previewBook" class="w-32" />
                    <div class="flex gap-1.5">
                        <label class="flex cursor-pointer items-center gap-1 rounded-md border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                            <CameraIcon class="h-4 w-4" /> {{ t('library.take_photo', 'تصوير') }}
                            <input type="file" accept="image/*" capture="environment" class="hidden" @change="pickCover" />
                        </label>
                        <label class="flex cursor-pointer items-center gap-1 rounded-md border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                            <PhotoIcon class="h-4 w-4" /> {{ t('library.pick_image', 'صورة') }}
                            <input type="file" accept="image/*" class="hidden" @change="pickCover" />
                        </label>
                    </div>
                    <button v-if="preview" type="button" class="text-xs text-red-600 hover:underline" @click="removeCover">{{ t('library.remove_cover', 'إزالة الغلاف') }}</button>
                    <InputError :message="form.errors.cover" />
                </div>

                <div class="grid flex-1 grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <InputLabel value="ISBN" />
                        <div class="mt-1 flex gap-2">
                            <TextInput v-model="form.isbn" dir="ltr" class="block w-full" @keydown.enter.prevent="fetchIsbn" />
                            <button type="button" :disabled="lookup.busy || !form.isbn" @click="fetchIsbn" class="flex shrink-0 items-center gap-1 rounded-md border border-gray-300 px-3 text-sm hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:hover:bg-gray-700">
                                <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': lookup.busy }" /> {{ t('library.fetch_isbn', 'جلب البيانات') }}
                            </button>
                        </div>
                        <p v-if="lookup.error" class="mt-1 text-xs text-amber-600">{{ lookup.error }}</p>
                    </div>
                    <div class="col-span-2">
                        <InputLabel :value="t('library.field.title', 'العنوان') + ' *'" />
                        <TextInput v-model="form.title" dir="auto" class="mt-1 block w-full" />
                        <InputError :message="form.errors.title" class="mt-1" />
                    </div>
                    <div class="col-span-2">
                        <InputLabel :value="t('library.field.authors', 'المؤلف')" />
                        <TextInput v-model="form.authors" dir="auto" class="mt-1 block w-full" />
                    </div>
                    <div class="col-span-2">
                        <InputLabel :value="t('library.field.section', 'القسم')" />
                        <select v-model="form.section_id" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option :value="null">{{ t('library.no_section', 'بدون قسم') }}</option>
                            <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.path }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel :value="t('library.field.publisher', 'الناشر')" />
                        <TextInput v-model="form.publisher" dir="auto" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('library.field.year', 'السنة')" />
                        <TextInput v-model="form.year" dir="ltr" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('library.field.edition', 'الطبعة')" />
                        <TextInput v-model="form.edition" dir="auto" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('library.field.language', 'اللغة')" />
                        <TextInput v-model="form.language" dir="auto" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel :value="t('library.field.pages', 'عدد الصفحات')" />
                        <TextInput v-model="form.pages" type="number" min="1" class="mt-1 block w-full" />
                    </div>
                    <div class="flex items-end pb-2">
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                            <input type="checkbox" v-model="form.reference_only" class="rounded border-gray-300" />
                            {{ t('library.reference_only', 'للقراءة داخل المكتبة') }}
                        </label>
                    </div>
                    <template v-if="!book">
                        <div>
                            <InputLabel :value="t('library.copies_count', 'عدد النسخ')" />
                            <TextInput v-model="form.copies" type="number" min="0" max="100" class="mt-1 block w-full" />
                            <InputError :message="form.errors.copies" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel :value="t('library.shelf', 'الرف')" />
                            <TextInput v-model="form.shelf" list="shelf-codes" class="mt-1 block w-full" />
                        </div>
                    </template>
                    <div class="col-span-2">
                        <InputLabel :value="t('library.field.description', 'الوصف')" />
                        <textarea v-model="form.description" rows="3" dir="auto" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="$emit('close')">{{ t('action.cancel', 'إلغاء') }}</SecondaryButton>
                <PrimaryButton :disabled="form.processing">{{ t('action.save', 'حفظ') }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
