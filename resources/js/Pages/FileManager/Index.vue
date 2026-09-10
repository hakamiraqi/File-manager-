<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, Link, router, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import FileThumbnail from '@/Components/FileThumbnail.vue';
import NewFolderModal from '@/Components/NewFolderModal.vue';
import RenameModal from '@/Components/RenameModal.vue';
import MoveModal from '@/Components/MoveModal.vue';
import ShareModal from '@/Components/ShareModal.vue';
import PreviewModal from '@/Components/PreviewModal.vue';
import { useChunkedUpload } from '@/Composables/useChunkedUpload';
import {
    FolderIcon,
    FolderPlusIcon,
    FolderArrowDownIcon,
    ArrowUpTrayIcon,
    LinkIcon,
    MagnifyingGlassIcon,
    EllipsisVerticalIcon,
    ArrowDownTrayIcon,
    TrashIcon,
    ShareIcon,
    PencilIcon,
    ArrowsRightLeftIcon,
    DocumentDuplicateIcon,
    GlobeAltIcon,
    DocumentMagnifyingGlassIcon,
    InformationCircleIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    PauseIcon,
    PlayIcon,
    XMarkIcon,
    ArrowPathIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    currentFolder: Object,
    breadcrumbs: Array,
    folders: Array,
    files: Array,
    filters: Object,
});

const page = usePage();
const can = computed(() => page.props.permissions ?? {});
const remoteUploadEnabled = computed(() => page.props.appSettings?.remoteUploadEnabled ?? false);

const search = ref(props.filters.q ?? '');
const selected = reactive({ files: new Set(), folders: new Set() });
const fileInput = ref(null);
const folderInput = ref(null);
const dragOver = ref(false);
const draggedItems = ref(null); // { files: [id...], folders: [id...] } while dragging an item within the app
const dropTargetFolderId = ref(undefined); // undefined = none, null = root breadcrumb

const newFolderModal = ref(false);
const renameTarget = ref(null);
const moveTarget = ref(null);
const shareTarget = ref(null);
const previewFile = ref(null);

// ---- Folder info panel (type + researcher/supervisor/thesis/publisher/etc.) ----
const showFolderInfo = ref(false);
const folderInfoFields = ['researcher_name', 'supervisor_name', 'thesis_title', 'publisher', 'digital_library', 'publication_year'];

function makeFolderInfoForm(folder) {
    return useForm({
        folder_type: folder?.folder_type ?? 'files',
        ...Object.fromEntries(folderInfoFields.map((field) => [field, folder?.[field] ?? ''])),
    });
}

const folderInfoForm = ref(makeFolderInfoForm(props.currentFolder));

watch(
    () => props.currentFolder?.id,
    () => {
        folderInfoForm.value = makeFolderInfoForm(props.currentFolder);
    },
);

function saveFolderInfo() {
    if (!props.currentFolder) return;
    folderInfoForm.value.put(route('folders.update', props.currentFolder.id), { preserveScroll: true });
}

const {
    uploads,
    uploadMany,
    uploadFolderTree,
    clearFinished,
    pause: pauseUpload,
    resume: resumeUpload,
    cancel: cancelUpload,
    interrupted,
    resumeInterrupted,
    dismissInterrupted,
} = useChunkedUpload({
    onUploaded: () => router.reload({ only: ['folders', 'files'] }),
});

const resumeFileInput = ref(null);
const resumeTarget = ref(null);

function pickFileToResume(pending) {
    resumeTarget.value = pending;
    resumeFileInput.value?.click();
}

function onResumeFileChosen(event) {
    const file = event.target.files[0];
    event.target.value = '';
    if (!file || !resumeTarget.value) return;

    const matched = resumeInterrupted(resumeTarget.value, file, props.currentFolder?.id ?? null);
    if (!matched) {
        alert('الملف الذي اخترته لا يطابق اسم وحجم الرفع المتوقّف. الرجاء اختيار نفس الملف.');
    }
    resumeTarget.value = null;
}

function goToFolder(id) {
    router.get(route('dashboard'), { folder: id }, { preserveState: true });
}

function runSearch() {
    router.get(route('dashboard'), { q: search.value, folder: props.currentFolder?.id }, { preserveState: true });
}

function changeSort(sort) {
    router.get(route('dashboard'), { ...props.filters, sort, folder: props.currentFolder?.id }, { preserveState: true });
}

function toggleSelect(type, id, event) {
    const set = selected[type];
    if (!event.ctrlKey && !event.metaKey) {
        selected.files.clear();
        selected.folders.clear();
    }
    set.has(id) ? set.delete(id) : set.add(id);
}

const selectionCount = computed(() => selected.files.size + selected.folders.size);

function clearSelection() {
    selected.files.clear();
    selected.folders.clear();
}

function onFilesChosen(event) {
    uploadMany(event.target.files, props.currentFolder?.id ?? null);
    event.target.value = '';
}

function onFolderChosen(event) {
    uploadFolderTree(event.target.files, props.currentFolder?.id ?? null);
    event.target.value = '';
}

function isExternalFileDrag(event) {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

function onContainerDragOver(event) {
    if (isExternalFileDrag(event)) {
        dragOver.value = true;
    }
}

function onDrop(event) {
    dragOver.value = false;
    if (event.dataTransfer.files.length) {
        uploadMany(event.dataTransfer.files, props.currentFolder?.id ?? null);
    }
}

function onDragStartItem(event, type, id) {
    const key = type === 'file' ? 'files' : 'folders';
    const inSelection = selected[key].has(id);

    draggedItems.value = inSelection
        ? { files: [...selected.files], folders: [...selected.folders] }
        : { files: type === 'file' ? [id] : [], folders: type === 'folder' ? [id] : [] };

    event.dataTransfer.effectAllowed = 'move';
    // Required by some browsers (notably Firefox) for the drop to fire at all.
    event.dataTransfer.setData('text/plain', 'filenest-item');
}

function onDragEndItem() {
    draggedItems.value = null;
    dropTargetFolderId.value = undefined;
}

function onDragOverDropTarget(event, folderId) {
    if (draggedItems.value) {
        if (draggedItems.value.folders.includes(folderId)) return; // can't drop a folder onto itself
        dropTargetFolderId.value = folderId;
    } else if (isExternalFileDrag(event)) {
        dropTargetFolderId.value = folderId;
    }
}

function onDropOnTarget(event, folderId) {
    dropTargetFolderId.value = undefined;

    if (draggedItems.value) {
        if (draggedItems.value.folders.includes(folderId)) {
            draggedItems.value = null;
            return;
        }
        moveItemsInto(draggedItems.value, folderId);
        draggedItems.value = null;
        return;
    }

    if (event.dataTransfer.files.length) {
        uploadMany(event.dataTransfer.files, folderId);
    }
}

function moveOne(url, data) {
    return new Promise((resolve) => {
        router.put(url, data, { preserveScroll: true, preserveState: true, onFinish: resolve });
    });
}

async function moveItemsInto(items, targetFolderId) {
    for (const id of items.files) {
        const file = props.files.find((f) => f.id === id);
        if (file && file.folder_id === targetFolderId) continue;
        await moveOne(route('files.update', id), { folder_id: targetFolderId });
    }

    for (const id of items.folders) {
        if (id === targetFolderId) continue;
        const folder = props.folders.find((f) => f.id === id);
        if (folder && folder.parent_id === targetFolderId) continue;
        await moveOne(route('folders.update', id), { parent_id: targetFolderId });
    }

    clearSelection();
    router.reload({ only: ['folders', 'files'] });
}

function downloadSelection() {
    const params = new URLSearchParams();
    selected.files.forEach((id) => params.append('file_ids[]', id));
    selected.folders.forEach((id) => params.append('folder_ids[]', id));
    window.location.href = `${route('files.download-zip')}?${params.toString()}`;
}

function deleteSelection() {
    if (!confirm('هل أنت متأكد من نقل العناصر المحددة إلى سلة المحذوفات؟')) return;
    selected.files.forEach((id) => router.delete(route('files.destroy', id), { preserveScroll: true }));
    selected.folders.forEach((id) => router.delete(route('folders.destroy', id), { preserveScroll: true }));
    clearSelection();
}

function deleteOne(type, id) {
    if (!confirm('هل أنت متأكد من نقل هذا العنصر إلى سلة المحذوفات؟')) return;
    router.delete(type === 'file' ? route('files.destroy', id) : route('folders.destroy', id), { preserveScroll: true });
}

function openPreview(file) {
    previewFile.value = file;
}

function togglePublic(folder) {
    router.put(route('folders.update', folder.id), { is_public: !folder.is_public }, { preserveScroll: true });
}

function copyOne(type, id) {
    const url = type === 'file' ? route('files.copy', id) : route('folders.copy', id);
    router.post(url, { folder_id: props.currentFolder?.id ?? null, parent_id: props.currentFolder?.id ?? null }, { preserveScroll: true });
}

function uploadFromUrl() {
    const url = window.prompt('أدخل رابط الملف المراد رفعه:');
    if (!url) return;

    router.post(
        route('uploads.remote'),
        { url, folder_id: props.currentFolder?.id ?? null },
        { preserveScroll: true, onSuccess: () => router.reload({ only: ['folders', 'files'] }) },
    );
}
</script>

<template>
    <Head title="ملفاتي" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <nav class="flex min-w-0 items-center gap-1 text-sm text-gray-500">
                    <button
                        @click="goToFolder(null)"
                        @dragover.prevent="onDragOverDropTarget($event, null)"
                        @dragleave="dropTargetFolderId = undefined"
                        @drop.prevent.stop="onDropOnTarget($event, null)"
                        class="shrink-0 rounded px-1 hover:text-gray-900"
                        :class="{ 'bg-[var(--accent-100)] text-[var(--accent-700)] ring-2 ring-[var(--accent-400)]': dropTargetFolderId === null }"
                    >
                        ملفاتي
                    </button>
                    <template v-for="crumb in breadcrumbs" :key="crumb.id">
                        <span>/</span>
                        <button
                            @click="goToFolder(crumb.id)"
                            @dragover.prevent="onDragOverDropTarget($event, crumb.id)"
                            @dragleave="dropTargetFolderId = undefined"
                            @drop.prevent.stop="onDropOnTarget($event, crumb.id)"
                            class="truncate rounded px-1 hover:text-gray-900"
                            :class="{ 'bg-[var(--accent-100)] text-[var(--accent-700)] ring-2 ring-[var(--accent-400)]': dropTargetFolderId === crumb.id }"
                        >
                            {{ crumb.name }}
                        </button>
                    </template>
                </nav>
                <div class="ms-auto flex items-center gap-2">
                    <div class="relative">
                        <MagnifyingGlassIcon class="pointer-events-none absolute start-2.5 top-2.5 h-4 w-4 text-gray-400" />
                        <input
                            v-model="search"
                            @keyup.enter="runSearch"
                            type="search"
                            placeholder="بحث..."
                            class="w-48 rounded-full border-gray-300 py-1.5 ps-8 text-sm focus:border-[var(--accent-500)] focus:ring-[var(--accent-500)]"
                        />
                    </div>
                </div>
            </div>
        </template>

        <div
            class="relative min-h-full p-6"
            @dragover.prevent="onContainerDragOver"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="onDrop"
        >
            <div v-if="dragOver" class="absolute inset-4 z-10 flex items-center justify-center rounded-xl border-4 border-dashed border-[var(--accent-500)] bg-[var(--accent-50)]/80 text-lg font-medium text-[var(--accent-700)]">
                أفلت الملفات هنا للرفع
            </div>

            <!-- Uploads left unfinished by a previous page load/crash -->
            <div v-if="interrupted.length" class="mb-4 space-y-2">
                <div
                    v-for="pending in interrupted"
                    :key="pending.identifier"
                    class="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800"
                >
                    <ArrowPathIcon class="h-5 w-5 shrink-0" />
                    <span class="min-w-0 flex-1 truncate">
                        رفع لم يكتمل لـ "{{ pending.name }}" — أعد اختيار الملف نفسه للاستكمال من حيث توقّف.
                    </span>
                    <button @click="pickFileToResume(pending)" class="shrink-0 rounded-md bg-amber-600 px-3 py-1 font-medium text-white hover:bg-amber-700">
                        استكمال
                    </button>
                    <button @click="dismissInterrupted(pending)" class="shrink-0 text-amber-500 hover:text-amber-700">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>
            </div>
            <input ref="resumeFileInput" data-testid="resume-file-input" type="file" class="hidden" @change="onResumeFileChosen" />

            <!-- Folder info: researcher/supervisor/thesis/publisher/etc. -->
            <div v-if="currentFolder" class="mb-6 rounded-xl border border-gray-200 bg-white">
                <button
                    data-testid="folder-info-toggle"
                    @click="showFolderInfo = !showFolderInfo"
                    class="flex w-full items-center justify-between px-4 py-3 text-sm font-medium text-gray-700"
                >
                    <span class="flex items-center gap-2">
                        <InformationCircleIcon class="h-5 w-5 text-gray-400" /> معلومات المجلد
                    </span>
                    <ChevronUpIcon v-if="showFolderInfo" class="h-4 w-4 text-gray-400" />
                    <ChevronDownIcon v-else class="h-4 w-4 text-gray-400" />
                </button>
                <form
                    v-if="showFolderInfo"
                    data-testid="folder-info-form"
                    @submit.prevent="saveFolderInfo"
                    class="grid grid-cols-1 gap-4 border-t border-gray-100 p-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="mb-1 block text-xs font-medium text-gray-500">نوع المجلد</label>
                        <div class="flex gap-4 text-sm text-gray-700">
                            <label class="flex items-center gap-1.5">
                                <input
                                    v-model="folderInfoForm.folder_type"
                                    type="radio"
                                    value="files"
                                    :disabled="!can.rename_folder"
                                    class="border-gray-300 text-[var(--accent-600)] focus:ring-[var(--accent-500)]"
                                />
                                ملفات
                            </label>
                            <label class="flex items-center gap-1.5">
                                <input
                                    v-model="folderInfoForm.folder_type"
                                    type="radio"
                                    value="thesis"
                                    :disabled="!can.rename_folder"
                                    class="border-gray-300 text-[var(--accent-600)] focus:ring-[var(--accent-500)]"
                                />
                                أطروحة
                            </label>
                        </div>
                    </div>
                    <template v-if="folderInfoForm.folder_type === 'thesis'">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500">اسم الباحث</label>
                            <input v-model="folderInfoForm.researcher_name" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500">المشرف</label>
                            <input v-model="folderInfoForm.supervisor_name" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500">عنوان الرسالة</label>
                            <input v-model="folderInfoForm.thesis_title" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500">دار النشر</label>
                            <input v-model="folderInfoForm.publisher" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500">المكتبة الرقمية</label>
                            <input v-model="folderInfoForm.digital_library" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500">سنة النشر</label>
                            <input v-model="folderInfoForm.publication_year" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50" />
                        </div>
                    </template>
                    <div v-if="can.rename_folder" class="sm:col-span-2 lg:col-span-3">
                        <button
                            type="submit"
                            :disabled="folderInfoForm.processing"
                            class="rounded-md bg-[var(--accent-600)] px-4 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50"
                        >
                            حفظ
                        </button>
                    </div>
                </form>
            </div>

            <!-- Toolbar -->
            <div class="mb-6 flex flex-wrap items-center gap-3">
                <button
                    v-if="can.new_folder"
                    @click="newFolderModal = true"
                    class="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    <FolderPlusIcon class="h-5 w-5" /> مجلد جديد
                </button>
                <button
                    v-if="can.upload"
                    @click="fileInput.click()"
                    class="flex items-center gap-2 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)]"
                >
                    <ArrowUpTrayIcon class="h-5 w-5" /> رفع ملفات
                </button>
                <button
                    v-if="can.upload"
                    @click="folderInput.click()"
                    class="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    <FolderArrowDownIcon class="h-5 w-5" /> رفع مجلد
                </button>
                <button
                    v-if="can.upload && remoteUploadEnabled"
                    @click="uploadFromUrl"
                    class="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    <LinkIcon class="h-5 w-5" /> رفع من رابط
                </button>
                <input ref="fileInput" data-testid="upload-file-input" type="file" multiple class="hidden" @change="onFilesChosen" />
                <input
                    ref="folderInput"
                    data-testid="upload-folder-input"
                    type="file"
                    webkitdirectory
                    directory
                    multiple
                    class="hidden"
                    @change="onFolderChosen"
                />

                <select
                    :value="filters.sort"
                    @change="changeSort($event.target.value)"
                    class="rounded-lg border-gray-300 text-sm"
                >
                    <option value="name">ترتيب حسب الاسم</option>
                    <option value="created_at">ترتيب حسب التاريخ</option>
                    <option value="size">ترتيب حسب الحجم</option>
                </select>

                <span class="text-sm text-gray-500">{{ folders.length }} مجلد، {{ files.length }} ملف</span>

                <div v-if="selectionCount" class="ms-auto flex items-center gap-2 rounded-lg bg-gray-100 px-3 py-1.5">
                    <span class="text-sm text-gray-600">{{ selectionCount }} محدد</span>
                    <button v-if="can.download" @click="downloadSelection" class="rounded-md p-1.5 hover:bg-gray-200" title="تنزيل كملف مضغوط">
                        <ArrowDownTrayIcon class="h-4 w-4" />
                    </button>
                    <button v-if="can.delete || can.delete_folder" @click="deleteSelection" class="rounded-md p-1.5 hover:bg-gray-200" title="حذف">
                        <TrashIcon class="h-4 w-4 text-red-500" />
                    </button>
                    <button @click="clearSelection" class="text-sm text-gray-500 hover:underline">إلغاء التحديد</button>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="!folders.length && !files.length" class="flex flex-col items-center justify-center py-24 text-gray-400">
                <FolderIcon class="h-16 w-16" />
                <p class="mt-3">هذا المجلد فارغ. اسحب الملفات هنا أو استخدم زر "رفع ملفات" أو "رفع مجلد".</p>
            </div>

            <!-- Grid -->
            <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                <div
                    v-for="folder in folders"
                    :key="`folder-${folder.id}`"
                    class="group relative flex cursor-pointer flex-col items-center rounded-xl border p-4 text-center hover:shadow-md"
                    :class="[
                        selected.folders.has(folder.id) ? 'border-[var(--accent-500)] bg-[var(--accent-50)]' : 'border-gray-200 bg-white',
                        dropTargetFolderId === folder.id ? 'ring-2 ring-[var(--accent-500)]' : '',
                    ]"
                    :draggable="can.move"
                    @click="toggleSelect('folders', folder.id, $event)"
                    @dblclick="goToFolder(folder.id)"
                    @dragstart="onDragStartItem($event, 'folder', folder.id)"
                    @dragend="onDragEndItem"
                    @dragover.prevent="onDragOverDropTarget($event, folder.id)"
                    @dragleave="dropTargetFolderId = undefined"
                    @drop.prevent.stop="onDropOnTarget($event, folder.id)"
                >
                    <FolderIcon class="h-12 w-12 text-amber-400" />
                    <p class="mt-2 w-full truncate text-sm font-medium text-gray-700">{{ folder.name }}</p>
                    <span
                        v-if="folder.folder_type === 'thesis'"
                        class="mt-1 rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-medium text-purple-700"
                    >
                        أطروحة
                    </span>

                    <div class="absolute end-2 top-2 opacity-0 group-hover:opacity-100">
                        <Dropdown align="right">
                            <template #trigger>
                                <button class="rounded-full p-1 hover:bg-gray-100" @click.stop>
                                    <EllipsisVerticalIcon class="h-5 w-5 text-gray-500" />
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink v-if="can.share" as="button" @click="shareTarget = { ...folder, type: 'folder' }">
                                    <ShareIcon class="me-2 inline h-4 w-4" /> مشاركة
                                </DropdownLink>
                                <DropdownLink v-if="can.rename_folder" as="button" @click="renameTarget = { ...folder, type: 'folder' }">
                                    <PencilIcon class="me-2 inline h-4 w-4" /> إعادة تسمية
                                </DropdownLink>
                                <DropdownLink v-if="can.move" as="button" @click="moveTarget = { ...folder, type: 'folder' }">
                                    <ArrowsRightLeftIcon class="me-2 inline h-4 w-4" /> نقل
                                </DropdownLink>
                                <DropdownLink v-if="can.copy" as="button" @click="copyOne('folder', folder.id)">
                                    <DocumentDuplicateIcon class="me-2 inline h-4 w-4" /> نسخ
                                </DropdownLink>
                                <DropdownLink v-if="can.delete_folder" as="button" @click="deleteOne('folder', folder.id)">
                                    <TrashIcon class="me-2 inline h-4 w-4 text-red-500" /> حذف
                                </DropdownLink>
                                <DropdownLink v-if="can.is_admin" as="button" @click="togglePublic(folder)">
                                    <GlobeAltIcon class="me-2 inline h-4 w-4" />
                                    {{ folder.is_public ? 'إلغاء المشاركة العامة' : 'جعله مجلداً عاماً (للزوار)' }}
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>
                </div>

                <div
                    v-for="file in files"
                    :key="`file-${file.id}`"
                    class="group relative flex cursor-pointer flex-col items-center rounded-xl border p-4 text-center hover:shadow-md"
                    :class="selected.files.has(file.id) ? 'border-[var(--accent-500)] bg-[var(--accent-50)]' : 'border-gray-200 bg-white'"
                    :draggable="can.move"
                    @click="toggleSelect('files', file.id, $event)"
                    @dblclick="openPreview(file)"
                    @dragstart="onDragStartItem($event, 'file', file.id)"
                    @dragend="onDragEndItem"
                >
                    <div class="flex h-16 w-16 items-center justify-center">
                        <FileThumbnail :file="file" :src="route('files.thumbnail', file.id)" icon-class="h-12 w-12" />
                    </div>
                    <p class="mt-2 flex w-full items-center justify-center gap-1 truncate text-sm font-medium text-gray-700">
                        <DocumentMagnifyingGlassIcon v-if="file.is_indexed" class="h-3.5 w-3.5 shrink-0 text-gray-400" title="مفهرس للبحث داخل المحتوى" />
                        <span class="truncate">{{ file.name }}</span>
                    </p>
                    <p class="text-xs text-gray-400">{{ file.human_size ?? '' }}</p>
                    <p v-if="file.content_snippet" class="mt-1 line-clamp-2 w-full text-start text-xs text-gray-500" dir="auto">
                        {{ file.content_snippet }}
                    </p>

                    <div class="absolute end-2 top-2 opacity-0 group-hover:opacity-100">
                        <Dropdown align="right">
                            <template #trigger>
                                <button class="rounded-full p-1 hover:bg-gray-100" @click.stop>
                                    <EllipsisVerticalIcon class="h-5 w-5 text-gray-500" />
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink v-if="can.download" :href="route('files.download', file.id)">
                                    <ArrowDownTrayIcon class="me-2 inline h-4 w-4" /> تنزيل
                                </DropdownLink>
                                <DropdownLink v-if="can.share" as="button" @click="shareTarget = { ...file, type: 'file' }">
                                    <ShareIcon class="me-2 inline h-4 w-4" /> مشاركة
                                </DropdownLink>
                                <DropdownLink v-if="can.rename" as="button" @click="renameTarget = { ...file, type: 'file' }">
                                    <PencilIcon class="me-2 inline h-4 w-4" /> إعادة تسمية
                                </DropdownLink>
                                <DropdownLink v-if="can.move" as="button" @click="moveTarget = { ...file, type: 'file' }">
                                    <ArrowsRightLeftIcon class="me-2 inline h-4 w-4" /> نقل
                                </DropdownLink>
                                <DropdownLink v-if="can.copy" as="button" @click="copyOne('file', file.id)">
                                    <DocumentDuplicateIcon class="me-2 inline h-4 w-4" /> نسخ
                                </DropdownLink>
                                <DropdownLink v-if="can.delete" as="button" @click="deleteOne('file', file.id)">
                                    <TrashIcon class="me-2 inline h-4 w-4 text-red-500" /> حذف
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload progress -->
        <div v-if="uploads.length" data-testid="upload-panel" class="fixed bottom-4 end-4 z-40 w-80 rounded-xl border border-gray-200 bg-white p-4 shadow-lg">
            <div class="mb-2 flex items-center justify-between">
                <p class="text-sm font-medium text-gray-700">جارِ الرفع</p>
                <button @click="clearFinished" class="text-xs text-gray-400 hover:underline">مسح المكتمل</button>
            </div>
            <div v-for="u in uploads" :key="u.id" class="mb-3 text-xs">
                <div class="flex items-center justify-between text-gray-600">
                    <span class="min-w-0 flex-1 truncate">{{ u.name }}</span>
                    <span class="ms-2 flex shrink-0 items-center gap-1.5">
                        <span v-if="u.status === 'uploading' || u.status === 'paused'">{{ u.progress }}%</span>
                        <span v-else-if="u.status === 'done'" class="text-emerald-600">تم</span>
                        <span v-else-if="u.status === 'error'" class="text-red-500">فشل</span>

                        <button v-if="u.status === 'uploading'" @click="pauseUpload(u)" title="إيقاف مؤقت" class="rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                            <PauseIcon class="h-3.5 w-3.5" />
                        </button>
                        <button v-else-if="u.status === 'paused' || u.status === 'error'" @click="resumeUpload(u)" title="استئناف" class="rounded p-0.5 text-[var(--accent-600)] hover:bg-gray-100">
                            <PlayIcon class="h-3.5 w-3.5" />
                        </button>
                        <button v-if="u.status !== 'done'" @click="cancelUpload(u)" title="إلغاء" class="rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-red-600">
                            <XMarkIcon class="h-3.5 w-3.5" />
                        </button>
                    </span>
                </div>
                <p v-if="u.status === 'error'" class="mt-0.5 text-red-500">{{ u.error }}</p>
                <div class="mt-1 h-1.5 rounded-full bg-gray-100">
                    <div
                        class="h-full rounded-full bg-[var(--accent-600)] transition-all"
                        :class="{ 'bg-red-500': u.status === 'error', 'bg-gray-400': u.status === 'paused' }"
                        :style="{ width: `${u.progress}%` }"
                    />
                </div>
            </div>
        </div>

        <NewFolderModal :show="newFolderModal" :parent-id="currentFolder?.id ?? null" @close="newFolderModal = false" />
        <RenameModal :show="!!renameTarget" :item="renameTarget" @close="renameTarget = null" />
        <MoveModal :show="!!moveTarget" :item="moveTarget" @close="moveTarget = null" />
        <ShareModal :show="!!shareTarget" :item="shareTarget" @close="shareTarget = null" />
        <PreviewModal :show="!!previewFile" :file="previewFile" :files="props.files" @close="previewFile = null" @change="previewFile = $event" />
    </AppLayout>
</template>
