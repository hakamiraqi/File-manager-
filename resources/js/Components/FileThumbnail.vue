<script setup>
import { ref, watch } from 'vue';
import FileIcon from '@/Components/FileIcon.vue';

const props = defineProps({
    file: { type: Object, required: true },
    src: { type: String, default: null },
    iconClass: { type: String, default: 'h-12 w-12' },
});

const failed = ref(false);

watch(() => props.file.thumbnail_path, () => (failed.value = false));

const showThumbnail = () => props.file.thumbnail_path && props.src && !failed.value;
</script>

<template>
    <img
        v-if="showThumbnail()"
        :src="src"
        :alt="file.name"
        class="h-full w-full rounded-md object-cover"
        loading="lazy"
        @error="failed = true"
    />
    <FileIcon v-else :extension="file.extension" :mime-type="file.mime_type" :class="iconClass" />
</template>
