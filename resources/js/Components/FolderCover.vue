<script setup>
// The band at the top of a folder: its cover picture, or its theme drawn in the theme's colours,
// with the folder's logo and name on it.
import { computed } from 'vue';
import FolderThemeIcon from '@/Components/FolderThemeIcon.vue';
import { themeByKey } from '@/lib/folderThemes';

const props = defineProps({
    look: { type: Object, default: () => ({}) },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    small: { type: Boolean, default: false },
});

const theme = computed(() => themeByKey(props.look?.theme));
const primary = computed(() => props.look?.color || theme.value?.primary || '#b45309');
const secondary = computed(() => theme.value?.secondary || '#fde68a');
const pattern = computed(() => theme.value?.pattern || 'tablet');
const uid = `fc${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <div class="relative overflow-hidden rounded-xl text-white shadow-sm" :class="small ? 'h-24' : 'h-36 sm:h-44'" :style="{ backgroundColor: primary }">
        <img v-if="look?.cover_url" :src="look.cover_url" alt="" class="absolute inset-0 h-full w-full object-cover" />
        <template v-else>
            <div class="absolute inset-0" :style="{ background: `linear-gradient(135deg, ${primary} 0%, ${primary} 45%, color-mix(in srgb, ${primary} 70%, black) 100%)` }" />
            <svg class="absolute inset-0 h-full w-full opacity-25" aria-hidden="true">
                <defs>
                    <pattern :id="uid" width="28" height="28" patternUnits="userSpaceOnUse">
                        <circle v-if="pattern === 'dots'" cx="14" cy="14" r="2.2" :fill="secondary" />
                        <path v-else-if="pattern === 'grid'" d="M28 0H0V28" fill="none" :stroke="secondary" stroke-width="1" />
                        <path v-else-if="pattern === 'cross'" d="M14 9v10M9 14h10" :stroke="secondary" stroke-width="2" stroke-linecap="round" />
                        <path v-else-if="pattern === 'hex'" d="M14 3l9 5v10l-9 5-9-5V8z" fill="none" :stroke="secondary" stroke-width="1" />
                        <path v-else-if="pattern === 'star'" d="M14 6l2.2 5.8L22 14l-5.8 2.2L14 22l-2.2-5.8L6 14l5.8-2.2z" :fill="secondary" />
                        <!-- cuneiform wedges, like the Masmari clay tablet -->
                        <path v-else d="M6 8l5-2v4zM16 8l5-2v4zM6 20l5-2v4zM16 20h6" :fill="secondary" :stroke="secondary" stroke-width="0.8" />
                    </pattern>
                </defs>
                <rect width="100%" height="100%" :fill="`url(#${uid})`" />
            </svg>
            <FolderThemeIcon :icon="theme?.icon || 'tablet'" class="absolute -bottom-6 end-4 opacity-20" :class="small ? 'h-24 w-24' : 'h-40 w-40 sm:h-48 sm:w-48'" :style="{ color: secondary }" />
        </template>
        <div class="absolute inset-0 bg-gradient-to-t from-black/55 via-black/10 to-transparent" />
        <div class="absolute inset-x-0 bottom-0 flex items-end gap-3 p-3 sm:p-4">
            <div v-if="look?.logo_url" class="shrink-0 rounded-xl bg-white p-1 shadow" :class="small ? 'h-12 w-12' : 'h-14 w-14 sm:h-16 sm:w-16'">
                <img :src="look.logo_url" alt="" class="h-full w-full object-contain" />
            </div>
            <div class="min-w-0">
                <p class="truncate font-bold drop-shadow" :class="small ? 'text-base' : 'text-lg sm:text-2xl'"><bdi>{{ title }}</bdi></p>
                <p v-if="subtitle" class="truncate text-xs text-white/85 drop-shadow sm:text-sm">{{ subtitle }}</p>
            </div>
            <slot />
        </div>
    </div>
</template>
