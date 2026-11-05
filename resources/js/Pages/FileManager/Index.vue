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
import AddToPlaylistModal from '@/Components/AddToPlaylistModal.vue';
import PreviewModal from '@/Components/PreviewModal.vue';
import { useChunkedUpload } from '@/Composables/useChunkedUpload';
import { useTrans } from '@/Composables/useTrans';
import {
    FolderIcon,
    FolderPlusIcon,
    FolderArrowDownIcon,
    ArrowUpTrayIcon,
    LinkIcon,
    MagnifyingGlassIcon,
    HomeIcon,
    ChevronLeftIcon as CrumbSepIcon,
    EllipsisVerticalIcon,
    ArrowDownTrayIcon,
    TrashIcon,
    ShareIcon,
    PencilIcon,
    ArrowsRightLeftIcon,
    DocumentDuplicateIcon,
    GlobeAltIcon,
    QueueListIcon,
    DocumentMagnifyingGlassIcon,
    InformationCircleIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    PauseIcon,
    PlayIcon,
    XMarkIcon,
    ArrowPathIcon,
    Squares2X2Icon,
    ListBulletIcon,
    CheckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    currentFolder: Object,
    breadcrumbs: Array,
    folders: Array,
    files: Array,
    filters: Object,
});

const { t } = useTrans();
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
const playlistTarget = ref(null);
const previewFile = ref(null);

function isMediaFile(file) {
    return /^(video|audio)\//.test(file.mime_type ?? '');
}

// ---- Folder info panel (type + researcher/supervisor/thesis/publisher/etc.) ----
const showFolderInfo = ref(false);
const folderInfoFields = ['researcher_name', 'degree', 'supervisor_name', 'thesis_title', 'publisher', 'digital_library', 'publication_year'];

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
        alert(t('upload.resume_mismatch', 'الملف الذي اخترته لا يطابق اسم وحجم الرفع المتوقّف. الرجاء اختيار نفس الملف.'));
    }
    resumeTarget.value = null;
}

function goToFolder(id) {
    clearSelection();
    router.get(route('dashboard'), { folder: id }, { preserveState: true });
}

function runSearch() {
    router.get(route('dashboard'), { q: search.value, folder: props.currentFolder?.id }, { preserveState: true });
}

function changeSort(sort) {
    router.get(route('dashboard'), { ...props.filters, sort, folder: props.currentFolder?.id }, { preserveState: true });
}

// Click opens a folder / previews a file. Once something is selected (tick box, Ctrl/Cmd/Shift
// click, or "select all"), clicks add and remove items instead, which works the same on phones.
function toggleSelect(type, id) {
    const set = selected[type];
    set.has(id) ? set.delete(id) : set.add(id);
}

function onItemClick(type, item, event) {
    const key = type === 'file' ? 'files' : 'folders';
    if (selectionCount.value > 0 || event.ctrlKey || event.metaKey || event.shiftKey) {
        toggleSelect(key, item.id);
        return;
    }
    type === 'file' ? openPreview(item) : goToFolder(item.id);
}

const selectionCount = computed(() => selected.files.size + selected.folders.size);
const totalItems = computed(() => props.folders.length + props.files.length);
const allSelected = computed(() => totalItems.value > 0 && selectionCount.value === totalItems.value);

function clearSelection() {
    selected.files.clear();
    selected.folders.clear();
}

function toggleSelectAll() {
    if (allSelected.value) {
        clearSelection();
        return;
    }
    props.folders.forEach((f) => selected.folders.add(f.id));
    props.files.forEach((f) => selected.files.add(f.id));
}

// Folder contents as cards (grid) or as a list; remembered on this device.
const viewMode = ref((() => {
    try {
        return localStorage.getItem('filenest.viewMode') === 'list' ? 'list' : 'grid';
    } catch {
        return 'grid';
    }
})());
const isList = computed(() => viewMode.value === 'list');

function setViewMode(mode) {
    viewMode.value = mode;
    try {
        localStorage.setItem('filenest.viewMode', mode);
    } catch {
        // Private mode: the choice just isn't remembered.
    }
}

function formatDate(value) {
    if (!value) return '';
    try {
        return new Date(value).toLocaleDateString(page.props.appSettings?.locale === 'en' ? 'en-GB' : 'ar-IQ', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch {
        return '';
    }
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
    if (!confirm(t('confirm.delete_selection', 'هل أنت متأكد من نقل العناصر المحددة إلى سلة المحذوفات؟'))) return;
    selected.files.forEach((id) => router.delete(route('files.destroy', id), { preserveScroll: true }));
    selected.folders.forEach((id) => router.delete(route('folders.destroy', id), { preserveScroll: true }));
    clearSelection();
}

function deleteOne(type, id) {
    if (!confirm(t('confirm.delete_one', 'هل أنت متأكد من نقل هذا العنصر إلى سلة المحذوفات؟'))) return;
    router.delete(type === 'file' ? route('files.destroy', id) : route('folders.destroy', id), { preserveScroll: true });
}

function openPreview(file) {
    previewFile.value = file;
}

function togglePublic(folder) {
    router.put(route('folders.update', folder.id), { is_public: !folder.is_public }, { preserveScroll: true });
}

function copyPublicLink(folder) {
    const url = route('guest.show', folder.id);
    const done = () => window.alert(t('folder.public_link_copied', 'تم نسخ رابط المجلد العام:') + '\n' + url);
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(url).then(done, () => window.prompt(t('folder.public_link', 'رابط المجلد العام'), url));
    } else {
        window.prompt(t('folder.public_link', 'رابط المجلد العام'), url);
    }
}

function copyOne(type, id) {
    const url = type === 'file' ? route('files.copy', id) : route('folders.copy', id);
    router.post(url, { folder_id: props.currentFolder?.id ?? null, parent_id: props.currentFolder?.id ?? null }, { preserveScroll: true });
}

function uploadFromUrl() {
    const url = window.prompt(t('upload.from_url_prompt', 'أدخل رابط الملف المراد رفعه:'));
    if (!url) return;

    router.post(
        route('uploads.remote'),
        { url, folder_id: props.currentFolder?.id ?? null },
        { preserveScroll: true, onSuccess: () => router.reload({ only: ['folders', 'files'] }) },
    );
}
</script>

<template>
    <Head :title="t('nav.dashboard', 'ملفاتي')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <nav class="hidden min-w-0 items-center gap-1 text-sm text-gray-500 sm:flex dark:text-gray-400">
                    <button
                        @click="goToFolder(null)"
                        @dragover.prevent="onDragOverDropTarget($event, null)"
                        @dragleave="dropTargetFolderId = undefined"
                        @drop.prevent.stop="onDropOnTarget($event, null)"
                        class="shrink-0 rounded px-1 hover:text-gray-900 dark:hover:text-gray-100"
                        :class="{ 'bg-[var(--accent-100)] text-[var(--accent-700)] ring-2 ring-[var(--accent-400)]': dropTargetFolderId === null }"
                    >
                        {{ t('nav.dashboard', 'ملفاتي') }}
                    </button>
                    <template v-for="crumb in breadcrumbs" :key="crumb.id">
                        <span>/</span>
                        <button
                            @click="goToFolder(crumb.id)"
                            @dragover.prevent="onDragOverDropTarget($event, crumb.id)"
                            @dragleave="dropTargetFolderId = undefined"
                            @drop.prevent.stop="onDropOnTarget($event, crumb.id)"
                            class="truncate rounded px-1 hover:text-gray-900 dark:hover:text-gray-100"
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
                            :placeholder="t('action.search', 'بحث') + '...'"
                            class="w-48 rounded-full border-gray-300 py-1.5 ps-8 text-sm focus:border-[var(--accent-500)] focus:ring-[var(--accent-500)] dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:placeholder-gray-400"
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
            <!-- Folder path on phones: الرئيسية / المجلد / ... each part opens that folder -->
            <nav class="-mx-1 mb-4 flex items-center gap-1 overflow-x-auto whitespace-nowrap rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600 sm:hidden dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                <button
                    @click="goToFolder(null)"
                    class="flex shrink-0 items-center gap-1 rounded px-1 py-0.5"
                    :class="breadcrumbs.length ? 'text-[var(--accent-600)] hover:underline' : 'font-semibold text-gray-900 dark:text-gray-100'"
                >
                    <HomeIcon class="h-4 w-4" />
                    {{ t('guest.home', 'الرئيسية') }}
                </button>
                <template v-for="(crumb, i) in breadcrumbs" :key="'m' + crumb.id">
                    <CrumbSepIcon class="h-3.5 w-3.5 shrink-0 text-gray-400 ltr:rotate-180" />
                    <button
                        @click="goToFolder(crumb.id)"
                        class="shrink-0 rounded px-1 py-0.5"
                        :class="i < breadcrumbs.length - 1 ? 'text-[var(--accent-600)] hover:underline' : 'font-semibold text-gray-900 dark:text-gray-100'"
                    >
                        {{ crumb.name }}
                    </button>
                </template>
            </nav>

            <div v-if="dragOver" class="absolute inset-4 z-10 flex items-center justify-center rounded-xl border-4 border-dashed border-[var(--accent-500)] bg-[var(--accent-50)]/80 text-lg font-medium text-[var(--accent-700)] dark:bg-gray-800/90">
                {{ t('upload.drop_here', 'أفلت الملفات هنا للرفع') }}
            </div>

            <!-- Uploads left unfinished by a previous page load/crash -->
            <div v-if="interrupted.length" class="mb-4 space-y-2">
                <div
                    v-for="pending in interrupted"
                    :key="pending.identifier"
                    class="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
                >
                    <ArrowPathIcon class="h-5 w-5 shrink-0" />
                    <span class="min-w-0 flex-1 truncate">
                        {{ t('upload.resume_pending', 'رفع لم يكتمل لـ ":name" — أعد اختيار الملف نفسه للاستكمال من حيث توقّف.', { name: pending.name }) }}
                    </span>
                    <button @click="pickFileToResume(pending)" class="shrink-0 rounded-md bg-amber-600 px-3 py-1 font-medium text-white hover:bg-amber-700">
                        {{ t('upload.resume', 'استكمال') }}
                    </button>
                    <button @click="dismissInterrupted(pending)" class="shrink-0 text-amber-500 hover:text-amber-700">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>
            </div>
            <input ref="resumeFileInput" data-testid="resume-file-input" type="file" class="hidden" @change="onResumeFileChosen" />

            <!-- Folder info: researcher/supervisor/thesis/publisher/etc. -->
            <div v-if="currentFolder" class="mb-6 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <button
                    data-testid="folder-info-toggle"
                    @click="showFolderInfo = !showFolderInfo"
                    class="flex w-full items-center justify-between px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    <span class="flex items-center gap-2">
                        <InformationCircleIcon class="h-5 w-5 text-gray-400" /> {{ t('folder.info', 'معلومات المجلد') }}
                    </span>
                    <ChevronUpIcon v-if="showFolderInfo" class="h-4 w-4 text-gray-400" />
                    <ChevronDownIcon v-else class="h-4 w-4 text-gray-400" />
                </button>
                <form
                    v-if="showFolderInfo"
                    data-testid="folder-info-form"
                    @submit.prevent="saveFolderInfo"
                    class="grid grid-cols-1 gap-4 border-t border-gray-100 p-4 sm:grid-cols-2 lg:grid-cols-3 dark:border-gray-700"
                >
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('folder.type', 'نوع المجلد') }}</label>
                        <div class="flex gap-4 text-sm text-gray-700 dark:text-gray-300">
                            <label class="flex items-center gap-1.5">
                                <input
                                    v-model="folderInfoForm.folder_type"
                                    type="radio"
                                    value="files"
                                    :disabled="!can.rename_folder"
                                    class="border-gray-300 text-[var(--accent-600)] focus:ring-[var(--accent-500)]"
                                />
                                {{ t('folder.type_files', 'ملفات') }}
                            </label>
                            <label class="flex items-center gap-1.5">
                                <input
                                    v-model="folderInfoForm.folder_type"
                                    type="radio"
                                    value="thesis"
                                    :disabled="!can.rename_folder"
                                    class="border-gray-300 text-[var(--accent-600)] focus:ring-[var(--accent-500)]"
                                />
                                {{ t('folder.type_thesis', 'أطروحة') }}
                            </label>
                        </div>
                    </div>
                    <template v-if="folderInfoForm.folder_type === 'thesis'">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('folder.researcher_name', 'اسم الباحث') }}</label>
                            <input v-model="folderInfoForm.researcher_name" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:disabled:bg-gray-800" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('folder.degree', 'الدرجة') }}</label>
                            <select v-model="folderInfoForm.degree" :disabled="!can.rename_folder" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:disabled:bg-gray-800">
                                <option value="">{{ t('folder.degree_none', '— غير محددة —') }}</option>
                                <option value="diploma">{{ t('folder.degree_diploma', 'دبلوم عالي') }}</option>
                                <option value="master">{{ t('folder.degree_master', 'ماجستير') }}</option>
                                <option value="phd">{{ t('folder.degree_phd', 'دكتوراه') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('folder.supervisor_name', 'المشرف') }}</label>
                            <input v-model="folderInfoForm.supervisor_name" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:disabled:bg-gray-800" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('folder.thesis_title', 'عنوان الرسالة') }}</label>
                            <input v-model="folderInfoForm.thesis_title" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:disabled:bg-gray-800" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('folder.publisher', 'دار النشر') }}</label>
                            <input v-model="folderInfoForm.publisher" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:disabled:bg-gray-800" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('folder.digital_library', 'المكتبة الرقمية') }}</label>
                            <input v-model="folderInfoForm.digital_library" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:disabled:bg-gray-800" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('folder.publication_year', 'سنة النشر') }}</label>
                            <input v-model="folderInfoForm.publication_year" :disabled="!can.rename_folder" type="text" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:disabled:bg-gray-800" />
                        </div>
                    </template>
                    <div v-if="can.rename_folder" class="sm:col-span-2 lg:col-span-3">
                        <button
                            type="submit"
                            :disabled="folderInfoForm.processing"
                            class="rounded-md bg-[var(--accent-600)] px-4 py-1.5 text-sm font-medium text-white hover:bg-[var(--accent-700)] disabled:opacity-50"
                        >
                            {{ t('action.save', 'حفظ') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Toolbar -->
            <div class="mb-6 flex flex-wrap items-center gap-3">
                <button
                    v-if="can.new_folder"
                    @click="newFolderModal = true"
                    class="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    <FolderPlusIcon class="h-5 w-5" /> {{ t('action.new_folder', 'مجلد جديد') }}
                </button>
                <button
                    v-if="can.upload"
                    @click="fileInput.click()"
                    class="flex items-center gap-2 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)]"
                >
                    <ArrowUpTrayIcon class="h-5 w-5" /> {{ t('action.upload', 'رفع ملفات') }}
                </button>
                <button
                    v-if="can.upload"
                    @click="folderInput.click()"
                    class="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    <FolderArrowDownIcon class="h-5 w-5" /> {{ t('action.upload_folder', 'رفع مجلد') }}
                </button>
                <button
                    v-if="can.upload && remoteUploadEnabled"
                    @click="uploadFromUrl"
                    class="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    <LinkIcon class="h-5 w-5" /> {{ t('action.upload_remote', 'رفع من رابط') }}
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
                    class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                >
                    <option value="name">{{ t('sort.name', 'ترتيب حسب الاسم') }}</option>
                    <option value="created_at">{{ t('sort.date', 'ترتيب حسب التاريخ') }}</option>
                    <option value="size">{{ t('sort.size', 'ترتيب حسب الحجم') }}</option>
                </select>

                <span class="text-sm text-gray-500 dark:text-gray-400">{{ t('folder.count_summary', ':folders مجلد، :files ملف', { folders: folders.length, files: files.length }) }}</span>

                <div class="flex items-center gap-2" :class="selectionCount ? '' : 'ms-auto'">
                    <button
                        v-if="totalItems"
                        @click="toggleSelectAll"
                        class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        <CheckIcon class="h-4 w-4" />
                        {{ allSelected ? t('selection.none', 'إلغاء تحديد الكل') : t('selection.all', 'تحديد الكل') }}
                    </button>
                    <div class="flex overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600">
                        <button
                            @click="setViewMode('grid')"
                            class="p-1.5"
                            :class="!isList ? 'bg-[var(--accent-600)] text-white' : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700'"
                            :title="t('view.grid', 'عرض كبطاقات')"
                        >
                            <Squares2X2Icon class="h-5 w-5" />
                        </button>
                        <button
                            @click="setViewMode('list')"
                            class="p-1.5"
                            :class="isList ? 'bg-[var(--accent-600)] text-white' : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700'"
                            :title="t('view.list', 'عرض كقائمة')"
                        >
                            <ListBulletIcon class="h-5 w-5" />
                        </button>
                    </div>
                </div>

                <div v-if="selectionCount" class="ms-auto flex w-full flex-wrap items-center gap-2 rounded-lg bg-[var(--accent-50)] px-3 py-2 sm:w-auto dark:bg-gray-700">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ t('selection.count', ':count محدد', { count: selectionCount }) }}</span>
                    <button
                        v-if="can.download"
                        @click="downloadSelection"
                        class="flex items-center gap-1 rounded-md bg-[var(--accent-600)] px-2.5 py-1 text-sm text-white hover:opacity-90"
                    >
                        <ArrowDownTrayIcon class="h-4 w-4" /> {{ t('action.download_zip', 'تنزيل كملف مضغوط') }}
                    </button>
                    <button v-if="can.delete || can.delete_folder" @click="deleteSelection" class="rounded-md p-1.5 hover:bg-gray-200 dark:hover:bg-gray-600" :title="t('action.delete', 'حذف')">
                        <TrashIcon class="h-4 w-4 text-red-500" />
                    </button>
                    <button @click="clearSelection" class="text-sm text-gray-500 hover:underline dark:text-gray-400">{{ t('selection.clear', 'إلغاء التحديد') }}</button>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="!folders.length && !files.length" class="flex flex-col items-center justify-center py-24 text-gray-400">
                <FolderIcon class="h-16 w-16" />
                <p class="mt-3">{{ t('empty.no_files', 'لا توجد ملفات أو مجلدات هنا') }}</p>
            </div>

            <!-- Grid -->
            <div
                v-else
                :class="isList
                    ? 'flex flex-col divide-y divide-gray-100 overflow-visible rounded-xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800'
                    : 'grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6'"
            >
                <div
                    v-for="folder in folders"
                    :key="`folder-${folder.id}`"
                    class="group relative flex cursor-pointer"
                    :class="[
                        isList ? 'items-center gap-3 px-3 py-2.5 pe-12' : 'flex-col items-center rounded-xl border p-4 text-center hover:shadow-md',
                        selected.folders.has(folder.id)
                            ? (isList ? 'bg-[var(--accent-50)] dark:bg-gray-700' : 'border-[var(--accent-500)] bg-[var(--accent-50)] dark:bg-gray-700')
                            : (isList ? 'hover:bg-gray-50 dark:hover:bg-gray-700/50' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'),
                        dropTargetFolderId === folder.id ? 'ring-2 ring-[var(--accent-500)]' : '',
                    ]"
                    :draggable="can.move"
                    @click="onItemClick('folder', folder, $event)"
                    @dragstart="onDragStartItem($event, 'folder', folder.id)"
                    @dragend="onDragEndItem"
                    @dragover.prevent="onDragOverDropTarget($event, folder.id)"
                    @dragleave="dropTargetFolderId = undefined"
                    @drop.prevent.stop="onDropOnTarget($event, folder.id)"
                >
                    <button
                        type="button"
                        class="flex h-5 w-5 shrink-0 items-center justify-center rounded border transition"
                        :class="[
                            selected.folders.has(folder.id) ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white' : 'border-gray-300 bg-white dark:border-gray-500 dark:bg-gray-800',
                            isList ? '' : 'absolute start-2 top-2',
                        ]"
                        :aria-label="t('selection.select', 'تحديد')"
                        @click.stop="toggleSelect('folders', folder.id)"
                    >
                        <CheckIcon v-if="selected.folders.has(folder.id)" class="h-3.5 w-3.5" />
                    </button>
                    <FolderIcon class="shrink-0 text-amber-400" :class="isList ? 'h-7 w-7' : 'h-12 w-12'" />
                    <p class="truncate text-sm font-medium text-gray-700 dark:text-gray-200" :class="isList ? 'min-w-0 flex-1 text-start' : 'mt-2 w-full'">{{ folder.name }}</p>
                    <span v-if="isList" class="hidden shrink-0 text-xs text-gray-400 sm:inline">{{ formatDate(folder.created_at) }}</span>
                    <span
                        v-if="folder.folder_type === 'thesis'"
                        class="mt-1 rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-medium text-purple-700 dark:bg-purple-900/40 dark:text-purple-300"
                    >
                        {{ t('folder.type_thesis', 'أطروحة') }}
                    </span>

                    <div class="absolute end-2" :class="isList ? 'top-1/2 -translate-y-1/2' : 'top-2'" @click.stop>
                        <Dropdown align="right">
                            <template #trigger>
                                <button class="rounded-full p-1 hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <EllipsisVerticalIcon class="h-5 w-5 text-gray-500" />
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink v-if="can.share" as="button" @click="shareTarget = { ...folder, type: 'folder' }">
                                    <ShareIcon class="me-2 inline h-4 w-4" /> {{ t('action.share', 'مشاركة') }}
                                </DropdownLink>
                                <DropdownLink v-if="can.rename_folder" as="button" @click="renameTarget = { ...folder, type: 'folder' }">
                                    <PencilIcon class="me-2 inline h-4 w-4" /> {{ t('action.rename', 'إعادة تسمية') }}
                                </DropdownLink>
                                <DropdownLink v-if="can.move" as="button" @click="moveTarget = { ...folder, type: 'folder' }">
                                    <ArrowsRightLeftIcon class="me-2 inline h-4 w-4" /> {{ t('action.move', 'نقل') }}
                                </DropdownLink>
                                <DropdownLink v-if="can.copy" as="button" @click="copyOne('folder', folder.id)">
                                    <DocumentDuplicateIcon class="me-2 inline h-4 w-4" /> {{ t('action.copy', 'نسخ') }}
                                </DropdownLink>
                                <DropdownLink v-if="can.delete_folder" as="button" @click="deleteOne('folder', folder.id)">
                                    <TrashIcon class="me-2 inline h-4 w-4 text-red-500" /> {{ t('action.delete', 'حذف') }}
                                </DropdownLink>
                                <DropdownLink v-if="can.is_admin" as="button" @click="togglePublic(folder)">
                                    <GlobeAltIcon class="me-2 inline h-4 w-4" />
                                    {{ folder.is_public ? t('folder.unpublish', 'إلغاء المشاركة العامة') : t('folder.publish', 'جعله مجلداً عاماً (للزوار)') }}
                                </DropdownLink>
                                <DropdownLink v-if="folder.is_public" as="button" @click="copyPublicLink(folder)">
                                    <LinkIcon class="me-2 inline h-4 w-4" /> {{ t('folder.public_link', 'رابط المجلد العام') }}
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>
                </div>

                <div
                    v-for="file in files"
                    :key="`file-${file.id}`"
                    class="group relative flex cursor-pointer"
                    :class="[
                        isList ? 'items-center gap-3 px-3 py-2.5 pe-12' : 'flex-col items-center rounded-xl border p-4 text-center hover:shadow-md',
                        selected.files.has(file.id)
                            ? (isList ? 'bg-[var(--accent-50)] dark:bg-gray-700' : 'border-[var(--accent-500)] bg-[var(--accent-50)] dark:bg-gray-700')
                            : (isList ? 'hover:bg-gray-50 dark:hover:bg-gray-700/50' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'),
                    ]"
                    :draggable="can.move"
                    @click="onItemClick('file', file, $event)"
                    @dragstart="onDragStartItem($event, 'file', file.id)"
                    @dragend="onDragEndItem"
                >
                    <button
                        type="button"
                        class="z-10 flex h-5 w-5 shrink-0 items-center justify-center rounded border transition"
                        :class="[
                            selected.files.has(file.id) ? 'border-[var(--accent-600)] bg-[var(--accent-600)] text-white' : 'border-gray-300 bg-white dark:border-gray-500 dark:bg-gray-800',
                            isList ? '' : 'absolute start-2 top-2',
                        ]"
                        :aria-label="t('selection.select', 'تحديد')"
                        @click.stop="toggleSelect('files', file.id)"
                    >
                        <CheckIcon v-if="selected.files.has(file.id)" class="h-3.5 w-3.5" />
                    </button>
                    <div class="flex shrink-0 items-center justify-center" :class="isList ? 'h-9 w-9' : 'h-16 w-16'">
                        <FileThumbnail :file="file" :src="route('files.thumbnail', file.id)" :icon-class="isList ? 'h-7 w-7' : 'h-12 w-12'" />
                    </div>
                    <div :class="isList ? 'min-w-0 flex-1 text-start' : 'w-full'">
                        <p class="flex items-center gap-1 truncate text-sm font-medium text-gray-700 dark:text-gray-200" :class="isList ? '' : 'mt-2 w-full justify-center'">
                            <DocumentMagnifyingGlassIcon v-if="file.is_indexed" class="h-3.5 w-3.5 shrink-0 text-gray-400" :title="t('file.indexed', 'مفهرس للبحث داخل المحتوى')" />
                            <span class="truncate">{{ file.name }}</span>
                        </p>
                        <p v-if="!isList" class="text-xs text-gray-400">{{ file.human_size ?? '' }}</p>
                        <p v-if="file.content_snippet" class="mt-1 line-clamp-2 w-full text-start text-xs text-gray-500 dark:text-gray-400" dir="auto">
                            {{ file.content_snippet }}
                        </p>
                    </div>
                    <span v-if="isList" class="shrink-0 text-xs text-gray-400">{{ file.human_size ?? '' }}</span>
                    <span v-if="isList" class="hidden w-24 shrink-0 text-xs text-gray-400 sm:inline">{{ formatDate(file.created_at) }}</span>

                    <div class="absolute end-2" :class="isList ? 'top-1/2 -translate-y-1/2' : 'top-2'" @click.stop>
                        <Dropdown align="right">
                            <template #trigger>
                                <button class="rounded-full p-1 hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <EllipsisVerticalIcon class="h-5 w-5 text-gray-500" />
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink v-if="can.download" :href="route('files.download', file.id)">
                                    <ArrowDownTrayIcon class="me-2 inline h-4 w-4" /> {{ t('action.download', 'تنزيل') }}
                                </DropdownLink>
                                <DropdownLink v-if="can.share" as="button" @click="shareTarget = { ...file, type: 'file' }">
                                    <ShareIcon class="me-2 inline h-4 w-4" /> {{ t('action.share', 'مشاركة') }}
                                </DropdownLink>
                                <DropdownLink v-if="isMediaFile(file)" as="button" @click="playlistTarget = { ...file, type: 'file' }">
                                    <QueueListIcon class="me-2 inline h-4 w-4" /> {{ t('action.add_to_playlist', 'إضافة إلى قائمة تشغيل') }}
                                </DropdownLink>
                                <DropdownLink v-if="can.rename" as="button" @click="renameTarget = { ...file, type: 'file' }">
                                    <PencilIcon class="me-2 inline h-4 w-4" /> {{ t('action.rename', 'إعادة تسمية') }}
                                </DropdownLink>
                                <DropdownLink v-if="can.move" as="button" @click="moveTarget = { ...file, type: 'file' }">
                                    <ArrowsRightLeftIcon class="me-2 inline h-4 w-4" /> {{ t('action.move', 'نقل') }}
                                </DropdownLink>
                                <DropdownLink v-if="can.copy" as="button" @click="copyOne('file', file.id)">
                                    <DocumentDuplicateIcon class="me-2 inline h-4 w-4" /> {{ t('action.copy', 'نسخ') }}
                                </DropdownLink>
                                <DropdownLink v-if="can.delete" as="button" @click="deleteOne('file', file.id)">
                                    <TrashIcon class="me-2 inline h-4 w-4 text-red-500" /> {{ t('action.delete', 'حذف') }}
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload progress -->
        <div v-if="uploads.length" data-testid="upload-panel" class="fixed bottom-4 end-4 z-40 w-80 rounded-xl border border-gray-200 bg-white p-4 shadow-lg dark:border-gray-700 dark:bg-gray-800">
            <div class="mb-2 flex items-center justify-between">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ t('upload.in_progress', 'جارِ الرفع') }}</p>
                <button @click="clearFinished" class="text-xs text-gray-400 hover:underline">{{ t('upload.clear_finished', 'مسح المكتمل') }}</button>
            </div>
            <div v-for="u in uploads" :key="u.id" class="mb-3 text-xs">
                <div class="flex items-center justify-between text-gray-600 dark:text-gray-300">
                    <span class="min-w-0 flex-1 truncate">{{ u.name }}</span>
                    <span class="ms-2 flex shrink-0 items-center gap-1.5">
                        <span v-if="u.status === 'uploading' || u.status === 'paused'">{{ u.progress }}%</span>
                        <span v-else-if="u.status === 'done'" class="text-emerald-600 dark:text-emerald-400">{{ t('upload.done', 'تم') }}</span>
                        <span v-else-if="u.status === 'error'" class="text-red-500">{{ t('upload.failed', 'فشل') }}</span>

                        <button v-if="u.status === 'uploading'" @click="pauseUpload(u)" :title="t('upload.pause', 'إيقاف مؤقت')" class="rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200">
                            <PauseIcon class="h-3.5 w-3.5" />
                        </button>
                        <button v-else-if="u.status === 'paused' || u.status === 'error'" @click="resumeUpload(u)" :title="t('upload.resume', 'استئناف')" class="rounded p-0.5 text-[var(--accent-600)] hover:bg-gray-100 dark:hover:bg-gray-700">
                            <PlayIcon class="h-3.5 w-3.5" />
                        </button>
                        <button v-if="u.status !== 'done'" @click="cancelUpload(u)" :title="t('action.cancel', 'إلغاء')" class="rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-700">
                            <XMarkIcon class="h-3.5 w-3.5" />
                        </button>
                    </span>
                </div>
                <p v-if="u.status === 'error'" class="mt-0.5 text-red-500">{{ u.error }}</p>
                <div class="mt-1 h-1.5 rounded-full bg-gray-100 dark:bg-gray-700">
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
        <AddToPlaylistModal :show="!!playlistTarget" :item="playlistTarget" @close="playlistTarget = null" />
        <PreviewModal :show="!!previewFile" :file="previewFile" :files="props.files" @close="previewFile = null" @change="previewFile = $event" />
    </AppLayout>
</template>
