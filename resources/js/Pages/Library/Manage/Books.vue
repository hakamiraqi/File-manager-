<script setup>
import { ref, reactive } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import LibraryTabs from '@/Components/Library/LibraryTabs.vue';
import BookCover from '@/Components/Library/BookCover.vue';
import BookFormModal from '@/Components/Library/BookFormModal.vue';
import { useTrans } from '@/Composables/useTrans';
import { useLibrary } from '@/Composables/useLibrary';
import {
    PlusIcon, ArrowUpTrayIcon, ArrowDownTrayIcon, PrinterIcon, PencilSquareIcon, TrashIcon, ChevronDownIcon, MagnifyingGlassIcon, CheckIcon,
} from '@heroicons/vue/24/outline';

const { t } = useTrans();
const { statusLabel, statusClass } = useLibrary();
const props = defineProps({ books: Object, sections: Array, filters: Object, statuses: Array, shelfCodes: { type: Array, default: () => [] } });

const q = ref(props.filters.q ?? '');
const section = ref(props.filters.section ?? '');
function go() {
    router.get(route('library.manage.books'), { q: q.value || undefined, section: section.value || undefined }, { preserveState: true });
}

const formOpen = ref(false);
const editing = ref(null);
function openNew() { editing.value = null; formOpen.value = true; }
function openEdit(book) { editing.value = book; formOpen.value = true; }
function destroy(book) {
    if (!confirm(t('library.confirm_delete_book', 'حذف الكتاب ":name" وكل نسخه؟', { name: book.title }))) return;
    router.delete(route('library.manage.books.destroy', book.id), { preserveScroll: true });
}

const open = reactive({});
const selectedBooks = ref([]);
function toggleBook(id) {
    selectedBooks.value = selectedBooks.value.includes(id) ? selectedBooks.value.filter((x) => x !== id) : [...selectedBooks.value, id];
}
function printLabels(params) {
    window.open(route('library.manage.labels', params), '_blank');
}

// Copies
const copyEdits = reactive({});
function editCopy(c) { copyEdits[c.id] = { barcode: c.barcode, rfid_tag: c.rfid_tag ?? '', shelf: c.shelf ?? '', status: c.status, errors: {} }; }
function saveCopy(c) {
    const e = copyEdits[c.id];
    const data = { barcode: e.barcode, rfid_tag: e.rfid_tag || null, shelf: e.shelf || null };
    if (['available', 'damaged', 'lost'].includes(e.status)) data.status = e.status;
    router.put(route('library.manage.copies.update', c.id), data, {
        preserveScroll: true,
        onSuccess: () => delete copyEdits[c.id],
        onError: (errors) => (e.errors = errors),
    });
}
function deleteCopy(c) {
    if (!confirm(t('library.confirm_delete_copy', 'حذف النسخة :code؟', { code: c.barcode }))) return;
    router.delete(route('library.manage.copies.destroy', c.id), { preserveScroll: true });
}
const addForm = reactive({});
function addCopies(book) {
    const f = addForm[book.id] ?? { count: 1, shelf: '' };
    router.post(route('library.manage.copies.store', book.id), { count: f.count, shelf: f.shelf || null }, { preserveScroll: true });
}

// Import
const importOpen = ref(false);
const importForm = useForm({ file: null });
const importKind = ref('excel'); // excel | marc
const marcForm = useForm({ file: null, existing: 'skip', section_id: null });
function runImport() {
    const done = { forceFormData: true, preserveScroll: true, onSuccess: () => { importOpen.value = false; importForm.reset(); marcForm.reset(); } };
    if (importKind.value === 'marc') marcForm.post(route('library.manage.import.marc'), done);
    else importForm.post(route('library.manage.import'), done);
}

// MARC export, for Koha or any library system.
const exportOpen = ref(false);
const exp = reactive({ format: 'mrc', section: '', item_type: 'BK', branch: '' });
function exportUrl() {
    const params = Object.fromEntries(Object.entries(exp).filter(([, v]) => v !== '' && v !== null));
    return route('library.manage.export.marc', params);
}
</script>

<template>
    <Head :title="t('library.tab.books', 'إدارة الكتب')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('library.tab.books', 'إدارة الكتب') }}</h2>
                <div class="ms-auto flex gap-2">
                    <button @click="importOpen = true" class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                        <ArrowUpTrayIcon class="h-5 w-5" /> <span class="hidden sm:inline">{{ t('library.import_any', 'استيراد') }}</span>
                    </button>
                    <button @click="exportOpen = true" class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                        <ArrowDownTrayIcon class="h-5 w-5" /> <span class="hidden sm:inline">{{ t('library.export_marc', 'تصدير MARC') }}</span>
                    </button>
                    <button @click="openNew" class="flex items-center gap-1.5 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)]">
                        <PlusIcon class="h-5 w-5" /> {{ t('library.new_book', 'كتاب جديد') }}
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-4 p-4 sm:p-6">
            <LibraryTabs />

            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-[12rem] flex-1">
                    <MagnifyingGlassIcon class="pointer-events-none absolute start-2.5 top-2.5 h-4 w-4 text-gray-400" />
                    <input v-model="q" type="search" @keyup.enter="go" :placeholder="t('library.search', 'ابحث بالعنوان أو المؤلف أو ISBN أو الباركود...')"
                        class="w-full rounded-lg border-gray-300 py-1.5 ps-8 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100" />
                </div>
                <select v-model="section" @change="go" class="rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">{{ t('library.all_sections', 'كل الأقسام') }}</option>
                    <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.path }}</option>
                </select>
                <button v-if="selectedBooks.length" @click="printLabels({ books: selectedBooks })" class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                    <PrinterIcon class="h-4 w-4" /> {{ t('library.print_labels_n', 'طباعة ملصقات (:n كتاب)', { n: selectedBooks.length }) }}
                </button>
            </div>

            <div v-if="!books.data.length" class="py-16 text-center text-gray-400">{{ t('library.empty_manage', 'لا توجد كتب. أضف كتاباً أو استورد ملف Excel.') }}</div>

            <div v-else class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
                <div v-for="book in books.data" :key="book.id">
                    <div class="flex items-center gap-3 px-3 py-2.5">
                        <button type="button" @click="toggleBook(book.id)" class="flex h-5 w-5 shrink-0 items-center justify-center rounded border"
                            :class="selectedBooks.includes(book.id) ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white' : 'border-gray-300 dark:border-gray-500'">
                            <CheckIcon v-if="selectedBooks.includes(book.id)" class="h-3.5 w-3.5" />
                        </button>
                        <BookCover :book="book" size="sm" class="w-10 shrink-0" />
                        <button class="min-w-0 flex-1 text-start" @click="open[book.id] = !open[book.id]">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100" dir="auto">{{ book.title }}</p>
                            <p class="truncate text-xs text-gray-500" dir="auto">{{ [book.authors, book.section?.name, book.isbn].filter(Boolean).join(' · ') }}</p>
                        </button>
                        <span class="hidden shrink-0 text-xs text-gray-500 sm:inline">{{ t('library.available_n', 'متاح :n من :m', { n: book.available_count, m: book.copies_count }) }}</span>
                        <button @click="open[book.id] = !open[book.id]" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><ChevronDownIcon class="h-5 w-5 transition" :class="{ 'rotate-180': open[book.id] }" /></button>
                        <button @click="openEdit(book)" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><PencilSquareIcon class="h-5 w-5 text-gray-500" /></button>
                        <button @click="destroy(book)" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><TrashIcon class="h-5 w-5 text-red-500" /></button>
                    </div>

                    <!-- Copies -->
                    <div v-if="open[book.id]" class="space-y-2 bg-gray-50 px-3 py-3 dark:bg-gray-900/40">
                        <div v-for="c in book.copies" :key="c.id" class="rounded-lg border border-gray-200 bg-white p-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                            <div v-if="!copyEdits[c.id]" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="font-mono" dir="ltr">{{ c.barcode }}</span>
                                <span v-if="c.rfid_tag" class="text-xs text-gray-500" dir="ltr">RFID: {{ c.rfid_tag }}</span>
                                <span v-if="c.shelf" class="text-xs text-gray-500">{{ t('library.shelf', 'الرف') }}: {{ c.shelf }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(c.status)">{{ statusLabel(c.status) }}</span>
                                <span class="ms-auto flex gap-1">
                                    <button @click="printLabels({ copies: [c.id] })" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700" :title="t('library.print_label', 'طباعة ملصق')"><PrinterIcon class="h-4 w-4" /></button>
                                    <button @click="editCopy(c)" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><PencilSquareIcon class="h-4 w-4" /></button>
                                    <button @click="deleteCopy(c)" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-gray-700"><TrashIcon class="h-4 w-4 text-red-500" /></button>
                                </span>
                            </div>
                            <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                                <input v-model="copyEdits[c.id].barcode" dir="ltr" :placeholder="t('library.barcode', 'الباركود')" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" />
                                <input v-model="copyEdits[c.id].rfid_tag" dir="ltr" placeholder="RFID" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" />
                                <input v-model="copyEdits[c.id].shelf" list="shelf-codes" :placeholder="t('library.shelf', 'الرف')" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" />
                                <select v-model="copyEdits[c.id].status" :disabled="['loaned', 'on_hold'].includes(c.status)" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700">
                                    <option v-for="s in statuses" :key="s" :value="s" :disabled="['loaned', 'on_hold'].includes(s)">{{ statusLabel(s) }}</option>
                                </select>
                                <div class="flex gap-1">
                                    <button @click="saveCopy(c)" class="flex-1 rounded-md bg-[var(--accent-600)] px-2 py-1 text-sm text-white">{{ t('action.save', 'حفظ') }}</button>
                                    <button @click="delete copyEdits[c.id]" class="rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-600">×</button>
                                </div>
                                <p v-for="(msg, k) in copyEdits[c.id].errors" :key="k" class="col-span-full text-xs text-red-600">{{ msg }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-gray-500">{{ t('library.add_copies', 'إضافة نسخ') }}:</span>
                            <input type="number" min="1" max="100" :value="addForm[book.id]?.count ?? 1" @input="addForm[book.id] = { ...(addForm[book.id] ?? { shelf: '' }), count: $event.target.value }"
                                class="w-20 rounded-md border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" />
                            <input :value="addForm[book.id]?.shelf ?? ''" list="shelf-codes" @input="addForm[book.id] = { ...(addForm[book.id] ?? { count: 1 }), shelf: $event.target.value }"
                                :placeholder="t('library.shelf', 'الرف')" class="w-28 rounded-md border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-700" />
                            <button @click="addCopies(book)" class="rounded-md border border-gray-300 px-3 py-1 hover:bg-white dark:border-gray-600 dark:hover:bg-gray-700">{{ t('action.add', 'إضافة') }}</button>
                            <button @click="printLabels({ books: [book.id] })" class="ms-auto flex items-center gap-1 rounded-md border border-gray-300 px-3 py-1 hover:bg-white dark:border-gray-600 dark:hover:bg-gray-700">
                                <PrinterIcon class="h-4 w-4" /> {{ t('library.print_all_labels', 'ملصقات كل النسخ') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <Pagination :links="books.links" />
        </div>

        <BookFormModal :show="formOpen" :book="editing" :sections="sections" :default-section="filters.section" @close="formOpen = false" />

        <Modal :show="importOpen" @close="importOpen = false">
            <form @submit.prevent="runImport" class="space-y-4 p-6">
                <h2 class="text-lg font-medium">{{ t('library.import_any', 'استيراد') }}</h2>
                <div class="flex gap-1 rounded-lg bg-gray-100 p-1 text-sm dark:bg-gray-700/50">
                    <button type="button" class="flex-1 rounded-md px-3 py-1.5" :class="importKind === 'excel' ? 'bg-white shadow dark:bg-gray-800' : 'text-gray-500'" @click="importKind = 'excel'">Excel / CSV</button>
                    <button type="button" class="flex-1 rounded-md px-3 py-1.5" :class="importKind === 'marc' ? 'bg-white shadow dark:bg-gray-800' : 'text-gray-500'" @click="importKind = 'marc'">MARC (Koha)</button>
                </div>
                <template v-if="importKind === 'excel'">
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ t('library.import_hint', 'ملف Excel (.xlsx) أو CSV. الصف الأول أسماء الأعمدة: العنوان، المؤلف، ISBN، الناشر، السنة، اللغة، القسم (مثل: الدراسات الأولية / جراحة الفم)، عدد النسخ، الرف، الباركود (اختياري). الأقسام غير الموجودة تُنشأ تلقائياً.') }}
                    </p>
                    <a :href="route('library.manage.import.template')" class="inline-block text-sm text-[var(--accent-600)] hover:underline">{{ t('library.import_template', 'تنزيل نموذج جاهز') }}</a>
                    <input type="file" accept=".xlsx,.csv" @change="importForm.file = $event.target.files[0]" class="block w-full text-sm" />
                    <InputError :message="importForm.errors.file" />
                </template>
                <template v-else>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ t('library.import_marc_hint', 'ملف MARC من Koha أو أي نظام مكتبات: ISO 2709 (.mrc) أو MARCXML، بصيغة MARC21 أو UNIMARC. نسخ Koha (الحقل 952 أو 995) تُضاف نسخاً بباركودها. يُعرف الكتاب الموجود مسبقاً من رقم السجل أو ISBN.') }}
                    </p>
                    <input type="file" accept=".mrc,.marc,.iso,.xml,.dat" @change="marcForm.file = $event.target.files[0]" class="block w-full text-sm" />
                    <InputError :message="marcForm.errors.file" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">
                            <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.marc_existing', 'الكتاب الموجود مسبقاً') }}</span>
                            <select v-model="marcForm.existing" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                                <option value="skip">{{ t('library.marc_skip', 'يبقى كما هو (تُضاف نسخه الجديدة فقط)') }}</option>
                                <option value="update">{{ t('library.marc_update', 'تُحدّث بياناته من الملف') }}</option>
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.marc_section', 'قسم الكتب الجديدة') }}</span>
                            <select v-model="marcForm.section_id" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                                <option :value="null">{{ t('library.no_section', 'بدون قسم') }}</option>
                                <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.path }}</option>
                            </select>
                        </label>
                    </div>
                </template>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="importOpen = false" class="rounded-md border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <button :disabled="(importKind === 'marc' ? !marcForm.file || marcForm.processing : !importForm.file || importForm.processing)" class="rounded-md bg-[var(--accent-600)] px-4 py-2 text-sm text-white disabled:opacity-50">{{ t('library.import_run', 'استيراد') }}</button>
                </div>
            </form>
        </Modal>
        <Modal :show="exportOpen" @close="exportOpen = false">
            <div class="space-y-4 p-6">
                <h2 class="text-lg font-medium">{{ t('library.export_marc', 'تصدير MARC') }}</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ t('library.export_marc_hint', 'الفهرس كاملاً بصيغة MARC21، تستورده Koha أو أي نظام مكتبات. كل نسخة تُكتب حقل 952 بباركودها ورقم استدعائها. الكتب المستوردة من MARC تحتفظ بسجلها الأصلي كاملاً.') }}
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.export_format', 'الصيغة') }}</span>
                        <select v-model="exp.format" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option value="mrc">ISO 2709 (.mrc)</option>
                            <option value="xml">MARCXML (.xml)</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.field.section', 'القسم') }}</span>
                        <select v-model="exp.section" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700">
                            <option value="">{{ t('library.all_sections', 'كل الأقسام') }}</option>
                            <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.path }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.koha_itemtype', 'نوع المادة في Koha (952$y)') }}</span>
                        <input v-model="exp.item_type" dir="ltr" maxlength="10" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-gray-600 dark:text-gray-300">{{ t('library.koha_branch', 'رمز المكتبة في Koha (952$a/$b)') }}</span>
                        <input v-model="exp.branch" dir="ltr" maxlength="10" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700" />
                    </label>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="exportOpen = false" class="rounded-md border border-gray-300 px-4 py-2 text-sm dark:border-gray-600">{{ t('action.cancel', 'إلغاء') }}</button>
                    <a :href="exportUrl()" @click="exportOpen = false" class="rounded-md bg-[var(--accent-600)] px-4 py-2 text-sm text-white">{{ t('library.export_run', 'تنزيل') }}</a>
                </div>
            </div>
        </Modal>
        <datalist id="shelf-codes">
            <option v-for="code in shelfCodes" :key="code" :value="code" />
        </datalist>
    </AppLayout>
</template>
