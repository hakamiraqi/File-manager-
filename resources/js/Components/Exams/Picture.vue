<script setup>
// A question's or a choice's picture for the student: tap it to see it full size.
import { ref } from 'vue';
import { MagnifyingGlassPlusIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { useTrans } from '@/Composables/useTrans';

const { t } = useTrans();
defineProps({ src: { type: String, required: true }, small: { type: Boolean, default: false } });
const open = ref(false);
</script>

<template>
    <span class="relative inline-block">
        <!-- Beside a choice a tap on the picture picks the choice; the magnifier enlarges it. -->
        <img v-if="small" :src="src" alt="" loading="lazy" draggable="false" class="max-h-28 max-w-[12rem] rounded-md object-contain" />
        <button v-if="small" type="button" @click.stop.prevent="open = true" class="absolute -end-2 -top-2 rounded-full bg-white p-1 text-gray-700 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600" :title="t('exam.picture_zoom', 'اضغط للتكبير')"><MagnifyingGlassPlusIcon class="h-3.5 w-3.5" /></button>
        <button v-else type="button" @click.stop.prevent="open = true" class="block cursor-zoom-in rounded-lg focus-visible:ring-2 focus-visible:ring-[var(--accent-500)]" :title="t('exam.picture_zoom', 'اضغط للتكبير')">
            <img :src="src" alt="" loading="lazy" draggable="false" class="max-h-[45vh] max-w-full rounded-lg object-contain" />
        </button>
        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/80 p-4" @click="open = false" @keydown.esc="open = false" tabindex="-1">
                <img :src="src" alt="" class="max-h-full max-w-full rounded-lg bg-white object-contain" />
                <button type="button" class="absolute end-4 top-4 rounded-full bg-white/90 p-2 text-gray-800" :aria-label="t('common.close', 'إغلاق')"><XMarkIcon class="h-6 w-6" /></button>
            </div>
        </Teleport>
    </span>
</template>
