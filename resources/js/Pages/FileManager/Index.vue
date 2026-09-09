<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import FileIcon from '@/Components/FileIcon.vue';
import NewFolderModal from '@/Components/NewFolderModal.vue';
import RenameModal from '@/Components/RenameModal.vue';
import MoveModal from '@/Components/MoveModal.vue';
import ShareModal from '@/Components/ShareModal.vue';
import PreviewModal from '@/Components/PreviewModal.vue';
import { useChunkedUpload } from '@/Composables/useChunkedUpload';
import {
    FolderIcon,
    FolderPlusIcon,
    ArrowUpTrayIcon,
    MagnifyingGlassIcon,
    EllipsisVerticalIcon,
    ArrowDownTrayIcon,
    TrashIcon,
    ShareIcon,
    PencilIcon,
    ArrowsRightLeftIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    currentFolder: Object,
    breadcrumbs: Array,
    folders: Array,
    files: Array,
    filters: Object,
});

const search = ref(props.filters.q ?? '');
const selected = reactive({ files: new Set(), folders: new Set() });
const fileInput = ref(null);
const dragOver = ref(false);

const newFolderModal = ref(false);
const renameTarget = ref(null);
const moveTarget = ref(null);
const shareTarget = ref(null);
const previewFile = ref(null);

const { uploads, uploadMany, clearFinished } = useChunkedUpload({
    onUploaded: () => router.reload({ only: ['folders', 'files'] }),
});

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

function onDrop(event) {
    dragOver.value = false;
    if (event.dataTransfer.files.length) {
        uploadMany(event.dataTransfer.files, props.currentFolder?.id ?? null);
    }
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
</script>

<template>
    <Head title="ملفاتي" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <nav class="flex min-w-0 items-center gap-1 text-sm text-gray-500">
                    <button @click="goToFolder(null)" class="shrink-0 hover:text-gray-900">ملفاتي</button>
                    <template v-for="crumb in breadcrumbs" :key="crumb.id">
                        <span>/</span>
                        <button @click="goToFolder(crumb.id)" class="truncate hover:text-gray-900">{{ crumb.name }}</button>
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
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="onDrop"
        >
            <div v-if="dragOver" class="absolute inset-4 z-10 flex items-center justify-center rounded-xl border-4 border-dashed border-[var(--accent-500)] bg-[var(--accent-50)]/80 text-lg font-medium text-[var(--accent-700)]">
                أفلت الملفات هنا للرفع
            </div>

            <!-- Toolbar -->
            <div class="mb-6 flex flex-wrap items-center gap-3">
                <button
                    @click="newFolderModal = true"
                    class="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    <FolderPlusIcon class="h-5 w-5" /> مجلد جديد
                </button>
                <button
                    @click="fileInput.click()"
                    class="flex items-center gap-2 rounded-lg bg-[var(--accent-600)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--accent-700)]"
                >
                    <ArrowUpTrayIcon class="h-5 w-5" /> رفع ملفات
                </button>
                <input ref="fileInput" type="file" multiple class="hidden" @change="onFilesChosen" />

                <select
                    :value="filters.sort"
                    @change="changeSort($event.target.value)"
                    class="rounded-lg border-gray-300 text-sm"
                >
                    <option value="name">ترتيب حسب الاسم</option>
                    <option value="created_at">ترتيب حسب التاريخ</option>
                    <option value="size">ترتيب حسب الحجم</option>
                </select>

                <div v-if="selectionCount" class="ms-auto flex items-center gap-2 rounded-lg bg-gray-100 px-3 py-1.5">
                    <span class="text-sm text-gray-600">{{ selectionCount }} محدد</span>
                    <button @click="downloadSelection" class="rounded-md p-1.5 hover:bg-gray-200" title="تنزيل كملف مضغوط">
                        <ArrowDownTrayIcon class="h-4 w-4" />
                    </button>
                    <button @click="deleteSelection" class="rounded-md p-1.5 hover:bg-gray-200" title="حذف">
                        <TrashIcon class="h-4 w-4 text-red-500" />
                    </button>
                    <button @click="clearSelection" class="text-sm text-gray-500 hover:underline">إلغاء التحديد</button>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="!folders.length && !files.length" class="flex flex-col items-center justify-center py-24 text-gray-400">
                <FolderIcon class="h-16 w-16" />
                <p class="mt-3">هذا المجلد فارغ. اسحب الملفات هنا أو استخدم زر "رفع ملفات".</p>
            </div>

            <!-- Grid -->
            <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                <div
                    v-for="folder in folders"
                    :key="`folder-${folder.id}`"
                    class="group relative flex cursor-pointer flex-col items-center rounded-xl border p-4 text-center hover:shadow-md"
                    :class="selected.folders.has(folder.id) ? 'border-[var(--accent-500)] bg-[var(--accent-50)]' : 'border-gray-200 bg-white'"
                    @click="toggleSelect('folders', folder.id, $event)"
                    @dblclick="goToFolder(folder.id)"
                >
                    <FolderIcon class="h-12 w-12 text-amber-400" />
                    <p class="mt-2 w-full truncate text-sm font-medium text-gray-700">{{ folder.name }}</p>

                    <div class="absolute end-2 top-2 opacity-0 group-hover:opacity-100">
                        <Dropdown align="right">
                            <template #trigger>
                                <button class="rounded-full p-1 hover:bg-gray-100" @click.stop>
                                    <EllipsisVerticalIcon class="h-5 w-5 text-gray-500" />
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink as="button" @click="shareTarget = { ...folder, type: 'folder' }">
                                    <ShareIcon class="me-2 inline h-4 w-4" /> مشاركة
                                </DropdownLink>
                                <DropdownLink as="button" @click="renameTarget = { ...folder, type: 'folder' }">
                                    <PencilIcon class="me-2 inline h-4 w-4" /> إعادة تسمية
                                </DropdownLink>
                                <DropdownLink as="button" @click="moveTarget = { ...folder, type: 'folder' }">
                                    <ArrowsRightLeftIcon class="me-2 inline h-4 w-4" /> نقل
                                </DropdownLink>
                                <DropdownLink as="button" @click="deleteOne('folder', folder.id)">
                                    <TrashIcon class="me-2 inline h-4 w-4 text-red-500" /> حذف
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
                    @click="toggleSelect('files', file.id, $event)"
                    @dblclick="openPreview(file)"
                >
                    <FileIcon :extension="file.extension" :mime-type="file.mime_type" class="h-12 w-12" />
                    <p class="mt-2 w-full truncate text-sm font-medium text-gray-700">{{ file.name }}</p>
                    <p class="text-xs text-gray-400">{{ file.human_size ?? '' }}</p>

                    <div class="absolute end-2 top-2 opacity-0 group-hover:opacity-100">
                        <Dropdown align="right">
                            <template #trigger>
                                <button class="rounded-full p-1 hover:bg-gray-100" @click.stop>
                                    <EllipsisVerticalIcon class="h-5 w-5 text-gray-500" />
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink :href="route('files.download', file.id)">
                                    <ArrowDownTrayIcon class="me-2 inline h-4 w-4" /> تنزيل
                                </DropdownLink>
                                <DropdownLink as="button" @click="shareTarget = { ...file, type: 'file' }">
                                    <ShareIcon class="me-2 inline h-4 w-4" /> مشاركة
                                </DropdownLink>
                                <DropdownLink as="button" @click="renameTarget = { ...file, type: 'file' }">
                                    <PencilIcon class="me-2 inline h-4 w-4" /> إعادة تسمية
                                </DropdownLink>
                                <DropdownLink as="button" @click="moveTarget = { ...file, type: 'file' }">
                                    <ArrowsRightLeftIcon class="me-2 inline h-4 w-4" /> نقل
                                </DropdownLink>
                                <DropdownLink as="button" @click="deleteOne('file', file.id)">
                                    <TrashIcon class="me-2 inline h-4 w-4 text-red-500" /> حذف
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload progress -->
        <div v-if="uploads.length" class="fixed bottom-4 end-4 z-40 w-80 rounded-xl border border-gray-200 bg-white p-4 shadow-lg">
            <div class="mb-2 flex items-center justify-between">
                <p class="text-sm font-medium text-gray-700">جارِ الرفع</p>
                <button @click="clearFinished" class="text-xs text-gray-400 hover:underline">مسح المكتمل</button>
            </div>
            <div v-for="u in uploads" :key="u.id" class="mb-2 text-xs">
                <div class="flex justify-between text-gray-600">
                    <span class="truncate">{{ u.name }}</span>
                    <span v-if="u.status === 'uploading'">{{ u.progress }}%</span>
                    <span v-else-if="u.status === 'done'" class="text-emerald-600">تم</span>
                    <span v-else class="text-red-500">{{ u.error }}</span>
                </div>
                <div class="mt-1 h-1.5 rounded-full bg-gray-100">
                    <div
                        class="h-full rounded-full bg-[var(--accent-600)] transition-all"
                        :class="{ 'bg-red-500': u.status === 'error' }"
                        :style="{ width: `${u.progress}%` }"
                    />
                </div>
            </div>
        </div>

        <NewFolderModal :show="newFolderModal" :parent-id="currentFolder?.id ?? null" @close="newFolderModal = false" />
        <RenameModal :show="!!renameTarget" :item="renameTarget" @close="renameTarget = null" />
        <MoveModal :show="!!moveTarget" :item="moveTarget" @close="moveTarget = null" />
        <ShareModal :show="!!shareTarget" :item="shareTarget" @close="shareTarget = null" />
        <PreviewModal :show="!!previewFile" :file="previewFile" @close="previewFile = null" />
    </AppLayout>
</template>
