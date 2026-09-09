<script setup>
import { computed } from 'vue';
import {
    PhotoIcon,
    FilmIcon,
    MusicalNoteIcon,
    DocumentTextIcon,
    ArchiveBoxIcon,
    TableCellsIcon,
    DocumentIcon,
    PresentationChartBarIcon,
} from '@heroicons/vue/24/solid';

const props = defineProps({
    extension: { type: String, default: '' },
    mimeType: { type: String, default: '' },
});

const groups = {
    image: { exts: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'], icon: PhotoIcon, color: 'text-emerald-500' },
    video: { exts: ['mp4', 'mov', 'avi', 'mkv', 'webm'], icon: FilmIcon, color: 'text-rose-500' },
    audio: { exts: ['mp3', 'wav', 'ogg', 'flac'], icon: MusicalNoteIcon, color: 'text-purple-500' },
    archive: { exts: ['zip', 'rar', '7z', 'tar', 'gz'], icon: ArchiveBoxIcon, color: 'text-amber-500' },
    sheet: { exts: ['xls', 'xlsx', 'csv'], icon: TableCellsIcon, color: 'text-green-600' },
    slides: { exts: ['ppt', 'pptx'], icon: PresentationChartBarIcon, color: 'text-orange-500' },
    text: { exts: ['txt', 'md', 'json', 'log'], icon: DocumentTextIcon, color: 'text-sky-500' },
    pdf: { exts: ['pdf'], icon: DocumentTextIcon, color: 'text-red-600' },
    doc: { exts: ['doc', 'docx'], icon: DocumentTextIcon, color: 'text-blue-600' },
};

const match = computed(() => {
    const ext = props.extension?.toLowerCase();
    return Object.values(groups).find((g) => g.exts.includes(ext));
});

const icon = computed(() => match.value?.icon ?? DocumentIcon);
const color = computed(() => match.value?.color ?? 'text-gray-400');
</script>

<template>
    <component :is="icon" :class="color" />
</template>
