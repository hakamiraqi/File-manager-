<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FileIcon from '@/Components/FileIcon.vue';
import { FolderIcon, ArrowUturnLeftIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    folders: Array,
    files: Array,
    retentionDays: Number,
});

function restore(type, id) {
    router.post(route('trash.restore'), { type, id }, { preserveScroll: true });
}

function forceDelete(type, id) {
    if (!confirm('سيتم حذف هذا العنصر نهائياً ولا يمكن التراجع عن ذلك. متابعة؟')) return;
    router.delete(route('trash.force-delete', { type, id }), { preserveScroll: true });
}

function emptyTrash() {
    if (!confirm('سيتم إفراغ سلة المحذوفات بالكامل بشكل نهائي. متابعة؟')) return;
    router.delete(route('trash.empty'), { preserveScroll: true });
}
</script>

<template>
    <Head title="سلة المحذوفات" />

    <AppLayout>
        <template #header>
            <h2 class="text-lg font-semibold text-gray-800">سلة المحذوفات</h2>
        </template>

        <div class="p-6">
            <p class="mb-4 text-sm text-gray-500">
                يتم حذف العناصر نهائياً تلقائياً بعد {{ retentionDays }} يوماً من نقلها إلى سلة المحذوفات.
            </p>

            <button
                v-if="folders.length || files.length"
                @click="emptyTrash"
                class="mb-4 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
            >
                إفراغ سلة المحذوفات
            </button>

            <div v-if="!folders.length && !files.length" class="py-24 text-center text-gray-400">
                سلة المحذوفات فارغة.
            </div>

            <ul v-else class="divide-y divide-gray-200 rounded-xl border border-gray-200 bg-white">
                <li v-for="folder in folders" :key="`folder-${folder.id}`" class="flex items-center gap-3 p-4">
                    <FolderIcon class="h-6 w-6 shrink-0 text-amber-400" />
                    <span class="flex-1 truncate">{{ folder.name }}</span>
                    <button @click="restore('folder', folder.id)" class="rounded-md p-2 hover:bg-gray-100" title="استعادة">
                        <ArrowUturnLeftIcon class="h-5 w-5" />
                    </button>
                    <button @click="forceDelete('folder', folder.id)" class="rounded-md p-2 hover:bg-gray-100" title="حذف نهائي">
                        <TrashIcon class="h-5 w-5 text-red-500" />
                    </button>
                </li>
                <li v-for="file in files" :key="`file-${file.id}`" class="flex items-center gap-3 p-4">
                    <FileIcon :extension="file.extension" class="h-6 w-6 shrink-0" />
                    <span class="flex-1 truncate">{{ file.name }}</span>
                    <button @click="restore('file', file.id)" class="rounded-md p-2 hover:bg-gray-100" title="استعادة">
                        <ArrowUturnLeftIcon class="h-5 w-5" />
                    </button>
                    <button @click="forceDelete('file', file.id)" class="rounded-md p-2 hover:bg-gray-100" title="حذف نهائي">
                        <TrashIcon class="h-5 w-5 text-red-500" />
                    </button>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
