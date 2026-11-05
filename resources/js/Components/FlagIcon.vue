<script setup>
// A language's icon: a country flag, or a plain icon when the flag is "icon:<name>".
import { computed } from 'vue';
import { LanguageIcon } from '@heroicons/vue/24/outline';
import { FLAG_URLS, LANGUAGE_ICONS } from '@/flags';

const props = defineProps({
    flag: { type: String, default: null },
    size: { type: String, default: 'h-5 w-7' },
});

const icon = computed(() => (props.flag?.startsWith('icon:') ? LANGUAGE_ICONS[props.flag.slice(5)] ?? LanguageIcon : LanguageIcon));
const url = computed(() => (props.flag && !props.flag.startsWith('icon:') ? FLAG_URLS[props.flag] : null));
</script>

<template>
    <img v-if="url" :src="url" alt="" :class="size" class="inline-block shrink-0 rounded-[3px] object-cover shadow-sm ring-1 ring-black/10" loading="lazy" />
    <span v-else :class="size" class="inline-flex shrink-0 items-center justify-center rounded-[3px] bg-indigo-50 text-[var(--accent-600)] dark:bg-indigo-900/30">
        <component :is="icon" class="h-4 w-4" />
    </span>
</template>
