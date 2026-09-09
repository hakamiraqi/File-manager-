<script setup>
import Modal from '@/Components/Modal.vue';
import { XMarkIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    show: Boolean,
    file: { type: Object, default: null },
});
defineEmits(['close']);

function kind(file) {
    if (!file) return null;
    if (file.mime_type?.startsWith('image/')) return 'image';
    if (file.mime_type?.startsWith('video/')) return 'video';
    if (file.mime_type === 'application/pdf') return 'pdf';
    if (['text/plain', 'text/csv', 'application/json'].includes(file.mime_type)) return 'text';
    return 'unsupported';
}
</script>

<template>
    <Modal :show="show" @close="$emit('close')" max-width="2xl">
        <div v-if="file" class="flex max-h-[85vh] flex-col">
            <div class="flex items-center justify-between border-b border-gray-200 p-4">
                <h3 class="truncate font-medium text-gray-900">{{ file.name }}</h3>
                <div class="flex items-center gap-2">
                    <a :href="route('files.download', file.id)" class="rounded-md p-2 hover:bg-gray-100">
                        <ArrowDownTrayIcon class="h-5 w-5" />
                    </a>
                    <button @click="$emit('close')" class="rounded-md p-2 hover:bg-gray-100">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>
            </div>
            <div class="flex flex-1 items-center justify-center overflow-auto bg-gray-50 p-4">
                <img v-if="kind(file) === 'image'" :src="route('files.preview', file.id)" class="max-h-[70vh] rounded" />
                <video v-else-if="kind(file) === 'video'" :src="route('files.preview', file.id)" controls class="max-h-[70vh] rounded" />
                <iframe v-else-if="kind(file) === 'pdf'" :src="route('files.preview', file.id)" class="h-[70vh] w-full rounded bg-white" />
                <iframe v-else-if="kind(file) === 'text'" :src="route('files.preview', file.id)" class="h-[70vh] w-full rounded bg-white" />
                <p v-else class="text-sm text-gray-500">لا تتوفر معاينة لهذا النوع من الملفات. الرجاء تنزيله للاطلاع عليه.</p>
            </div>
        </div>
    </Modal>
</template>
