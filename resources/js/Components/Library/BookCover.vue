<script setup>
import { computed } from 'vue';
import { BookOpenIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ book: Object, size: { type: String, default: 'md' } });
// A soft colour picked from the title, so books without a cover still look different.
const hue = computed(() => [...(props.book?.title ?? '')].reduce((a, c) => (a + c.charCodeAt(0)) % 360, 0));
</script>

<template>
    <div class="relative overflow-hidden rounded-md bg-gray-100 shadow-sm dark:bg-gray-700" style="aspect-ratio: 2 / 3">
        <img v-if="book?.cover_url" :src="book.cover_url" :alt="book.title" loading="lazy" class="h-full w-full object-cover" />
        <div
            v-else
            class="flex h-full w-full flex-col items-center justify-center gap-2 p-2 text-center"
            :style="{ background: `linear-gradient(160deg, hsl(${hue} 45% 92%), hsl(${hue} 40% 80%))` }"
        >
            <BookOpenIcon :class="size === 'sm' ? 'h-5 w-5' : 'h-8 w-8'" class="text-gray-500" />
            <span v-if="size !== 'sm'" class="line-clamp-3 text-xs font-semibold text-gray-700" dir="auto">{{ book?.title }}</span>
        </div>
    </div>
</template>
